<?php

namespace App\Services\Discord;

use App\Exceptions\DiscordApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin REST client for posting roundup cards to Discord channels.
 *
 * Composes with the roundup bot's application token (D118 — "the bot
 * application token shared with the M057 Interactions endpoint") against
 * Discord's REST channel-message endpoints. NO gateway SDK, NO DiscordPHP,
 * NO ReactPHP, NO persistent daemon (D117 — thin push+REST architecture).
 *
 * The low-level Discord integration that the publisher (T05) and digest (S02)
 * build on. It posts/edits/deletes messages addressed by Discord channel id +
 * message id, and handles Discord's reactive rate-limiting (HTTP 429) by
 * honoring the authoritative `Retry-After` directive before retrying.
 *
 * Auth model: `Authorization: Bot {token}`. We do NOT use Laravel's
 * `withToken()` because that defaults to the `Bearer` scheme, which Discord
 * rejects for bot endpoints.
 *
 * Failure surface (see Q5 in the task summary):
 *  - 429 Too Many Requests → back off `Retry-After` seconds, retry, up to
 *    maxAttempts; if the wait exceeds maxRetryAfterSeconds or attempts are
 *    exhausted, throw {@see DiscordApiException::rateLimited()} so the queue
 *    layer retries the whole job later.
 *  - 5xx server error → brief fixed backoff, retry up to maxAttempts, then
 *    throw {@see DiscordApiException::serverError()}.
 *  - ConnectionException (DNS/timeout/refused) → retry up to maxAttempts,
 *    then throw {@see DiscordApiException::connection()}.
 *  - 4xx client error (non-429) → non-retryable; throw
 *    {@see DiscordApiException::requestFailed()} immediately.
 */
class DiscordWebhookClient
{
    private string $baseUrl;

    private string $botToken;

    private int $timeout;

    private int $maxAttempts;

    private float $maxRetryAfterSeconds;

    private float $serverErrorBackoffSeconds;

    /** @var \Closure(float): void */
    private \Closure $sleep;

