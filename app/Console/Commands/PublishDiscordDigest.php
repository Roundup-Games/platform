<?php

namespace App\Console\Commands;

// Aliased because the command class shares the base name with the job
// (different namespaces, same unqualified name). The job is the canonical
// `App\Jobs\PublishDiscordDigest`; this command dispatches it per guild.
use App\Jobs\PublishDiscordDigest as PublishDiscordDigestJob;
use App\Jobs\PublishGameToDiscord;
use App\Models\DiscordGuild;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Publish (or rewrite) the daily two-week calendar digest to every eligible
 * Discord guild (M057/S02, T04).
 *
 * The digest is scheduler-driven, NOT event-driven (unlike the per-game card
 * path that the GameObserver wires). This command is the scheduler's entry
 * point: it iterates postable guilds and dispatches ONE {@see
 * PublishDiscordDigest} job per guild. Splitting the per-guild work onto the
 * queue isolates each guild's Discord REST latency + reactive 429 backoff from
 * the (fast) command and from every other guild — one bad guild never blocks
 * the rest. This mirrors {@see SendWeeklyDigest} (iterate + dispatch per row)
 * and {@see PublishGameToDiscord} (one queued job per unit of work).
 *
 * Eligibility at the command level is the COARSE postable set: a guild with a
 * calendar channel that is not paused. The publisher re-checks every gate
 * (publishing_enabled MEM918, paused, no-calendar-channel) as defense-in-depth
 * inside the job, so a guild paused between dispatch and execution is still
 * safely skipped with a structured log. The command's own coarse filter only
 * exists to keep the dispatch loop fast and the dry-run output meaningful — it
 * is NOT the authority on eligibility.
 *
 * Supports:
 *   - `--dry-run`  — list the guilds that would be dispatched without enqueuing
 *   - `--guild=`   — target a single guild by its primary key (UUID) for
 *                    manual re-runs / smoke tests (bypasses the postable filter;
 *                    the publisher still gates the targeted guild).
 */
class PublishDiscordDigest extends Command
{
    protected $signature = 'discord:publish-digest
                            {--dry-run : Show which guilds would be dispatched without enqueuing jobs}
                            {--guild= : Target a single guild by its primary key (UUID)}';

    protected $description = 'Dispatch a PublishDiscordDigest job per postable guild (daily two-week calendar digest)';

    public function handle(): int
    {
        // MEM918 master gate — the command is inert until publishing is enabled.
        // The publisher re-checks this inside each job as defense-in-depth.
        if (! (bool) config('services.discord.publishing_enabled', false)) {
            $this->info('Discord publishing is disabled (services.discord.publishing_enabled). Nothing to dispatch.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $targetGuildId = $this->option('guild');

        Log::info('discord_digest.command.started', [
            'dry_run' => $dryRun,
            'target_guild_id' => $targetGuildId,
        ]);

        $this->info($dryRun ? 'Dry run: listing guilds that would be dispatched...' : 'Dispatching digest jobs...');

        $dispatched = 0;
        $errors = 0;

        if ($targetGuildId !== null) {
            // Single-guild targeted run — bypass the postable filter so an
            // operator can force a re-run; the publisher still gates the guild.
            $guild = DiscordGuild::find($targetGuildId);
            if (! $guild) {
                $this->error("Guild {$targetGuildId} not found.");

                return self::FAILURE;
            }

            if ($dryRun) {
                $this->line("  Would dispatch digest job for guild {$guild->id} ({$guild->name})");

                return self::SUCCESS;
            }

            try {
                PublishDiscordDigestJob::dispatch($guild->id);
                $dispatched++;
                $this->line("  Dispatched digest job for guild {$guild->id} ({$guild->name})");
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('discord_digest.command.dispatch_failed', [
                    'guild_id' => $guild->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  Failed to dispatch digest job for guild {$guild->id}: {$e->getMessage()}");
            }
        } else {
            // Coarse postable set: a calendar channel and not paused. The
            // publisher re-checks every gate inside the job.
            DiscordGuild::query()
                ->whereNotNull('calendar_channel_id')
                ->where('calendar_channel_id', '!=', '')
                ->where('paused', false)
                ->chunkById(200, function ($guilds) use ($dryRun, &$dispatched, &$errors) {
                    foreach ($guilds as $guild) {
                        try {
                            if ($dryRun) {
                                $this->line("  Would dispatch digest job for guild {$guild->id} ({$guild->name})");
                                $dispatched++;

                                continue;
                            }

                            PublishDiscordDigestJob::dispatch($guild->id);
                            $dispatched++;
                        } catch (\Throwable $e) {
                            $errors++;
                            Log::warning('discord_digest.command.dispatch_failed', [
                                'guild_id' => $guild->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });
        }

        $noun = $dryRun ? 'would be dispatched' : 'dispatched';
        $this->info("Digest complete: {$dispatched} job(s) {$noun}, {$errors} error(s).");

        Log::info('discord_digest.command.completed', [
            'dispatched' => $dispatched,
            'errors' => $errors,
            'dry_run' => $dryRun,
            'target_guild_id' => $targetGuildId,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
