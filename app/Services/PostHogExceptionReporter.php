<?php

namespace App\Services;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PostHog\ExceptionPayloadBuilder;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Captures PHP exceptions as PostHog '$exception' events for error tracking.
 *
 * Only reports 5xx and unhandled exceptions (skips 4xx HTTP exceptions).
 * Rate-limited to 10 reports/minute per exception type to prevent flooding.
 * All PostHog calls are wrapped in try/catch so analytics failures never
 * block error reporting or app responses.
 */
class PostHogExceptionReporter
{
    /**
     * Maximum exception reports per minute per exception class.
     */
    private const RATE_LIMIT_PER_CLASS = 10;

    /**
     * Rate limit cache key prefix.
     */
    private const CACHE_KEY_PREFIX = 'posthog:exception_throttle:';

    public function __construct(
        private readonly PostHogClient $posthog,
    ) {}

    /**
     * Report an exception to PostHog error tracking.
     *
     * Skips: disabled PostHog, 4xx HTTP exceptions, rate-limited exceptions.
     * Error handling is centralized in PostHogClient::capture().
     * If the SDK throws, PostHogClient catches it and logs a warning.
     */
    public function report(Throwable $e): void
    {
        if (! $this->posthog->isEnabled()) {
            return;
        }

        // Skip 4xx HTTP exceptions — not actionable errors
        if ($this->isClientError($e)) {
            return;
        }

        // Rate-limit per exception class to prevent flooding on cascading failures
        if (! $this->passesRateLimit($e)) {
            return;
        }

        $distinctId = $this->resolveDistinctId();
        $fingerprint = $this->buildFingerprint($e);

        // Build the structured exception list the PostHog ingestion endpoint requires.
        // PostHog expects $exception_list as an array of objects with type/value/mechanism/stacktrace.
        $exceptionList = ExceptionPayloadBuilder::buildExceptionList($e);
        $exceptionList = ExceptionPayloadBuilder::overridePrimaryMechanism($exceptionList, [
            'type' => 'manual',
            'handled' => false,
        ]);

        $this->posthog->capture([
            'distinctId' => $distinctId,
            'event' => '$exception',
            'properties' => [
                // Structured exception list — required by PostHog ingestion
                '$exception_list' => $exceptionList,
                '$exception_handled' => ExceptionPayloadBuilder::getPrimaryHandled($exceptionList),
                '$exception_source' => 'php',
                '$exception_fingerprint' => $fingerprint,
                // Request context
                'request_url' => request()->fullUrl(),
                'request_method' => request()->method(),
                'request_path' => request()->path(),
                // Code location
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_code' => $e->getCode(),
                // Environment
                'environment' => app()->environment(),
            ],
        ]);

        Log::channel('daily')->debug('posthog.exception.reported', [
            'exception_class' => get_class($e),
            'fingerprint' => $fingerprint,
        ]);
    }

    /**
     * Check if this is a 4xx client error that should be skipped.
     */
    private function isClientError(Throwable $e): bool
    {
        // Symfony HTTP exceptions carry status codes (NotFound, Forbidden, etc.)
        if ($e instanceof HttpException) {
            return $e->getStatusCode() < 500;
        }

        // Laravel's ModelNotFoundException is typically a 404
        if ($e instanceof ModelNotFoundException) {
            return true;
        }

        // Authentication/authorization exceptions
        if ($e instanceof AuthenticationException) {
            return true;
        }

        // Token mismatch (CSRF) — 419
        if ($e instanceof TokenMismatchException) {
            return true;
        }

        // Validation exceptions — 422
        if ($e instanceof ValidationException) {
            return true;
        }

        return false;
    }

    /**
     * Rate-limit: max N reports per minute per exception class.
     *
     * Uses cache()->add() to atomically create the key with a 60s TTL,
     * then increments on each hit. add() only writes if the key doesn't
     * exist, so concurrent first requests don't race to reset the counter.
     *
     * With the array or database cache driver, concurrent requests may
     * both pass the limit check — acceptable for analytics.
     */
    private function passesRateLimit(Throwable $e): bool
    {
        $key = self::CACHE_KEY_PREFIX.md5(get_class($e));

        try {
            // Ensure key exists with TTL before incrementing.
            // add() is atomic — only sets if key doesn't exist, avoiding
            // the race between concurrent first-request increments.
            cache()->add($key, 0, 60);

            return cache()->increment($key) <= self::RATE_LIMIT_PER_CLASS;
        } catch (Throwable) {
            // If cache fails, allow the report through
            return true;
        }
    }

    /**
     * Resolve the distinct ID for PostHog attribution.
     *
     * Uses authenticated user ID or a random session-scoped anonymous ID.
     * The anonymous ID is stored in the session and persists across requests
     * within the same browser session, enabling session grouping without
     * deriving identifiers from PII (IP address, user agent).
     *
     * GDPR-friendly: no IP address or user agent is processed to create
     * the analytics identifier. The random UUID is opaque and cannot be
     * correlated back to an individual without access to the session store.
     */
    private function resolveDistinctId(): string
    {
        $user = Auth::user();

        if ($user) {
            $authId = $user->getAuthIdentifier();

            return to_string_id($authId);
        }

        // Anonymous — use a random UUID stored in the session.
        // Generated once per session, reused across requests for grouping.
        // No PII (IP, UA) is used as input — purely random identifier.
        if (! $anonId = session('posthog_anon_id')) {
            $anonId = (string) Str::uuid();
            session(['posthog_anon_id' => $anonId]);
        }

        return 'anon:'.substr(is_string($anonId) ? $anonId : '', 0, 16);
    }

    /**
     * Build a fingerprint for grouping similar exceptions in PostHog.
     * Strips line numbers so same exception in same file groups together.
     */
    private function buildFingerprint(Throwable $e): string
    {
        return md5(get_class($e).'|'.$e->getFile().'|'.$e->getMessage());
    }
}
