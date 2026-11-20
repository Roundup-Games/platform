<?php

namespace App\Exceptions;

use App\Models\GoogleCalendarConnection;
use App\Services\Calendar\Google\GoogleCalendarClient;
use RuntimeException;

/**
 * Thrown by {@see GoogleCalendarClient} when
 * Google refused the connection's grant outright (M083/S02-T04): either the
 * refresh token was refused with invalid_grant/401 at the token endpoint, or
 * the Calendar API answered the access token with 401.
 *
 * This is NOT a retryable failure — the consent is gone at Google's side.
 * The client has already written the truth to the connection row
 * ({@see GoogleCalendarConnection::markRevoked()}) and emitted the
 * calendar_sync.revocation_detected structured log before throwing, so the
 * queued sync job (S03) treats this exception as a terminal skip for that
 * connection until the user re-connects; the Settings revoked banner
 * (S02-T03) is what surfaces the re-auth path to the user.
 *
 * Named static constructors mirror the CalendarLinkFailed convention so the
 * caller sees a typed reason rather than a bare message.
 */
class CalendarSyncRevoked extends RuntimeException
{
    /**
     * The token endpoint refused the stored refresh grant (invalid_grant or
     * 401) — Google revoked or rotated away the long-lived credential.
     */
    public static function refreshGrantRefused(): self
    {
        return new self('Calendar sync: Google refused the stored refresh grant (invalid_grant/401 at the token endpoint); the connection was marked revoked and needs re-connecting.');
    }

    /**
     * The Calendar API refused the access token with 401 — the consent was
     * revoked at Google's side between the token mint and this call.
     */
    public static function apiCallRefused(): self
    {
        return new self('Calendar sync: the Google Calendar API refused the access token (HTTP 401); the connection was marked revoked and needs re-connecting.');
    }
}