    public function __construct(
        ?string $baseUrl = null,
        ?string $botToken = null,
        ?int $timeout = null,
        ?int $maxAttempts = null,
        ?float $maxRetryAfterSeconds = null,
        ?float $serverErrorBackoffSeconds = null,
        ?\Closure $sleep = null,
    ) {
        $configured = is_string($u = config('services.discord.api_base_url')) ? $u : 'https://discord.com/api/v10';
        $this->baseUrl = rtrim($baseUrl ?? $configured, '/');
        $this->botToken = $botToken ?? (is_string($t = config('services.discord.bot_token')) ? $t : '');
        $this->timeout = $timeout ?? 10;
        $this->maxAttempts = $maxAttempts ?? 3;
        $this->maxRetryAfterSeconds = $maxRetryAfterSeconds ?? 30.0;
        $this->serverErrorBackoffSeconds = $serverErrorBackoffSeconds ?? 1.0;
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) ($seconds * 1_000_000));
            }
        };
    }

    /**
     * Post a new message (card) to a Discord channel.
     *
     * @param  string  $channelId  Discord channel snowflake
     * @return string The created message snowflake id
     *
     * @throws DiscordApiException on non-retryable failure or exhausted retries
     */
    public function postMessage(string $channelId, DiscordWebhookPayload $payload): string
    {
        $response = $this->request('POST', "channels/{$channelId}/messages", $payload->toArray());

        return $this->extractMessageId($response, "channels/{$channelId}/messages");
    }

    /**
     * Edit (PATCH) an existing message in place — the re-publish path the
     * publisher uses so a composite-unique (game_id, guild_id) card updates
     * rather than duplicates.
     *
     * @param  string  $channelId  Discord channel snowflake
     * @param  string  $messageId  Discord message snowflake to edit
     * @return string The edited message snowflake id (echoed by Discord)
     *
     * @throws DiscordApiException on non-retryable failure or exhausted retries
     */
    public function editMessage(string $channelId, string $messageId, DiscordWebhookPayload $payload): string
    {
        $response = $this->request('PATCH', "channels/{$channelId}/messages/{$messageId}", $payload->toArray());

        return $this->extractMessageId($response, "channels/{$channelId}/messages/{$messageId}");
    }

    /**
     * Delete a message — the visibility-downgrade path (public → protected /
     * private) the publisher uses to pull a card off a channel.
     *
     * @param  string  $channelId  Discord channel snowflake
     * @param  string  $messageId  Discord message snowflake to delete
     *
     * @throws DiscordApiException on non-retryable failure or exhausted retries
     */
    public function deleteMessage(string $channelId, string $messageId): void
    {
        $this->request('DELETE', "channels/{$channelId}/messages/{$messageId}");
    }

    /**
     * PATCH the @original interaction response — the deferred-response surface
     * {@see ProcessDiscordRsvp} uses to resolve a DEFERRED interaction with the
     * ephemeral confirmation after the participant write completes (M057/S03/T03).
     *
     * Unlike {@see postMessage}/{@see editMessage} (channel-message endpoints
     * addressed by channel+message snowflakes and authenticated with the bot
     * token), this hits the INTERACTION webhook URL
     * `/webhooks/{application_id}/{interaction_token}/messages/@original`, which
     * is authenticated SOLELY by the interaction token in the path — Discord
     * issues one token per interaction (valid 15 min) and the @original sentinel
     * addresses the initial "Bot is thinking…" response the controller returned.
     * No `Authorization: Bot {token}` header is sent (token-authenticated), so
     * this path is independent of bot-token config and works even if the bot
     * token is rotated after the interaction was acked.
     *
     * @param  string  $applicationId  The bot application id (snowflake) from
     *                                 config('services.discord.bot_application_id').
     * @param  string  $interactionToken  The per-interaction token the controller
     *                                    captured (valid 15 min from ack).
     *
     * @throws DiscordApiException on non-retryable failure or exhausted retries
     */
    public function patchOriginalInteractionResponse(
        string $applicationId,
        string $interactionToken,
        DiscordWebhookPayload $payload,
    ): void {
        $path = "webhooks/{$applicationId}/{$interactionToken}/messages/@original";

        $this->request('PATCH', $path, $payload->toArray(), authenticated: false);
    }

    // ── HTTP lifecycle ──────────────────────────────────

    /**
     * The single owner of the Discord REST lifecycle: retry/backoff for 429,
     * 5xx, and connection loss, plus error mapping. Returns a successful
     * (2xx/204) Response for the caller to interpret.
     *
     * @param  array<string, mixed>|null  $payload
     *
     * @throws DiscordApiException on non-retryable failure or exhausted retries
     */
    private function request(string $method, string $path, ?array $payload = null, bool $authenticated = true): Response
    {
        $url = $this->baseUrl.'/'.$path;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->send($method, $url, $payload, $authenticated);
            } catch (ConnectionException $e) {
                if ($attempt >= $this->maxAttempts) {
                    throw DiscordApiException::connection($path, $e);
                }

                $this->logRetry('connection', $path, 0, $attempt, ['error' => $e->getMessage()]);
                ($this->sleep)($this->serverErrorBackoffSeconds);

                continue;
            }

            $status = $response->status();

            if ($status === 429) {
                $retryAfter = $this->parseRetryAfter($response);

                if ($retryAfter > $this->maxRetryAfterSeconds || $attempt >= $this->maxAttempts) {
                    throw DiscordApiException::rateLimited($path, $retryAfter);
                }

                $this->logRetry('rate_limited', $path, $status, $attempt, ['retry_after' => $retryAfter]);
                ($this->sleep)($retryAfter);

                continue;
            }

            if ($status >= 500) {
                if ($attempt >= $this->maxAttempts) {
                    throw DiscordApiException::serverError($path, $status);
                }

                $this->logRetry('server_error', $path, $status, $attempt);
                ($this->sleep)($this->serverErrorBackoffSeconds);

                continue;
            }

            if ($response->failed()) {
                // 4xx client error (non-429): non-retryable.
                Log::error('Discord API client error', [
                    'endpoint' => $path,
                    'status' => $status,
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                throw DiscordApiException::requestFailed($status, $path, $response->body());
            }

            return $response;
        }
    }

    /**
     * Send a single HTTP request with bot auth + JSON body. No retry logic —
     * that lives in {@see request()}.
     *
     * @param  array<string, mixed>|null  $payload
     *
     * @throws ConnectionException on network/timeout failure
     */
    private function send(string $method, string $url, ?array $payload, bool $authenticated = true): Response
    {
        $http = Http::timeout($this->timeout);

        // Channel-message endpoints (post/edit/delete) are bot-token
        // authenticated; interaction webhook endpoints (@original followup) are
        // token-authenticated by the URL alone and send no Authorization header.
        $request = $authenticated
            ? $http->withHeaders(['Authorization' => 'Bot '.$this->botToken])
            : $http;

        return match (strtoupper($method)) {
            'POST' => $request->asJson()->post($url, $payload ?? []),
            'PATCH' => $request->asJson()->patch($url, $payload ?? []),
            'DELETE' => $request->delete($url),
            default => throw new \InvalidArgumentException("Unsupported Discord HTTP method: {$method}"),
        };
    }

    /**
     * Discord's authoritative wait directive, in seconds. Prefers the JSON
     * `retry_after` body field (float seconds, present on per-route 429s),
     * falls back to the `Retry-After` header (integer seconds, present on
     * global 429s). Returns a safe 1s default if neither is present.
     */
    private function parseRetryAfter(Response $response): float
    {
        $body = $response->json();
        if (is_array($body) && isset($body['retry_after']) && is_numeric($body['retry_after'])) {
            return (float) $body['retry_after'];
        }

        $header = $response->header('Retry-After');
        if (is_numeric($header) && (float) $header > 0) {
            return (float) $header;
        }

        return 1.0;
    }

    /**
     * @return string The Discord message snowflake id
     *
     * @throws DiscordApiException when the response lacks a message id
     */
    private function extractMessageId(Response $response, string $path): string
    {
        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw DiscordApiException::missingMessageId($path);
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function logRetry(string $reason, string $path, int $status, int $attempt, array $extra = []): void
    {
        Log::warning('Discord API transient failure; retrying', array_merge([
            'reason' => $reason,
            'endpoint' => $path,
            'status' => $status,
            'attempt' => $attempt,
        ], $extra));
    }
}
