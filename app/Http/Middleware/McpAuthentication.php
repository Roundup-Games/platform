<?php

namespace App\Http\Middleware;

use App\Enums\McpAbility;
use App\Mcp\Servers\RoundupServer;
use App\Mcp\Support\McpAuditTarget;
use App\Models\McpClient;
use App\Models\User;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * MCP surface gate (M079/S01, D277 mechanics + T03 hardening).
 *
 * Fail-closed pipeline, in order: auth → rate limit → dispatch policy →
 * dispatch. Every rejection is JSON with a precise status (401/403/400/
 * 429) and an mcp.reject / mcp.throttle structured event on the default
 * channel (D272 precedent); an exception anywhere past auth becomes a 500
 * JSON response, never a stack trace.
 *
 * Auth: Sanctum personal access tokens are the substrate; mcp_clients
 * rows are the consumer identity. Every dispatch must resolve to a live
 * PAT linked to an unrevoked client row owned by the token's user.
 * Abilities are tool-class scopes (mcp:read/write/host/ops) checked with
 * tokenCan() before dispatch; the ops surface is dual-gated with the
 * Platform Admin role, and the host surface with the Game Master role
 * (the GM membership perk, R097/D278 — re-checked per dispatch).
 *
 * Rate limiting (T03): per-consumer buckets keyed on the client uuid
 * with a read/write suffix (tier from rate_limit_tier); the 'mcp' named
 * limiter (AppServiceProvider) serves the Limit pair. 429s carry
 * Retry-After. The limits are a documented contract: /agents.md renders
 * them from config (mcp.rate_limits.tiers.standard), so retuning a tier
 * is doc-synced automatically.
 *
 * Dispatch policy (T03): the tool kill-switch (cache-TTL'd disabled list,
 * no deploy needed) refuses disabled tools with 403; the argument
 * discipline refuses undeclared arguments with 400 naming the accepted
 * ones (agents guess). Both apply before the server sees the message.
 *
 * Contract note: the snake_case reasons this gate emits are a public
 * wire contract — /agents.md lists them for agents to branch on. A new
 * reason must be added to the doc's hand-maintained "The wire" list in
 * the same change (resources/views/markdown/agents.blade.php).
 *
 * After dispatch, every tools/call message leaves an mcp_calls audit row
 * (client, user, tool, sha256 of the arguments, latency) — including
 * calls whose dispatch threw. Arguments are stored ONLY as a hash;
 * rejection logs carry argument names, never values. An audit write
 * failure is logged and never breaks the agent response.
 */
class McpAuthentication
{
    /** Request attribute the 'mcp' named limiter reads: the resolved consumer. */
    public const string ATTRIBUTE_CLIENT = 'mcpClient';

    /** Request attribute the 'mcp' named limiter reads: the request's bucket suffix. */
    public const string ATTRIBUTE_BUCKET = 'mcpRateBucket';

    /** The named rate limiter this gate applies (registered in AppServiceProvider). */
    public const string RATE_LIMITER = 'mcp';

    public const string BUCKET_READ = 'read';

    public const string BUCKET_WRITE = 'write';

