<?php

namespace App\Http\Controllers;

use App\Enums\McpAbility;
use App\Mcp\Servers\RoundupServer;
use App\Models\McpClient;
use Illuminate\Http\Response;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Server\Attributes\Description;
use ReflectionClass;

/**
 * GET /agents.md — the public agent self-onboarding document.
 *
 * Written second-person TO an AI personal assistant: how to connect to the
 * Roundup Games MCP server and act on behalf of its human. EN-only by
 * design (the D274 markdown-alternates precedent: agent-consumption docs
 * are EN-only), so the copy lives in the view and here, not in lang/.
 *
 * Deliberately stateless: no live stats, no cache (the body changes only
 * per deploy), unlike llms.txt. Four parts are GENERATED, never
 * hand-copied, so they cannot drift from the code they describe:
 *
 *  - the tool catalog, built from RoundupServer::TOOL_REGISTRY with each
 *    tool's enforced scope (requiredAbilityFor) and its purpose (the
 *    first sentence of the tool class's Description attribute — the same
 *    text tools/list advertises);
 *  - the supported protocol versions, from the installed laravel/mcp
 *    package's ProtocolVersion::initializeSupported() — exactly the list
 *    the live server answers an initialize handshake with;
 *  - the rate-limit clauses, from the standard tier of
 *    config('mcp.rate_limits.tiers') — the row McpAuthentication's
 *    limiter actually enforces (and the fail-safe for unknown tiers);
 *  - the idempotency replay window, from config('mcp.idempotency.
 *    ttl_seconds') — the TTL HandlesIdempotency stores answers for.
 *
 * The gate-reason list ("The wire") and the denial-code list
 * ("Denials are coded") remain hand-maintained prose: when
 * McpAuthentication gains a reason or a tool gains a code, the lists
 * must be updated with it — the "include" hedges scope them as
 * non-exhaustive, but not as optional.
 *
 * AgentsMarkdownTest pins the generated parts against the registry, the
 * vendor constant, and the config values (the drift guards for this
 * document).
 */
class AgentsMarkdownController extends Controller
{
    /**
     * Group copy per tool-class scope: heading + one framing line, shown
     * with the scope itself. Keys are McpAbility values; groups render in
     * McpAbility::cases() order (read → write → host → ops).
     *
     * @var array<string, array{heading: string, note: string}>
     */
    private const GROUPS = [
        McpAbility::Read->value => [
            'heading' => 'Participant reads',
            'note' => 'Discovery and your owner\'s own sessions — the surface every credential starts with.',
        ],
        McpAbility::Write->value => [
            'heading' => 'Participant writes',
            'note' => 'Joining, applying, and leaving on your owner\'s behalf — write tools, each accepting an `idempotency_key`.',
        ],
        McpAbility::Host->value => [
            'heading' => 'Host tools',
            'note' => 'The host workspace — requires `mcp:host` plus your owner\'s active Game Master membership.',
        ],
        McpAbility::Ops->value => [
            'heading' => 'Platform ops',
            'note' => 'Admin-issued only: a credential reading this document almost certainly does not carry `mcp:ops` — it is granted to Platform Admin staff. Listed for completeness.',
        ],
    ];

    public function __invoke(): Response
    {
        $appUrl = config('app.url');

        return response()
            ->view('markdown.agents', [
                'serverUrl' => rtrim(is_string($appUrl) && $appUrl !== '' ? $appUrl : 'https://roundup.games', '/').'/mcp',
                'protocolVersions' => '`'.implode('`, `', self::supportedProtocolVersions()).'`',
                'toolGroups' => self::catalog(),
                'rateLimits' => self::rateLimitClauses(),
                'idempotencyWindow' => self::idempotencyWindow(),
            ])
            ->withHeaders([
                'Content-Type' => 'text/markdown; charset=utf-8',
            ]);
    }

    /**
     * The protocol versions this deployment's initialize handshake
     * supports, derived from the installed laravel/mcp package's
     * ProtocolVersion::initializeSupported() — the exact list the live
     * server answers with, so the document can never advertise a version
     * the deployment rejects (or omit one it accepts).
     *
     * @return list<string>
     */
    public static function supportedProtocolVersions(): array
    {
        return ProtocolVersion::initializeSupported();
    }

