<?php

namespace App\Jobs;

use App\Models\Game;
use App\Observers\GameObserver;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\DiscordPublishException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that publishes (or unpublishes) a roundup Game's enriched card to
 * every eligible Discord guild, via the {@see DiscordPublisher} chokepoint.
 *
 * Dispatched by {@see GameObserver} when a Game is saved and
 * Discord publishing is enabled (services.discord.publishing_enabled). Runs on
 * the existing Laravel queue so the Discord REST latency + reactive 429
 * backoff (T03) never blocks the web request that created/updated the game.
 *
 * The publisher is idempotent (edit-in-place via the composite-unique
 * discord_card_messages), so job retries are safe — guilds that already
 * received the card get a PATCH, not a duplicate.
 *
 * Failure handling: a {@see DiscordPublishException} (one or more guilds
 * failed) or a transient error bubbles so the queue retries the whole game.
 * After all retries are exhausted the job is logged but NOT marked fatal —
 * the next Game save re-arms the dispatch, and edit-in-place means the retry
 * converges rather than spamming.
 */
class PublishGameToDiscord implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum retry attempts before marking as failed. Generous because the
     * publisher is idempotent (edit-in-place) — a retry converges, never
     * duplicates.
     */
    public int $tries = 3;

    /**
     * Maximum time the job may run before timing out.
     */
    public int $timeout = 120;

    /**
     * Seconds to wait before retrying. Discord 429 backoff is handled inside
     * the webhook client; this is the inter-attempt delay for whole-job
     * failures (e.g. a Discord outage).
     */
    public int $backoff = 60;

    /**
     * @param  string  $gameId  Game id (string PK) — passed as a primitive so
     *                          the job serializes cleanly and survives a model
     *                          change between dispatch and handle().
     */
    public function __construct(
        public string $gameId,
    ) {}

    public function handle(DiscordPublisher $publisher): void
    {
        $game = Game::find($this->gameId);

        if (! $game) {
            // Game deleted between dispatch and execution — nothing to publish,
            // and any posted card was already removed by the deleting() observer
            // path. Log and exit cleanly.
            Log::info('discord_publish.job.game_missing', [
                'game_id' => $this->gameId,
            ]);

            return;
        }

        Log::info('discord_publish.job.started', [
            'game_id' => $this->gameId,
        ]);

        $publisher->publish($game);

        Log::info('discord_publish.job.completed', [
            'game_id' => $this->gameId,
        ]);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('discord_publish.job.failed', [
            'game_id' => $this->gameId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