    public function handle(Request $request, Closure $next): Response
    {
        // Ops audit-target linkage (M079/S04): start every dispatch from a
        // clean target so a long-lived worker can never carry a stale one
        // from a previous request into this row.
        app(McpAuditTarget::class)->clear();

        $user = $this->resolveUser($request);

        if ($user === null) {
            return $this->unauthenticated($request);
        }

        $token = $user->currentAccessToken();

        // The vendor trait types this as PersonalAccessToken, but Sanctum's
        // guard attaches a TransientToken for session-cookie auth — a
        // runtime possibility the PHPDoc cannot see. Never accept one.
        if (! $token instanceof PersonalAccessToken) { // @phpstan-ignore instanceof.alwaysTrue
            // Session-cookie auth resolves to a TransientToken: the MCP
            // surface deliberately accepts only purpose-issued PATs.
            return $this->unauthenticated($request);
        }

        $client = McpClient::query()
            ->whereBelongsTo($token)
            ->active()
            ->first();

        if ($client === null || $client->user_id !== $user->id) {
            // No consumer row for this token (never issued through the MCP
            // surface) or the consumer is revoked — fail closed.
            return $this->unauthenticated($request);
        }

        if (! $this->hasAnyMcpAbility($user)) {
            return $this->forbidden('This token has no MCP abilities.', 'no_mcp_abilities', $client);
        }

        $requiredAbility = $this->requiredAbilityFor($request);
        $toolName = $this->dispatchedToolName($request);

        if ($requiredAbility !== null && ! $user->tokenCan($requiredAbility->value)) {
            return $this->forbidden(sprintf(
                'This token does not have the %s ability required by %s.',
                $requiredAbility->value,
                $toolName ?? 'this request',
            ), 'ability_denied', $client, $toolName, ['ability' => $requiredAbility->value]);
        }

        // Dual-gate for the ops surface (D277): the token needs mcp:ops AND
        // its owner must still be a Platform Admin — demoting an admin
        // kills their ops consumers' dispatch path.
        if ($requiredAbility === McpAbility::Ops && ! $user->hasRole('Platform Admin')) {
            return $this->forbidden('The ops surface requires an active Platform Admin.', 'ops_requires_admin', $client, $toolName);
        }

        // Dual-gate for the host surface (R097/D278): the GM membership
        // perk. The token needs mcp:host AND its owner must still hold the
        // Game Master role — the runtime marker of active GM status whose
        // lifecycle is coupled to GM subscription lapse, so a token issued
        // while subscribed dies with the role at lapse. Platform Admins
        // pass (the GamePolicy::before superuser convention).
        if ($requiredAbility === McpAbility::Host
            && ! $user->isGM()
            && ! $user->hasRole('Platform Admin')
        ) {
            return $this->forbidden('The host surface requires an active Game Master membership.', 'host_requires_gm', $client, $toolName);
        }

        // Everything past auth is fail-closed: throttling, the dispatch
        // policy, and the dispatch itself never surface a stack trace.
        $startedAt = microtime(true);
        $dispatched = false;

        try {
            $throttled = $this->applyRateLimit($request, $client, self::bucketFor($requiredAbility), $toolName);

            if ($throttled !== null) {
                return $throttled;
            }

            if ($this->isDispatchedToolCall($request)) {
                $refused = $this->enforceDispatchPolicy($request, $client, $toolName);

                if ($refused !== null) {
                    return $refused;
                }
            }

            $dispatched = true;

            $response = $next($request);

            // The Laravel pipeline renders uncaught downstream exceptions
            // into a response ($next does not throw for route-closure
            // failures in Laravel 11+), so a 5xx body here may be a
            // rendered debug page. Stack traces are never acceptable on
            // this surface: every 5xx becomes the sanitized gate JSON.
            if ($response->getStatusCode() >= 500) {
                $this->logRejection('dispatch_exception', $client, $toolName, [
                    'error' => $response instanceof \Illuminate\Http\Response && $response->exception !== null
                        ? $response->exception::class
                        : 'unknown',
                ]);

                $response = response()->json([
                    'error' => [
                        // Coded reason (M079 review 4): tool-level denials carry
                        // snake_case codes; the gate layer matches that
                        // discipline so agents can branch on error.reason at
                        // every layer instead of parsing prose. Additive —
                        // message text is unchanged.
                        'reason' => 'dispatch_exception',
                        'message' => 'The MCP server encountered an error.',
                    ],
                ], $response->getStatusCode());
            }
        } catch (Throwable $e) {
            report($e);

            $this->logRejection('dispatch_exception', $client, $toolName, ['error' => $e::class]);

            $response = response()->json([
                'error' => [
                    'reason' => 'dispatch_exception',
                    'message' => 'The MCP server encountered an error.',
                ],
            ], 500);
        } finally {
            // Only messages that reached the dispatch stage are audited:
            // rejected-before-dispatch calls (auth, throttle, kill-switch,
            // argument discipline) leave an mcp.reject / mcp.throttle
            // structured event instead — never an audit row. A dispatch
            // that THREW is audited (it reached the server).
            if ($dispatched && $this->isDispatchedToolCall($request)) {
                $this->auditDispatchedCall($request, $client, $user, $startedAt);
            }
        }

        return $response;
    }

    // ── Rate limiting (T03) ────────────────────────────────

