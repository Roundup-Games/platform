<?php

namespace App\Services\Discord;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort poster to an INCOMING Discord webhook URL — the second
 * (opt-in) page pipe for observability:scan (observability 2026-11-03).
 *
 * Distinct from {@see DiscordWebhookClient} by design: that client speaks
 * the bot-token REST API (retries, throws, composes cards). This one
 * addresses a pre-configured incoming webhook whose URL carries its own
 * auth — a fire-and-forget ops side channel. One attempt, 3s timeout,
 * catch-all: an alerting side channel must never break the scan (and its
 * failure surfaces as an info log, never as an error row in the very
 * table this backbone feeds).
 */
class DiscordAlertWebhook
{
    private int $timeoutSeconds;

    public function __construct(?int $timeoutSeconds = null)
    {
        $this->timeoutSeconds = $timeoutSeconds ?? 3;
    }

    /**
     * Post the payload to the configured webhook. Returns false (never
     * throws) when unconfigured, unreachable, or erroring.
     *
     * @param  array<string, mixed>  $payload  Full Discord webhook payload.
     * @return bool Whether the post was attempted AND answered 2xx.
     */
    public function post(array $payload): bool
    {
        $url = config('observability.discord_webhook_url');

        if (! is_string($url) || $url === '') {
            return false;
        }

        try {
            Http::timeout($this->timeoutSeconds)
                ->asJson()
                ->post($url, $payload)
                ->throw();

            return true;
        } catch (Throwable $e) {
            // Info, not error: an error here would re-enter the alerting
            // backbone the caller just ran.
            Log::info('observability.discord_page_failed', [
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
