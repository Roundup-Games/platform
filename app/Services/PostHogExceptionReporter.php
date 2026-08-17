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

        // Normalize every message before it reaches the error-tracking store.
        // The SDK copies each Throwable message (including the wrapped
        // PDOException) into the entry 'value', which becomes the issue
        // description. A QueryException message carries per-request bound
        // values, and a Postgres unique violation carries the offending key
        // value (a customer email address). Scrub both so no per-row data or
        // PII leaves the app, using the same shape as the fingerprint.
        foreach ($exceptionList as $i => $entry) {
            if (isset($entry['value']) && is_string($entry['value'])) {
                $exceptionList[$i]['value'] = $this->normalizeMessage($entry['value']);
            }
        }

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
        try {
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
        } catch (Throwable) {
            // With SESSION_DRIVER=database the auth guard and session read hit
            // Postgres. During a database outage — exactly when we report it —
            // that read throws, so fall back to a placeholder instead of the
            // reporter becoming a second failure on top of the outage.
            return 'anon:unknown';
        }
    }

    /**
     * Build a fingerprint for grouping similar exceptions in PostHog.
     *
     * The key is class plus file plus a normalized message shape. Without the
     * normalization a QueryException fingerprints on the failing SQL, which
     * with SESSION_DRIVER=database carries the per-visitor session id from the
     * session-read query — so one connection outage splits into one issue per
     * request instead of grouping into one.
     */
    private function buildFingerprint(Throwable $e): string
    {
        return md5(get_class($e).'|'.$e->getFile().'|'.$this->normalizeMessage($e->getMessage()));
    }

    /**
     * Normalize an exception message into a stable, PII-free shape.
     *
     * A QueryException message ends with " (Connection: <name>, SQL: <sql>)",
     * where the SQL holds per-request bound values — most damagingly the
     * session id in the session-read query. A Postgres unique violation adds a
     * "DETAIL:  Key (col)=(value) already exists." line, and that value can be
     * a customer email address. Both are removed, and the connection endpoint
     * host and port are collapsed, so a connection-level failure produces one
     * stable message shape regardless of visitor or pool member.
     */
    private function normalizeMessage(string $message): string
    {
        $patterns = [
            // Drop the "(Connection: <name>, SQL: <sql with bound values>)" tail.
            '/ \(Connection: .*$/s' => '',
            // Drop Postgres "DETAIL: ..." lines — they quote key values (PII).
            '/^\h*DETAIL:.*$/mi' => '',
            // Collapse the connection endpoint so different pool members or DNS
            // results do not fragment one connection failure. Covers both the
            // "server at "host", port N" and "host "host" ... port N" forms.
            '/server at "[^"]*"/i' => 'server at "*"',
            '/host "[^"]*"/i' => 'host "*"',
            '/port \d+/i' => 'port *',
            // Collapse blank lines left by the removals above.
            '/\v+/' => "\n",
        ];

        // preg_replace returns null only on internal error; keep the last good
        // value so the method always returns a string.
        foreach ($patterns as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message) ?? $message;
        }

        return trim($message);
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
