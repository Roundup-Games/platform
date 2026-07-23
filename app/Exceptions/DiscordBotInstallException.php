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
        return new self('Discord bot install: missing guild_id in the callback. Discord always returns it for a bot install — a missing value means a malformed or tampered callback.');
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
