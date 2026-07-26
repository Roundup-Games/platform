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
 *
 * The HTTP status code (when there is one) is preserved on the instance so
 * callers can branch on it — e.g. the publisher treats a 404 "Unknown Message"
 * on an edit as "the card was deleted out-of-band" and self-heals by
 * re-posting, rather than failing the job forever.
 */
class DiscordApiException extends \RuntimeException
{
    public function __construct(string $message = '', private readonly ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The terminal HTTP status Discord returned, when applicable.
     *
     * Null for non-HTTP failures (connection errors that never received a
     * response). Callers should null-check before branching.
     */
    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public static function requestFailed(int $statusCode, string $endpoint, string $body = ''): self
    {
        $bodySnippet = $body === '' ? '' : ' body='.mb_substr($body, 0, 200);

        return new self("Discord API request to {$endpoint} failed with status {$statusCode}.{$bodySnippet}", $statusCode);
    }

    public static function rateLimited(string $endpoint, float $retryAfter): self
    {
        return new self("Discord API rate-limited (429) on {$endpoint} after exhausting retries; last retry_after={$retryAfter}s.", 429);
    }

    public static function serverError(string $endpoint, int $status): self
    {
        return new self("Discord API server error {$status} on {$endpoint} after exhausting retries.", $status);
    }

    public static function connection(string $endpoint, \Throwable $previous): self
    {
        return new self("Discord API connection to {$endpoint} failed after exhausting retries: {$previous->getMessage()}", previous: $previous);
    }

    public static function missingMessageId(string $endpoint): self
    {
        return new self("Discord API response from {$endpoint} did not contain a message id.");
    }

    public static function missingChannelId(string $endpoint): self
    {
        return new self("Discord API response from {$endpoint} did not contain a channel id.");
    }
}
