<?php

namespace App\Exceptions;

use App\Services\Calendar\Google\GoogleCalendarAuth;
use RuntimeException;

/**
 * Raised by {@see GoogleCalendarAuth} when a
 * calendar-link OAuth round trip (M083/S02-T02) fails before a connection
 * row can be established: an unverifiable state, a missing authorization
 * code, or a Google payload missing a piece the connection contract
 * requires.
 *
 * The Settings calendar-link controller catches it and surfaces the honest
 * failure marker back to the calendar section; the cause rides the
 * structured log line (calendar_sync.connect_failed), never the raw
 * message to the user. Named static constructors mirror the
 * DiscordBotInstallException convention so the caller sees a typed reason
 * rather than a bare message.
 */
class CalendarLinkFailed extends RuntimeException
{
    /**
     * The callback's state could not be verified for this user — tampered,
     * expired, or signed for a different user. The round trip can never be
     * bound to the authenticated user, so no exchange happens.
     */
    public static function stateMismatch(): self
    {
        return new self('Calendar link: the connect state could not be verified for this user (tampered, expired, or not theirs).');
    }

    public static function missingAuthorizationCode(): self
    {
        return new self('Calendar link: Google callback carried no authorization code.');
    }

    public static function missingAccessToken(): self
    {
        return new self('Calendar link: Google token exchange returned no access token.');
    }

    public static function missingRefreshToken(): self
    {
        return new self('Calendar link: Google token exchange returned no refresh token and no prior connection held one.');
    }

    public static function missingAccountEmail(): self
    {
        return new self('Calendar link: Google userinfo payload carried no account email.');
    }
}
