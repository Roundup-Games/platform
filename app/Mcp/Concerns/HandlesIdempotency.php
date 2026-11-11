<?php

namespace App\Mcp\Concerns;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Content\Text;

/**
 * Write-tool idempotency (M079/S01/T03): agents retry, so a retried write
 * must never double-execute.
 *
 * A tool adopting this concern routes its handle() body through
 * idempotentResponse(). When the call carries an idempotency_key argument,
 * the first successful answer is cached (per principal + tool + hashed key,
 * config TTL) and every retry with the same key replays that stored answer
 * byte-for-byte instead of executing again. Only successful responses are
 * stored — a failed first attempt still retries. Calls without a key (or
 * with a non-string/empty one) execute normally; keys are hashed in the
 * cache key so raw key material is never stored.
 *
 * Same-key executions are serialized through a cache lock so concurrent
 * retries are single-flight: the loser blocks until the winner's answer is
 * stored, then replays it — instead of double-executing and racing for the
 * cache (the services underneath already guard double-writes; the lock
 * keeps the replay contract honest under concurrency too). The lock lives
 * on the same store as the answer (redis/database both serve locks; the
 * array store does in tests).
 *
 * The idempotency_key argument itself must be declared in the tool's
 * schema() (alongside the tool's real arguments) so the transport-level
 * undeclared-argument discipline in McpAuthentication accepts it — agents
 * see the argument through the schema exactly like any other.
 */
trait HandlesIdempotency
{
    /** How long a same-key caller waits for a concurrent execution to finish. */
    protected const int IDEMPOTENCY_LOCK_SECONDS = 15;

    /**
     * The reserved argument name write tools accept for safe retries.
     * Single-sourced here; consuming tools declare it in their schema().
     */
    public const string IDEMPOTENCY_KEY_ARGUMENT = 'idempotency_key';

    /**
     * Execute a write-tool action idempotently: replay the stored answer
     * for a repeated idempotency_key, otherwise execute and store the
     * first successful response.
     *
     * @param  Closure(): (Response|array<int, Response>)  $execute
     * @return Response|array<int, Response>
     */
    protected function idempotentResponse(Request $request, Closure $execute): Response|array
    {
        $key = $request->get(self::IDEMPOTENCY_KEY_ARGUMENT);

        if (! is_string($key) || $key === '') {
            return $execute();
        }

        $cacheKey = $this->idempotencyCacheKey($request, $key);

        // Single-flight: one execution per key at a time. A concurrent
        // same-key call waits for the winner, then replays the stored
        // answer; on a lock timeout the call executes unlocked (the worst
        // case regresses to the previous check-then-execute behavior,
        // which the services underneath already make safe).
        $lock = Cache::lock($cacheKey.':lock', self::IDEMPOTENCY_LOCK_SECONDS);

        try {
            $lock->block(self::IDEMPOTENCY_LOCK_SECONDS);
        } catch (LockTimeoutException) {
            // Fall through and execute without the lock.
            $lock = null;
        }

        try {
            $stored = Cache::get($cacheKey);

            if (is_string($stored) && $stored !== '') {
                return Response::text($stored);
            }

            $response = $execute();

            // Only a single successful Text Response is replayable byte-for-
            // byte: streamed multi-responses, error results, and structured-
            // content payloads must not become a permanent answer, so they
            // execute fresh on every retry.
            if ($response instanceof Response && ! $response->isError() && $response->content() instanceof Text) {
                Cache::put($cacheKey, (string) $response->content(), now()->addSeconds($this->idempotencyTtl()));
            }

            return $response;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Replay scope: one principal (the authenticated user), one tool, one
     * key. Scoping by user rather than token id means a consumer re-issue
     * keeps its idempotency history — retries after a credential rotation
     * still replay instead of double-executing.
     */
    protected function idempotencyCacheKey(Request $request, string $key): string
    {
        $principal = $request->user('sanctum') ?? $request->user();

        // getAuthIdentifier() is mixed on the contract; a scalar (int|string
        // id from any driver) stringifies cleanly and anything else can only
        // be a wiring bug — 'direct' is the same fall-through as no principal.
        $rawIdentifier = $principal instanceof Authenticatable
            ? $principal->getAuthIdentifier()
            : null;

        $identifier = is_scalar($rawIdentifier) ? (string) $rawIdentifier : 'direct';

        $prefix = config('mcp.idempotency.cache_prefix', 'mcp:idempotency');

        return sprintf(
            '%s:%s:%s:%s',
            is_string($prefix) && $prefix !== '' ? $prefix : 'mcp:idempotency',
            $identifier,
            $this->name(),
            hash('sha256', $key),
        );
    }

    protected function idempotencyTtl(): int
    {
        $ttl = config('mcp.idempotency.ttl_seconds', 86_400);

        return is_numeric($ttl) ? max(1, (int) $ttl) : 86_400;
    }

    /**
     * Provided by the host tool (Laravel\Mcp\Server\Primitive): the wire
     * name that scopes the idempotency cache key.
     */
    abstract public function name(): string;
}
