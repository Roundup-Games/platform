<?php

namespace App\Jobs;

use App\Models\DiscordGuild;
use App\Services\Discord\DiscordDigestPublisher;
use App\Services\Discord\DiscordPublishException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that rewrites the daily two-week calendar digest for a single
 * Discord guild, via the {@see DiscordDigestPublisher} chokepoint (M057/S02).
 *
 * Dispatched one-per-guild by the `discord:publish-digest` scheduled command
 * (T04). Splitting the per-guild work onto the queue isolates each guild's
 * Discord REST latency + reactive 429 backoff from the (fast) command and from
 * every other guild — one bad guild never blocks the rest. This is the digest
 * analogue of {@see PublishGameToDiscord}: one job per unit-of-work, carrying
 * the guild id as a primitive so the job serializes cleanly and survives a
 * model change between dispatch and handle().
 *
 * The publisher is idempotent within a day (same-day re-run PATCHes today's
 * starter; cross-day creates a fresh thread), so job retries are safe. The
 * daily-thread lifecycle is APPEND-based, though, so two CONCURRENT runs for
 * the same guild could both observe no-tracked-for-today and each create a
 * thread → duplicate daily threads that never converge. {@see ShouldBeUnique}
 * keyed on guild id coalesces a concurrent burst (a manual --guild= re-run
 * racing the scheduler, or a retry racing a fresh dispatch) into one run,
 * mirroring {@see PublishGameToDiscord}.
 *
 * Failure handling: a {@see DiscordPublishException}
 * (terminal post/edit failure, already logged discord_digest.post_failed by
 * the publisher) bubbles so the queue retries the whole guild. After all
 * retries are exhausted the job logs discord_digest.job.failed but is NOT
 * marked fatal — the next daily run re-dispatches fresh, and edit-in-place
 * keeps the retry idempotent.
 */
class PublishDiscordDigest implements ShouldBeUnique, ShouldQueue
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
     * Hold the unique lock long enough to coalesce a concurrent burst of
     * dispatches for the same guild (a manual --guild= re-run racing the
     * scheduler, or a retry racing a fresh dispatch) into one run — mirrors
     * {@see PublishGameToDiscord}. The daily-thread lifecycle is append-based,
     * so without this, two concurrent runs could each create a thread and
     * never converge; a suppressed dispatch is safely picked up by the next
     * daily run (or within-day refresh).
     */
    public int $uniqueFor = 60;

    /**
     * @param  string  $guildId  DiscordGuild primary key (string UUID) — passed
     *                           as a primitive so the job serializes cleanly and
     *                           survives a model change between dispatch and
     *                           handle(). Mirrors PublishGameToDiscord::$gameId.
     */
    public function __construct(
        public string $guildId,
    ) {}

    /**
     * Unique key per guild — coalesces concurrent dispatches into one run.
     */
    public function uniqueId(): string
    {
        return $this->guildId;
    }

    public function handle(DiscordDigestPublisher $publisher): void
    {
        $guild = DiscordGuild::find($this->guildId);

        if (! $guild) {
            // Guild removed between dispatch and execution — nothing to publish,
            // and any posted digest was on the now-deleted guild row. Log and
            // exit cleanly so the queue does not mark a non-entity as failed.
            Log::info('discord_digest.job.guild_missing', [
                'guild_id' => $this->guildId,
            ]);

            return;
        }

        Log::info('discord_digest.job.started', [
            'guild_id' => $this->guildId,
        ]);

        // The publisher owns all gating (publishing_enabled, paused,
        // no-calendar-channel) and the post/edit-in-place lifecycle. A
        // DiscordPublishException propagates so the queue retries this guild.
        $publisher->publish($guild);

        Log::info('discord_digest.job.completed', [
            'guild_id' => $this->guildId,
        ]);
    }

    /**
     * Handle a job failure after all retries are exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('discord_digest.job.failed', [
            'guild_id' => $this->guildId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
