<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\Discord\DiscordPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Debounced card-refresh job: re-publishes a roundup Game's enriched Discord
 * card through the {@see DiscordPublisher} chokepoint (edit-in-place PATCH)
 * when roster churn changes the card's roster state but does NOT re-save the
 * Game itself.
 *
 * Roster churn (a GameParticipant created / status-changed / deleted — a join,
 * a drop, a waitlist promotion, a bench demote) never re-saves the Game, so
 * {@see GameObserver::saved()} (which dispatches {@see PublishGameToDiscord})
 * never fires and the card goes stale. RefreshDiscordCard fills that gap.
 *
 * Debounce / coalescing: this job implements {@see ShouldBeUnique} keyed on
 * the gameId with a {@see uniqueFor()} window equal to the debounce window
 * (config services.discord.card_refresh_debounce_seconds, default 15s). The
 * constructor applies the same window as a dispatch delay. Together this means
 * the first roster change schedules a refresh N seconds out and acquires a
 * unique lock for N seconds; every subsequent change within that window is
 * suppressed (the lock is held while the job is delayed). The refresh runs
 * exactly once at the window edge. This is the same ShouldBeUnique-keyed-on-id
 * debounce pattern already used by {@see WarmTrendingNearby} and
 * {@see WarmDashboardCache}.
 *
 * Relationship to PublishGameToDiscord (material change): material Game saves
 * (date_time / venue / status / visibility) still dispatch PublishGameToDiscord
 * — immediate, non-debounced — because material changes are discrete and
 * low-frequency. RefreshDiscordCard is the roster-churn-only path. Both call
 * the same DiscordPublisher::publish() chokepoint, so edit-in-place keeps them
 * idempotent and they compose without conflict (a refresh re-renders whatever
 * the latest material publish produced, with the updated roster).
 *
 * Failure handling mirrors PublishGameToDiscord: a DiscordPublishException or
 * transient error bubbles so the queue retries the whole game (tries=3,
 * backoff=60s). Edit-in-place makes every retry converge rather than
 * duplicate. A game deleted between dispatch and run exits cleanly via
 * {@see deleteWhenMissingModels} + an explicit find() guard.
 */
class RefreshDiscordCard implements ShouldBeUnique, ShouldQueue
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
     * failures.
     */
    public int $backoff = 60;

    /**
     * Drop the job silently if the Game was deleted between dispatch and run —
     * there is nothing to refresh and nothing to retry.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  string  $gameId  Game id (string PK) — passed as a primitive so
     *                          the job serializes cleanly and survives a model
     *                          deletion between dispatch and handle().
     */
    public function __construct(
        public string $gameId,
    ) {
        // Apply the debounce window as the dispatch delay so the job is
        // inherently debounced — the first roster change schedules a refresh
        // N seconds out; ShouldBeUnique suppresses every subsequent change
        // within the window. Read from config so the window is tunable.
        $this->delay($this->debounceSeconds());
    }

    /**
     * Unique key per game — coalesces rapid roster churn into one refresh.
     */
    public function uniqueId(): string
    {
        return $this->gameId;
    }

    /**
     * Hold the unique lock for the full debounce window so dispatches within
     * the window are suppressed (the job is delayed for the same duration).
     */
    public function uniqueFor(): int
    {
        return $this->debounceSeconds();
    }

    public function handle(DiscordPublisher $publisher): void
    {
        $game = Game::find($this->gameId);

        if (! $game) {
            // Game deleted between dispatch and execution — nothing to
            // refresh, and any posted card was already removed by the
            // deleting() observer path. Log and exit cleanly.
            Log::info('discord_card_refresh.job.game_missing', [
                'game_id' => $this->gameId,
            ]);

            return;
        }

        Log::info('discord_card_refresh.job.started', [
            'game_id' => $this->gameId,
        ]);

        $publisher->publish($game);

        Log::info('discord_card_refresh.job.completed', [
            'game_id' => $this->gameId,
        ]);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('discord_card_refresh.job.failed', [
            'game_id' => $this->gameId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }

    /**
     * The debounce / coalesce window (seconds), from the discord config block.
     */
    private function debounceSeconds(): int
    {
        $seconds = config('services.discord.card_refresh_debounce_seconds', 15);

        return is_numeric($seconds) ? (int) $seconds : 15;
    }
}
