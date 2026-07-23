<?php

namespace App\Exceptions;

use App\Services\Discord\DiscordWebhookClient;

/**
 * Raised by {@see DiscordWebhookClient} when a Discord
 * REST call fails terminally (non-retryable HTTP status, exhausted retries on
 * 429/5xx, or a network connection error).
 *
 * Mirrors the {@see BggApiException} pattern: named static constructors so the
 * caller sees a typed reason rather than a generic RuntimeException.
 */
class DiscordApiException extends \RuntimeException
{
    public static function requestFailed(int $statusCode, string $endpoint, string $body = ''): self
    {
        $bodySnippet = $body === '' ? '' : ' body='.mb_substr($body, 0, 200);

        return new self("Discord API request to {$endpoint} failed with status {$statusCode}.{$bodySnippet}");
    }

    public static function rateLimited(string $endpoint, float $retryAfter): self
    {
        return new self("Discord API rate-limited (429) on {$endpoint} after exhausting retries; last retry_after={$retryAfter}s.");
    }

    public static function serverError(string $endpoint, int $status): self
    {
        return new self("Discord API server error {$status} on {$endpoint} after exhausting retries.");
    }

    public static function timeout(string $endpoint): self
    {
        return new self("Discord API request to {$endpoint} timed out.");
    }

    public static function connection(string $endpoint, \Throwable $previous): self
    {
        return new self("Discord API connection to {$endpoint} failed after exhausting retries: {$previous->getMessage()}", 0, $previous);
    }

    public static function missingMessageId(string $endpoint): self
    {
        return new self("Discord API response from {$endpoint} did not contain a message id.");
    }
}
