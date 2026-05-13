<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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

        try {
            $distinctId = $this->resolveDistinctId();
            $fingerprint = $this->buildFingerprint($e);

            $this->posthog->capture([
                'distinctId' => $distinctId,
                'event' => '$exception',
                'properties' => [
                    // PostHog error tracking expects $exception_* properties
                    '$exception_type' => get_class($e),
                    '$exception_message' => $e->getMessage(),
                    '$exception_source' => 'php',
                    '$exception_stack_trace' => $this->formatStackTrace($e),
                    '$exception_handled' => false,
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
        } catch (Throwable $posthogError) {
            // PostHog failures must never block error reporting or app response
            Log::channel('daily')->warning('posthog.exception.report_failed', [
                'original_exception' => get_class($e),
                'posthog_error' => $posthogError->getMessage(),
            ]);
        }
    }

    /**
     * Check if this is a 4xx client error that should be skipped.
     */
    private function isClientError(Throwable $e): bool
    {
        // Symfony HTTP exceptions carry status codes (NotFound, Forbidden, etc.)
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
            return $e->getStatusCode() < 500;
        }

        // Laravel's ModelNotFoundException is typically a 404
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return true;
        }

        // Authentication/authorization exceptions
        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return true;
        }

        if ($e instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
            return true;
        }

        // Token mismatch (CSRF) — 419
        if ($e instanceof \Illuminate\Session\TokenMismatchException) {
            return true;
        }

        // Validation exceptions — 422
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return true;
        }

        return false;
    }

    /**
     * Rate-limit: max N reports per minute per exception class.
     *
     * Uses atomic cache increment with a guaranteed TTL. The key is set
     * with an expiry upfront via put(), then incremented atomically.
     * For Redis, INCR on an existing key preserves its TTL, so the
     * window doesn't shift on subsequent hits.
     *
     * With the array or database cache driver, concurrent requests may
     * both pass the limit check — acceptable for analytics.
     */
    private function passesRateLimit(Throwable $e): bool
    {
        $key = self::CACHE_KEY_PREFIX . md5(get_class($e));

        try {
            $hits = cache()->increment($key);

            // First hit: key didn't exist (increment returns 1).
            // Set TTL now so the key always expires. For Redis, INCR on
            // a non-existent key creates it with no TTL — this put() call
            // adds the 60s window. Subsequent increments preserve it.
            if ($hits === 1) {
                cache()->put($key, 1, 60);
            }

            return $hits <= self::RATE_LIMIT_PER_CLASS;
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
            return (string) $user->getAuthIdentifier();
        }

        // Anonymous — use a random UUID stored in the session.
        // Generated once per session, reused across requests for grouping.
        // No PII (IP, UA) is used as input — purely random identifier.
        if (! $anonId = session('posthog_anon_id')) {
            $anonId = (string) \Illuminate\Support\Str::uuid();
            session(['posthog_anon_id' => $anonId]);
        }

        return 'anon:' . substr($anonId, 0, 16);
    }

    /**
     * Build a fingerprint for grouping similar exceptions in PostHog.
     * Strips line numbers so same exception in same file groups together.
     */
    private function buildFingerprint(Throwable $e): string
    {
        return md5(get_class($e) . '|' . $e->getFile() . '|' . $e->getMessage());
    }

    /**
     * Format the stack trace for PostHog display.
     * Truncates to prevent oversized payloads.
     */
    private function formatStackTrace(Throwable $e): string
    {
        $trace = $e->getTraceAsString();

        // Limit to ~4000 chars to keep payload reasonable
        if (strlen($trace) > 4000) {
            $trace = substr($trace, 0, 4000) . "\n... [truncated]";
        }

        // Prepend the exception location (first frame)
        return get_class($e) . " at " . $e->getFile() . ":" . $e->getLine() . "\n" . $trace;
    }
}