    /**
     * Consume one attempt from the consumer's bucket and return the 429
     * response when it is exhausted. Mirrors the framework throttle
     * middleware's mechanics (tooManyAttempts → availableIn → hit) but
     * answers with our JSON shape, Retry-After, and the mcp.throttle
     * structured event instead of an HTML error page.
     */
    private function applyRateLimit(Request $request, McpClient $client, string $bucket, ?string $toolName): ?Response
    {
        $request->attributes->set(self::ATTRIBUTE_CLIENT, $client);
        $request->attributes->set(self::ATTRIBUTE_BUCKET, $bucket);

        $limit = $this->resolveLimit($request, $bucket);

        $key = $limit->key;

        if (! is_string($key) || $key === '') {
            throw new RuntimeException(sprintf('Rate limiter [%s] served a malformed key.', self::RATE_LIMITER));
        }

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            // Info level, not debug: this is a documented abuse signal
            // (the /admin/mcp-usage rejection-observability section and
            // docs/mcp-tools.md point ops here), and production runs at
            // LOG_LEVEL=info — a debug event would never ship. Per-call
            // transport telemetry (mcp.call/mcp.connect) stays debug: its
            // durable record is the mcp_calls audit row, not the log.
            Log::info('mcp.throttle', [
                'client_id' => $client->id,
                'bucket' => $bucket,
                'tool' => $toolName,
                'retry_after' => $retryAfter,
                'limit' => $limit->maxAttempts,
            ]);

            return response()->json([
                'error' => [
                    'reason' => 'rate_limited',
                    'message' => 'Too many MCP requests. Retry later.',
                ],
            ], 429)->withHeaders([
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $limit->maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, $limit->decaySeconds);

        return null;
    }

    /**
     * Resolve the bucket's Limit through the named 'mcp' limiter. A missing
     * limiter or bucket is a policy wiring bug — it fails loudly (and, via
     * the dispatch guard, as 500 JSON) rather than silently not throttling.
     */
    private function resolveLimit(Request $request, string $bucket): Limit
    {
        $limiter = RateLimiter::limiter(self::RATE_LIMITER);

        if ($limiter === null) {
            throw new RuntimeException(sprintf('Rate limiter [%s] is not registered.', self::RATE_LIMITER));
        }

        $limits = $limiter($request);

        if (! is_array($limits) || ! isset($limits[$bucket]) || ! $limits[$bucket] instanceof Limit) {
            throw new RuntimeException(sprintf('Rate limiter [%s] does not serve a [%s] limit.', self::RATE_LIMITER, $bucket));
        }

        return $limits[$bucket];
    }

    /**
     * The per-consumer limit pair the named 'mcp' limiter serves: a
     * generous read bucket and a tighter write bucket, keyed on the client
     * uuid so consumers throttle independently. The tier row comes from
     * config; unknown tiers fail safe to standard.
     *
     * @return array{read: Limit, write: Limit}
     */
    public static function limitsFor(Request $request): array
    {
        $client = $request->attributes->get(self::ATTRIBUTE_CLIENT);
        $bucket = $request->attributes->get(self::ATTRIBUTE_BUCKET);

        if (! $client instanceof McpClient || ! is_string($bucket) || $bucket === '') {
            throw new RuntimeException('The mcp rate limiter requires the client and bucket request attributes.');
        }

        $tier = self::tierFor($client);

        return [
            self::BUCKET_READ => new Limit(
                key: sprintf('mcp:%s:%s', $client->id, self::BUCKET_READ),
                maxAttempts: self::tierLimit($tier, self::BUCKET_READ, $client),
                decaySeconds: self::tierDecay($tier, self::BUCKET_READ, $client),
            ),
            self::BUCKET_WRITE => new Limit(
                key: sprintf('mcp:%s:%s', $client->id, self::BUCKET_WRITE),
                maxAttempts: self::tierLimit($tier, self::BUCKET_WRITE, $client),
                decaySeconds: self::tierDecay($tier, self::BUCKET_WRITE, $client),
            ),
        ];
    }

    /**
     * The bucket a request's required tool-class ability selects: read
     * tools share the generous read bucket; write/host/ops tools draw from
     * the tighter write bucket.
     */
    public static function bucketFor(?McpAbility $requiredAbility): string
    {
        return $requiredAbility === McpAbility::Write
            || $requiredAbility === McpAbility::Host
            || $requiredAbility === McpAbility::Ops
            ? self::BUCKET_WRITE
            : self::BUCKET_READ;
    }

    private static function tierFor(McpClient $client): string
    {
        $tier = $client->rate_limit_tier;

        if ($tier !== '' && config("mcp.rate_limits.tiers.{$tier}") !== null) {
            return $tier;
        }

        return McpClient::RATE_LIMIT_TIER_STANDARD;
    }

    private static function tierLimit(string $tier, string $bucket, McpClient $client): int
    {
        $limit = config("mcp.rate_limits.tiers.{$tier}.{$bucket}.limit");

        if (! is_numeric($limit) || (int) $limit < 1) {
            throw new RuntimeException(sprintf('No usable [%s] rate limit configured for tier [%s].', $bucket, $client->rate_limit_tier));
        }

        return (int) $limit;
    }

    private static function tierDecay(string $tier, string $bucket, McpClient $client): int
    {
        $decay = config("mcp.rate_limits.tiers.{$tier}.{$bucket}.decay_seconds");

        if (! is_numeric($decay) || (int) $decay < 1) {
            throw new RuntimeException(sprintf('No usable [%s] rate limit decay configured for tier [%s].', $bucket, $client->rate_limit_tier));
        }

        return (int) $decay;
    }

    // ── Dispatch policy (T03): kill-switch + argument discipline ──

    /**
     * Refuse the call when its target tool is on the disabled list. The
     * list is a cache entry ops can write at runtime — revoking a tool
     * must not require a release.
     */
    private function enforceDispatchPolicy(Request $request, McpClient $client, ?string $toolName): ?Response
    {
        if ($toolName !== null && in_array($toolName, self::disabledTools(), true)) {
            $this->logRejection('tool_disabled', $client, $toolName);

            return response()->json([
                'error' => [
                    'reason' => 'tool_disabled',
                    'message' => sprintf('The %s tool has been disabled by the platform.', $toolName),
                ],
            ], 403);
        }

        return $this->enforceArgumentDiscipline($client, $toolName, $request->input('params.arguments'));
    }

    /**
     * Refuse a tools/call carrying arguments the target tool does not
     * declare (agents guess): the refusal names the unexpected arguments
     * and the accepted ones, so the agent can correct the call. A
     * non-empty positional list is the same failure — arguments are an
     * object on this wire.
     */
    private function enforceArgumentDiscipline(McpClient $client, ?string $toolName, mixed $arguments): ?Response
    {
        if ($toolName === null || $arguments === null || $arguments === []) {
            return null;
        }

        $declared = RoundupServer::declaredArgumentsFor($toolName);

        // Unknown tools are left to the server's own tool-not-found
        // response — the discipline speaks only for registered tools.
        if ($declared === null) {
            return null;
        }

        $malformed = ! is_array($arguments) || array_is_list($arguments);

        $unexpected = $malformed
            ? []
            : array_values(array_diff(array_keys($arguments), $declared));

        if (! $malformed && $unexpected === []) {
            return null;
        }

        $this->logRejection('undeclared_arguments', $client, $toolName, [
            // Argument NAMES only — values are never written to logs.
            'unexpected' => $unexpected === [] ? null : $unexpected,
        ]);

        return response()->json([
            'error' => [
                'reason' => 'undeclared_arguments',
                'message' => sprintf(
                    'Tool %s does not accept %s. This tool accepts: %s.',
                    $toolName,
                    $malformed ? 'positional arguments' : implode(', ', $unexpected),
                    $declared === [] ? 'no arguments' : implode(', ', $declared),
                ),
                'accepted_arguments' => $declared,
                'unexpected_arguments' => $unexpected,
            ],
        ], 400);
    }

    // ── Tool kill-switch (T03) ─────────────────────────────

    /**
     * The disabled-tool names currently in force (cache-TTL'd setting, no
     * deploy needed to revoke a tool). Defaults to an empty list.
     *
     * @return list<string>
     */
    public static function disabledTools(): array
    {
        $tools = Cache::get(self::disabledToolsCacheKey(), []);

        return is_array($tools)
            ? array_values(array_filter($tools, is_string(...)))
            : [];
    }

    /**
     * Revokes tools at the dispatch gate without a release. The TTL bounds
     * a forgotten switch (it self-expires) — ops re-arms long revocations.
     *
     * @param  list<string>  $toolNames
     */
    public static function disableTools(array $toolNames, ?int $ttlSeconds = null): void
    {
        Cache::put(
            self::disabledToolsCacheKey(),
            array_values(array_unique(array_filter($toolNames, is_string(...)))),
            now()->addSeconds($ttlSeconds ?? self::disabledToolsTtl()),
        );
    }

    /**
     * Clears the kill-switch (every tool re-armed).
     */
    public static function enableAllTools(): void
    {
        Cache::forget(self::disabledToolsCacheKey());
    }

    private static function disabledToolsCacheKey(): string
    {
        $key = config('mcp.disabled_tools.cache_key', 'mcp:disabled-tools');

        return is_string($key) && $key !== '' ? $key : 'mcp:disabled-tools';
    }

    private static function disabledToolsTtl(): int
    {
        $ttl = config('mcp.disabled_tools.ttl_seconds', 86_400);

        return is_numeric($ttl) && (int) $ttl >= 1 ? (int) $ttl : 86_400;
    }

    // ── Auth helpers ───────────────────────────────────────

    /**
     * Resolve the user through Sanctum's guard (bearer PAT). Returns null
     * for missing credentials, unknown/expired/revoked tokens.
     */
    private function resolveUser(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user instanceof User ? $user : null;
    }

    private function hasAnyMcpAbility(User $user): bool
    {
        foreach (McpAbility::cases() as $ability) {
            if ($user->tokenCan($ability->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tool-class scope the incoming message requires: tools/list is a
     * read surface; tools/call needs whatever the target tool declares in
     * the server registry. Handshake/other methods need no extra scope
     * beyond the base any-mcp-ability gate. Null means "no additional
     * gate" (unknown tools are left to the server's own tool-not-found
     * response).
     */
    private function requiredAbilityFor(Request $request): ?McpAbility
    {
        $method = $request->input('method');

        if ($method === 'tools/list') {
            return McpAbility::Read;
        }

        if ($method === 'tools/call') {
            $toolName = $this->dispatchedToolName($request);

            return $toolName !== null
                ? RoundupServer::requiredAbilityFor($toolName)
                : McpAbility::Read;
        }

        return null;
    }

    private function isDispatchedToolCall(Request $request): bool
    {
        return $request->input('method') === 'tools/call';
    }

    private function dispatchedToolName(Request $request): ?string
    {
        $name = $request->input('params.name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    // ── Audit + rejection surfaces ─────────────────────────

    /**
     * Append the per-dispatch audit row (mcp_calls, D277), stamped
     * actor=agent (M079/S03/T02) — the provenance marker that makes an
     * agent-surface write observably distinguishable from the web path's
     * audit trail — plus the ops target linkage (M079/S04/T01, R090):
     * when an ops tool recorded the entity it operated on (ticket id /
     * user id), the row links it, so the trail answers “which consumer,
     * acting as which admin, touched which record” — the replacement for
     * ad-hoc SSH/artisan ops access. Arguments are stored ONLY as a
     * sha256 hash — the audit trail never holds argument values in the
     * clear (there is no raw path at all). Called in a finally block, so
     * a dispatch that threw is audited too. An audit write failure is
     * logged and never breaks the agent response.
     */
    private function auditDispatchedCall(Request $request, McpClient $client, User $user, float $startedAt): void
    {
        $arguments = $request->input('params.arguments');

        // An empty JSON object decodes to [] in PHP — encode it back as the
        // object the agent actually sent so the hash pins the wire payload.
        $encoded = $arguments === [] ? '{}' : json_encode($arguments ?? new \stdClass);
        $argumentsJson = $encoded === false ? 'null' : $encoded;

        $target = app(McpAuditTarget::class);

        // The wire name is agent-supplied for unregistered tools; bounding
        // it to the column width keeps the audit write guaranteed-writable
        // instead of failing the insert (and losing the row) on a >255-char
        // name. Registered tools are always far under the bound.
        $toolName = $this->dispatchedToolName($request);
        $tool = $toolName !== null && $toolName !== '' ? mb_substr($toolName, 0, 255) : '(unknown)';

        try {
            $client->calls()->create([
                'user_id' => $user->id,
                'tool' => $tool,
                'actor' => 'agent',
                'arguments_hash' => hash('sha256', $argumentsJson),
                'latency_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                'target_type' => $target->type,
                'target_id' => $target->id,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('mcp.audit_write_failed', [
                'client_id' => $client->id,
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * mcp.reject structured event (D272 precedent: default channel, event
     * name + context). Context carries reason/tool/ids and argument NAMES
     * at most — argument values are never logged. Info level so the event
     * ships at the production LOG_LEVEL=info baseline: this is the
     * documented rejection-observability surface (mcp_calls holds no
     * pre-dispatch rows), not per-call telemetry.
     *
     * @param  array<string, mixed>  $extra
     */
    private function logRejection(string $reason, ?McpClient $client = null, ?string $toolName = null, array $extra = []): void
    {
        Log::info('mcp.reject', array_filter([
            'reason' => $reason,
            'client_id' => $client?->id,
            'tool' => $toolName,
            ...$extra,
        ], fn (mixed $value): bool => $value !== null));
    }

    private function unauthenticated(Request $request): Response
    {
        $this->logRejection('unauthenticated', null, $this->dispatchedToolName($request));

        return response()->json([
            'error' => [
                'reason' => 'unauthenticated',
                'message' => 'Unauthenticated.',
            ],
        ], 401);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function forbidden(string $message, string $reason, McpClient $client, ?string $toolName = null, array $extra = []): Response
    {
        $this->logRejection($reason, $client, $toolName, $extra);

        return response()->json([
            'error' => [
                // The same snake_case reason that reaches the mcp.reject log
                // event — ability_denied / no_mcp_abilities /
                // ops_requires_admin / host_requires_gm.
                'reason' => $reason,
                'message' => $message,
            ],
        ], 403);
    }
}
