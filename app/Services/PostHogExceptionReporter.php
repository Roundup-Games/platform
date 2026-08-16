<?php

namespace App\Services;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
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
        // Strip SQL and bound values from every exception value so bindings
        // (session IDs, email addresses) never reach the error-tracking provider.
        $exceptionList = $this->scrubExceptionValues($exceptionList);

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
     * Uses class and file (line numbers are dropped so the same exception in
     * the same file groups together). The message discriminator is normalized
     * first: a raw QueryException message carries the SQL and its bound values,
     * so a per-visitor session ID or an inlined email would make every
     * occurrence hash differently. normalizeMessage() removes those, and for a
     * database driver error the discriminator collapses to the SQLSTATE code,
     * so all failures with the same code group into one issue.
     */
    private function buildFingerprint(Throwable $e): string
    {
        return md5(get_class($e).'|'.$e->getFile().'|'.$this->fingerprintDiscriminator($e));
    }

    /**
     * Resolve the message part of the fingerprint.
     *
     * A database driver error exposes a SQLSTATE code (e.g. `08006` for a
     * connection failure) in errorInfo[0]. Reading it structurally is reliable
     * across drivers, unlike matching the message string. All errors with the
     * same code collapse to that code, so a brief outage that fails many
     * statements makes one issue, not one per statement. Other exceptions fall
     * back to the normalized message.
     */
    private function fingerprintDiscriminator(Throwable $e): string
    {
        if ($e instanceof QueryException && ($sqlState = $e->errorInfo[0] ?? null)) {
            return 'SQLSTATE:'.$sqlState;
        }

        return $this->normalizeMessage($e->getMessage());
    }

    /**
     * Replace every exception value with a normalized form.
     *
     * A QueryException chain reaches PostHog as two frames — the driver
     * PDOException and the Laravel QueryException — and both inline bound
     * values into their message. normalizeMessage() removes them from each.
     *
     * @param  array<int, array<string, mixed>>  $exceptionList
     * @return array<int, array<string, mixed>>
     */
    private function scrubExceptionValues(array $exceptionList): array
    {
        foreach ($exceptionList as &$frame) {
            if (isset($frame['value']) && is_string($frame['value'])) {
                $frame['value'] = $this->normalizeMessage($frame['value']);
            }
        }

        return $exceptionList;
    }

    /**
     * Remove bound values from a raw exception message.
     *
     * Laravel appends `(Connection: <name>, SQL: <sql with bindings inlined>)`
     * to a QueryException message, and Postgres adds a `DETAIL:` line that
     * echoes the value that broke a constraint (for example a real email
     * address on a unique violation). Both carry bound values, so both are
     * stripped. The stable driver reason (the SQLSTATE line, the constraint
     * name) is kept for grouping and debugging.
     */
    private function normalizeMessage(string $message): string
    {
        foreach ([' (Connection:', ' (SQL:', 'DETAIL:'] as $marker) {
            if (($pos = strpos($message, $marker)) !== false) {
                $message = substr($message, 0, $pos);
            }
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
