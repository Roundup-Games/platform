<?php

namespace App\Services\Discord;

use App\Exceptions\DiscordApiException;
use App\Jobs\PublishGameToDiscord;

/**
 * Raised by {@see DiscordPublisher::publish()} when one or more target guilds
 * failed to receive the card (their {@see DiscordApiException} was already
 * logged). The publisher continues past per-guild failures so one bad guild
 * does not block the rest, then throws this once at the end so the queued job
 * ({@see PublishGameToDiscord}) retries the whole game —
 * edit-in-place keeps the retry idempotent for the guilds that already
 * succeeded.
 */
class DiscordPublishException extends \RuntimeException {}
