<?php

namespace App\Exceptions;

use App\Services\Calendar\Google\GoogleCalendarClient;
use RuntimeException;

/**
 * Thrown by {@see GoogleCalendarClient} when a
 * Google Calendar request fails in a way the queued job (S03) should retry
 * or dead-letter: any non-2xx that is NOT a revocation signal (HTTP 401/
 * invalid_grant → {@see CalendarSyncRevoked} instead) — 5xx backend errors,
 * 403 rate-limit answers, malformed payloads. The status and response body
 * ride the exception so the job's retry/dead-letter path can log exactly
 * what Google said without re-fetching anything.
 *
 * Deliberately carries NO retry logic: the client makes exactly one
 * attempt per call — backoff, ->tries and --timeout belong to the S03 job,
 * not the transport.
 */
class CalendarSyncRequestFailed extends RuntimeException
{
    /**
     * @param  int  $status  the HTTP status Google answered with (200 for a
     *                       malformed-success payload — see ::tokenRefresh)
     * @param  string  $body  the raw response body, for the retry/dead-letter log
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Google Calendar request failed with HTTP {$status}.");
    }

    /**
     * The Calendar API itself answered a call with a non-2xx.
     */
    public static function api(string $method, string $path, int $status, string $body): self
    {
        return new self(
            $status,
            $body,
            sprintf('Google Calendar API %s /calendar/v3/%s failed with HTTP %d.', strtoupper($method), $path, $status),
        );
    }

    /**
     * The OAuth token endpoint failed the access-token refresh (non-2xx, or
     * a 2xx that carried no usable access token).
     */
    public static function tokenRefresh(int $status, string $body): self
    {
        return new self(
            $status,
            $body,
            "Google Calendar access-token refresh failed with HTTP {$status}.",
        );
    }

    /**
     * A refresh succeeded at the HTTP layer but the payload had no access
     * token — nothing to authenticate with, same retryable failure surface.
     */
    public static function malformedRefreshPayload(int $status, string $body): self
    {
        return new self(
            $status,
            $body,
            'Google Calendar access-token refresh returned no access token.',
        );
    }
}