    /**
     * The rate-limit clauses the operating rules state, derived from the
     * standard tier of config('mcp.rate_limits.tiers') — the row the
     * McpAuthentication limiter enforces and the fail-safe for unknown
     * client tiers, so the numbers an agent paces itself against are the
     * numbers the server actually throttles by.
     *
     * @return array{read: string, write: string}
     */
    public static function rateLimitClauses(): array
    {
        $tier = config('mcp.rate_limits.tiers.'.McpClient::RATE_LIMIT_TIER_STANDARD);
        $rows = is_array($tier) ? $tier : [];

        $read = is_array($rows['read'] ?? null) ? $rows['read'] : [];
        $write = is_array($rows['write'] ?? null) ? $rows['write'] : [];

        return [
            'read' => self::perWindow(
                self::intFromConfig($read['limit'] ?? 60, 60),
                self::intFromConfig($read['decay_seconds'] ?? 60, 60),
            ),
            'write' => self::perWindow(
                self::intFromConfig($write['limit'] ?? 10, 10),
                self::intFromConfig($write['decay_seconds'] ?? 60, 60),
            ),
        ];
    }

    /**
     * The idempotency replay window the operating rules state, derived
     * from config('mcp.idempotency.ttl_seconds') — the TTL
     * HandlesIdempotency stores answers for, so a retried call with the
     * same key replays exactly as long as the document promises.
     */
    public static function idempotencyWindow(): string
    {
        return self::humanizeDuration(
            self::intFromConfig(config('mcp.idempotency.ttl_seconds', 86_400), 86_400)
        );
    }

    /**
     * Read an int from a config value, narrowing before the cast: the
     * committed shape is ints, but env-typed strings must resolve too,
     * and a non-numeric value falls back to the default rather than
     * rendering as 0.
     */
    private static function intFromConfig(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Humanize a limit/window pair for the operating-rules prose: the
     * common windows get their English word, anything else stays an
     * honest "N-second window".
     */
    private static function perWindow(int $limit, int $decaySeconds): string
    {
        return $limit.' per '.match ($decaySeconds) {
            1 => 'second',
            60 => 'minute',
            3_600 => 'hour',
            default => $decaySeconds.'-second window',
        };
    }

    /**
     * Humanize a seconds TTL for the operating-rules prose: hours first,
     * then minutes, then raw seconds — pluralized.
     */
    private static function humanizeDuration(int $seconds): string
    {
        if ($seconds >= 3_600 && $seconds % 3_600 === 0) {
            $hours = intdiv($seconds, 3_600);

            return $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }

        if ($seconds >= 60 && $seconds % 60 === 0) {
            $minutes = intdiv($seconds, 60);

            return $minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
        }

        return $seconds.' '.($seconds === 1 ? 'second' : 'seconds');
    }

    /**
     * The generated tool catalog: one group per tool-class scope in
     * McpAbility order, tools in registry order. Grouped rows keep the
     * registry's shape visible while the per-row scope keeps the catalog
     * machine-parseable (AgentsMarkdownTest pins both directions).
     *
     * @return list<array{scope: string, heading: string, note: string, tools: list<array{name: string, scope: string, purpose: string}>}>
     */
    public static function catalog(): array
    {
        /** @var array<string, list<array{name: string, scope: string, purpose: string}>> $byAbility */
        $byAbility = [];

        foreach (RoundupServer::TOOL_REGISTRY as $name => $toolClass) {
            $ability = RoundupServer::requiredAbilityFor($name);

            // ClientAuthTest pins that every registry tool declares an
            // ABILITY; a registry entry without one would silently vanish
            // from the public catalog, so it is skipped defensively here
            // and the parity test fails on the missing row.
            if ($ability === null) {
                continue;
            }

            $byAbility[$ability->value][] = [
                'name' => $name,
                'scope' => $ability->value,
                'purpose' => self::firstSentence(self::toolDescription($toolClass)),
            ];
        }

        $groups = [];

        foreach (McpAbility::cases() as $ability) {
            $tools = $byAbility[$ability->value] ?? [];

            if ($tools === []) {
                continue;
            }

            $groups[] = [
                'scope' => $ability->value,
                'heading' => self::GROUPS[$ability->value]['heading'],
                'note' => self::GROUPS[$ability->value]['note'],
                'tools' => $tools,
            ];
        }

        return $groups;
    }

    /**
     * The tool class's Description attribute text — the same purpose text
     * tools/list advertises. Empty when absent; the parity test then fails
     * on the blank purpose rather than shipping it.
     *
     * @param  class-string  $toolClass
     */
    private static function toolDescription(string $toolClass): string
    {
        $attributes = (new ReflectionClass($toolClass))->getAttributes(Description::class);

        if ($attributes === []) {
            return '';
        }

        return $attributes[0]->newInstance()->value;
    }

    /**
     * The first sentence of a tool's Description — its one-line purpose.
     * Every tool description opens with a complete purpose sentence, so
     * the cut at '. ' is the faithful extraction (a description with no
     * second sentence passes through whole).
     */
    private static function firstSentence(string $description): string
    {
        $cut = strpos($description, '. ');

        return $cut === false ? rtrim($description) : substr($description, 0, $cut + 1);
    }
}
