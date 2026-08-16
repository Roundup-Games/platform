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
use PDOException;
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
 *
 * A database connection failure carries the host, port, database name, and a
 * SQL fragment in its message. Such failures are infrastructure blips, not
 * application bugs, so this reporter groups them under one stable issue and
 * scrubs the message before it reaches error tracking.
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
     * SQLSTATE codes for connection-level database failures:
     * 08006 connection_failure, 08001 unable to establish connection,
     * 57P01 admin shutdown. These signal an infrastructure outage.
     */
    private const CONNECTION_SQLSTATES = ['08006', '08001', '57P01'];

    /**
     * Stable, secret-free message and fingerprint for infrastructure failures.
     * Keeps the private host, port, database name, and SQL out of the issue
     * title, and groups every connection blip under a single issue.
     */
    private const INFRASTRUCTURE_MESSAGE = 'Database connection failure (infrastructure — details scrubbed)';

    private const INFRASTRUCTURE_FINGERPRINT = 'infrastructure:database_connection';

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

        $isInfrastructureFailure = $this->isInfrastructureFailure($e);

        $distinctId = $this->resolveDistinctId();
        $fingerprint = $isInfrastructureFailure
            ? self::INFRASTRUCTURE_FINGERPRINT
            : $this->buildFingerprint($e);

        // Build the structured exception list the PostHog ingestion endpoint requires.
        // PostHog expects $exception_list as an array of objects with type/value/mechanism/stacktrace.
        $exceptionList = ExceptionPayloadBuilder::buildExceptionList($e);
        $exceptionList = ExceptionPayloadBuilder::overridePrimaryMechanism($exceptionList, [
            'type' => 'manual',
            'handled' => false,
        ]);

        // Replace the leaky connection message with a stable, scrubbed string.
        if ($isInfrastructureFailure) {
            $exceptionList = $this->scrubInfrastructureMessages($exceptionList);
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
            // Auth::user() and session() both read the session store, which may
            // be the thing that just failed (the database session driver during
            // a Postgres outage). Fall back to a stable id so reporting an
            // infrastructure failure never throws while reading a dead store.
            return 'anon:unknown';
        }
    }

    /**
     * Check whether an exception, or one it wraps, is a connection-level
     * database failure — an infrastructure blip rather than an app bug.
     */
    private function isInfrastructureFailure(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof QueryException && ! $current instanceof PDOException) {
                continue;
            }

            $sqlState = $this->extractSqlState($current);

            if ($sqlState !== null && in_array($sqlState, self::CONNECTION_SQLSTATES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the five-character SQLSTATE from a database exception. Reads
     * errorInfo first, then the code, then the message — never returns any
     * other part of the message, so no host or SQL leaks out.
     */
    private function extractSqlState(Throwable $e): ?string
    {
        $errorInfo = $e->errorInfo ?? null;
        if (is_array($errorInfo) && isset($errorInfo[0]) && is_string($errorInfo[0])) {
            return $errorInfo[0];
        }

        $code = $e->getCode();
        if (is_string($code) && preg_match('/^[0-9A-Z]{5}$/', $code)) {
            return $code;
        }

        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $e->getMessage(), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Replace every message in the exception list with a stable, secret-free
     * string. The connection message holds the host, port, database name, and
     * a SQL fragment, so none of it reaches error tracking.
     *
     * @param  array<int, array<string, mixed>>  $exceptionList
     * @return array<int, array<string, mixed>>
     */
    private function scrubInfrastructureMessages(array $exceptionList): array
    {
        foreach ($exceptionList as $index => $entry) {
            if (is_array($entry) && array_key_exists('value', $entry)) {
                $exceptionList[$index]['value'] = self::INFRASTRUCTURE_MESSAGE;
            }
        }

        return $exceptionList;
    }

    /**
     * Build a fingerprint for grouping similar exceptions in PostHog.
     * Strips line numbers so same exception in same file groups together.
     */
    private function buildFingerprint(Throwable $e): string
    {
        return md5(get_class($e).'|'.$e->getFile().'|'.$e->getMessage());
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
