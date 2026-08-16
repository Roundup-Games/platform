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

    /**
     * Per-worker fallback counters, used when the shared cache is unavailable.
     * Keyed by exception class.
     *
     * @var array<string, array{window: int, count: int}>
     */
    private static array $localThrottle = [];

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
                // Request context — path only. Query strings can carry share
                // tokens, UTM, or PII, so we deliberately exclude them from
                // error tracking (legitimate-interest) payloads. Path segments
                // that ARE secret (short-link codes, password-reset tokens,
                // email-verification hashes, invite-opt-out email hashes) are
                // scrubbed to a placeholder so bearer tokens never reach the
                // error-tracking provider.
                'request_path' => $this->scrubRequestPath(request()->path()),
                'request_method' => request()->method(),
                'request_is_https' => request()->secure(),
                // Code location
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_code' => $e->getCode(),
                // Environment
                'environment' => app()->environment(),
            ],
        ]);

        Log::debug('posthog.exception.reported', [
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
        $class = get_class($e);
        $key = self::CACHE_KEY_PREFIX.md5($class);

        try {
            // Ensure key exists with TTL before incrementing.
            // add() is atomic — only sets if key doesn't exist, avoiding
            // the race between concurrent first-request increments.
            cache()->add($key, 0, 60);

            return cache()->increment($key) <= self::RATE_LIMIT_PER_CLASS;
        } catch (Throwable) {
            // The cache is down — often the same host outage that generates
            // this flood of exceptions. Failing open here would defeat the
            // flood guard exactly when it is needed. Fall back to a per-worker
            // counter: it still lets the first reports of the fault through,
            // so error tracking keeps signal during the outage, but caps the
            // volume instead of forwarding every occurrence.
            return $this->passesLocalRateLimit($class);
        }
    }

    /**
     * Rate-limit using an in-process counter, for when the shared cache is
     * down. PHP-FPM reuses a worker across requests, so the static counter
     * persists and caps the per-class flood within each worker.
     */
    private function passesLocalRateLimit(string $class): bool
    {
        $window = time() - (time() % 60);
        $entry = self::$localThrottle[$class] ?? null;

        if ($entry === null || $entry['window'] !== $window) {
            self::$localThrottle[$class] = ['window' => $window, 'count' => 1];

            return true;
        }

        $count = $entry['count'] + 1;
        self::$localThrottle[$class]['count'] = $count;

        return $count <= self::RATE_LIMIT_PER_CLASS;
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
     *
     * Hashes the class, the file, and a normalized message. The message is
     * normalized because raw messages embed per-request values — a
     * QueryException carries the full SQL with bound values, so an unnormalized
     * message opens a fresh issue on every occurrence instead of grouping.
     */
    private function buildFingerprint(Throwable $e): string
    {
        return md5(get_class($e).'|'.$e->getFile().'|'.$this->normalizeMessage($e->getMessage()));
    }

    /**
     * Strip per-request values from an exception message so the same fault
     * gets one stable fingerprint.
     *
     * Laravel appends the rendered query to QueryException messages:
     * "<driver error> (Connection: pgsql, SQL: select ... where "id" = <value>)".
     * The bound value changes on every request, so the whole SQL suffix is
     * dropped. The driver error before it stays, so a connection failure keeps
     * a different fingerprint than a syntax error. Quoted literals and digit
     * runs in the remaining text are replaced with a placeholder so ids,
     * counts, and timestamps in any message do not fragment the fingerprint.
     */
    private function normalizeMessage(string $message): string
    {
        $message = (string) preg_replace('/\s*\((?:Connection|SQL):.*$/s', '', $message);
        $message = (string) preg_replace('/\'[^\']*\'|"[^"]*"/', '?', $message);

        return (string) preg_replace('/\d+/', '?', $message);
    }

    /**
     * Scrub secret path parameters from the request path before forwarding
     * to the error-tracking provider.
     *
     * Several routes carry a bearer-style secret as a path segment rather
     * than in the query string: short-link codes (`/link/{code}`), password
     * reset tokens (`reset-password/{token}`), email-verification hashes
     * (`verify-email/{id}/{hash}`), and invite-opt-out email hashes
     * (`invite-optout/{emailHash}`). If an exception fires while handling one
     * of these, the raw path would exfiltrate that secret to PostHog. Each is
     * collapsed to a stable placeholder that still routes/errors usefully.
     */
    private function scrubRequestPath(string $path): string
    {
        if (preg_match('#^(link|reset-password|invite-optout)/[^/]+#', $path, $m)) {
            return $m[1].'/*';
        }

        if (preg_match('#^verify-email/[^/]+/[^/]+#', $path)) {
            return 'verify-email/*/*';
        }

        return $path;
    }
}
