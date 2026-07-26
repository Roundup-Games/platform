<?php

namespace App\Exceptions;

use App\Services\Discord\DiscordBotInstallService;
use App\Services\Discord\DiscordPublishException;

/**
 * Raised by {@see DiscordBotInstallService} when a
 * landlord bot-install step fails terminally: the OAuth code exchange, the
 * guild-detail fetch, or the channel-list fetch.
 *
 * Distinct from {@see DiscordApiException} (which belongs to the webhook
 * push client) and {@see DiscordPublishException}
 * (the publisher's aggregate). Keeping the install surface typed lets the
 * callback route catch install failures without swallowing unrelated errors.
 *
 * Named static constructors mirror the BggApiException / DiscordApiException
 * convention so the caller sees a typed reason rather than a bare message.
 */
class DiscordBotInstallException extends \RuntimeException
{
    public static function tokenExchangeFailed(int $status, string $body = ''): self
    {
        $snippet = $body === '' ? '' : ' body='.mb_substr($body, 0, 200);

        return new self("Discord bot install: OAuth code exchange failed with status {$status}.{$snippet}");
    }

    public static function missingGuildId(): self
    {
        return new self('Discord bot install: missing or malformed guild_id in the callback. Discord always returns it for a bot install — a missing or non-snowflake value means a malformed or tampered callback.');
    }

    /**
     * The installer does not own or hold MANAGE_GUILD on the guild they
     * claimed in the callback. Raised after verifying the installer's actual
     * guild membership against the user bearer token — closes the guild-
     * takeover vector where an attacker replayed a valid code with a victim
     * guild_id.
     */
    public static function guildNotOwned(string $guildId): self
    {
        return new self("Discord bot install: you do not own or have Manage Guild permissions on guild {$guildId}. Only a server admin can install the roundup bot.");
    }

    /**
     * The install round-trip's anti-CSRF state or PKCE verifier was missing or
     * did not match. Raised when the callback cannot be tied to the landlord
     * who initiated it (session expired, or a cross-site forged callback).
     */
    public static function invalidState(): self
    {
        return new self('Discord bot install: the install session expired or the request was not initiated by you. Please try installing again.');
    }

    public static function guildFetchFailed(string $guildId, int $status): self
    {
        return new self("Discord bot install: could not fetch guild {$guildId} (status {$status}). Is the bot actually installed there?");
    }

    public static function channelFetchFailed(string $guildId, int $status): self
    {
        return new self("Discord bot install: could not list channels for guild {$guildId} (status {$status}).");
    }
}
