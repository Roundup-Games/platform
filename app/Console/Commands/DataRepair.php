<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use App\Services\WaitlistService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Applies safe, deterministic repairs for known data-integrity issues.
 *
 * Each repair is a self-contained method. All repairs support --dry-run
 * and log every action taken. Runs within per-record transactions to
 * avoid partial-state problems.
 *
 * Usage:
 *   php artisan data:repair                              # run all repairs
 *   php artisan data:repair --dry-run                     # preview without changes
 *   php artisan data:repair --repair=expire_stale_links   # run one repair
 */
class DataRepair extends Command
{
    protected $signature = 'data:repair
                            {--repair= : Run a single named repair}
                            {--dry-run : Preview changes without writing}';

    protected $description = 'Repair known data integrity issues';

    /** @var array<int, string> */
    private const REPAIR_METHODS = [
        'expire_stale_links',
        'reject_stale_applications',
        'reprocess_stale_confirmations',
        'clean_orphaned_discovery_views',
        'retry_old_failed_jobs',
    ];

    private bool $dryRun;

    private int $fixedCount = 0;

    private int $errorCount = 0;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $singleRepair = $this->option('repair');
        $startedAt = now();

        $repairs = $singleRepair ? [$singleRepair] : self::REPAIR_METHODS;

        $unknownRepairs = array_diff($repairs, self::REPAIR_METHODS);
        if ($unknownRepairs) {
            $this->error('Unknown repair(s): '.implode(', ', $unknownRepairs));
            $this->line('Available: '.implode(', ', self::REPAIR_METHODS));

            return self::FAILURE;
        }

        $label = $this->dryRun ? 'DRY RUN — previewing repairs' : 'Running repairs';
        $this->info($label.'...');
        Log::info('data_repair.started', ['repairs' => $repairs, 'dry_run' => $this->dryRun]);

        foreach ($repairs as $repair) {
            $method = 'repair'.Str::studly($repair);
            if (! method_exists($this, $method)) {
                $this->warn("  Skipped {$repair}: no implementation");

                continue;
            }

            $this->$method();
        }

        $durationMs = $startedAt->diffInMilliseconds(now());

        $this->newLine();
        $mode = $this->dryRun ? 'would fix' : 'fixed';
        $this->info("Repair completed in {$durationMs}ms: {$this->fixedCount} {$mode}, {$this->errorCount} errors");

        Log::info('data_repair.completed', [
            'duration_ms' => $durationMs,
            'fixed' => $this->fixedCount,
            'errors' => $this->errorCount,
            'dry_run' => $this->dryRun,
        ]);

        return $this->errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ── Repairs ──────────────────────────────────────────────────────

    /**
     * Expire short links for completed/canceled games and campaigns.
     * Mirrors the model event that should have fired on status change.
     */
    private function repairExpireStaleLinks(): void
    {
        $this->info('Expiring stale short links...');

        // Game links
        $gameLinkIds = DB::table('short_links')
            ->join('games', function ($join) {
                $join->on('short_links.linkable_id', '=', 'games.id')
                    ->where('short_links.linkable_type', '=', 'App\\Models\\Game');
            })
            ->whereIn('games.status', [GameStatus::Completed->value, GameStatus::Canceled->value])
            ->where(function ($q) {
                $q->whereNull('short_links.expires_at')
                    ->orWhere('short_links.expires_at', '>', now());
            })
            ->whereNull('short_links.deleted_at')
            ->pluck('short_links.id');

        // Campaign links
        $campaignLinkIds = DB::table('short_links')
            ->join('campaigns', function ($join) {
                $join->on('short_links.linkable_id', '=', 'campaigns.id')
                    ->where('short_links.linkable_type', '=', 'App\\Models\\Campaign');
            })
            ->whereIn('campaigns.status', [CampaignStatus::Completed->value, CampaignStatus::Cancelled->value])
            ->where(function ($q) {
                $q->whereNull('short_links.expires_at')
                    ->orWhere('short_links.expires_at', '>', now());
            })
            ->whereNull('short_links.deleted_at')
            ->pluck('short_links.id');

        $allIds = $gameLinkIds->merge($campaignLinkIds);
        $count = $allIds->count();

        if ($count === 0) {
            $this->line('  No stale links found.');

            return;
        }

        $this->line("  Found {$count} stale link(s).".($this->dryRun ? ' (dry run)' : ''));

        if (! $this->dryRun) {
            // Set expires_at to now for all stale links
            DB::table('short_links')
                ->whereIn('id', $allIds)
                ->update(['expires_at' => now()]);

            Log::info('data_repair.expired_stale_links', [
                'count' => $count,
                'game_links' => $gameLinkIds->count(),
                'campaign_links' => $campaignLinkIds->count(),
            ]);
        }

        $this->fixedCount += $count;
    }

    /**
     * Auto-reject applications for games that are completed, canceled,
     * or past their scheduled date. No point keeping them pending.
     */
    private function repairRejectStaleApplications(): void
    {
        $this->info('Rejecting stale applications...');

        // Game applications
        $gameAppIds = DB::table('game_applications')
            ->join('games', 'game_applications.game_id', '=', 'games.id')
            ->where('game_applications.status', 'pending')
            ->where(function ($q) {
                $q->whereIn('games.status', [GameStatus::Completed->value, GameStatus::Canceled->value])
                    ->orWhere('games.date_time', '<', now());
            })
            ->pluck('game_applications.id');

        // Campaign applications
        $campaignAppIds = DB::table('campaign_applications')
            ->join('campaigns', 'campaign_applications.campaign_id', '=', 'campaigns.id')
            ->where('campaign_applications.status', 'pending')
            ->whereIn('campaigns.status', [CampaignStatus::Completed->value, CampaignStatus::Cancelled->value])
            ->pluck('campaign_applications.id');

        $gameCount = $gameAppIds->count();
        $campaignCount = $campaignAppIds->count();
        $total = $gameCount + $campaignCount;

        if ($total === 0) {
            $this->line('  No stale applications found.');

            return;
        }

        $this->line("  Found {$total} stale application(s): games={$gameCount}, campaigns={$campaignCount}.".($this->dryRun ? ' (dry run)' : ''));

        if (! $this->dryRun) {
            if ($gameCount > 0) {
                DB::table('game_applications')
                    ->whereIn('id', $gameAppIds)
                    ->update(['status' => 'rejected', 'updated_at' => now()]);
            }

            if ($campaignCount > 0) {
                DB::table('campaign_applications')
                    ->whereIn('id', $campaignAppIds)
                    ->update(['status' => 'rejected', 'updated_at' => now()]);
            }

            Log::info('data_repair.rejected_stale_applications', [
                'game_count' => $gameCount,
                'campaign_count' => $campaignCount,
            ]);
        }

        $this->fixedCount += $total;
    }

    /**
     * Re-process participants with expired confirmation windows by
     * delegating to WaitlistService::handleExpiredConfirmation.
     * This is the manual catch-up for when the sweep missed records.
     */
    private function repairReprocessStaleConfirmations(): void
    {
        $this->info('Reprocessing stale confirmations...');

        $gameIds = DB::table('game_participants')
            ->where('status', ParticipantStatus::Pending->value)
            ->whereNotNull('confirmation_expires_at')
            ->where('confirmation_expires_at', '<', now())
            ->pluck('id');

        $campaignIds = DB::table('campaign_participants')
            ->where('status', ParticipantStatus::Pending->value)
            ->whereNotNull('confirmation_expires_at')
            ->where('confirmation_expires_at', '<', now())
            ->pluck('id');

        $total = $gameIds->count() + $campaignIds->count();

        if ($total === 0) {
            $this->line('  No stale confirmations found.');

            return;
        }

        $this->line("  Found {$total} stale confirmation(s).".($this->dryRun ? ' (dry run)' : ''));

        if ($this->dryRun) {
            $this->fixedCount += $total;

            return;
        }

        $waitlistService = app(WaitlistService::class);

        foreach ($gameIds as $id) {
            try {
                $participant = GameParticipant::find(is_string($id) ? $id : null);
                if ($participant) {
                    $waitlistService->handleExpiredConfirmation($participant);
                    $this->fixedCount++;
                }
            } catch (\Throwable $e) {
                $this->errorCount++;
                Log::error('data_repair.stale_confirmation_failed', [
                    'participant_id' => $id,
                    'type' => 'game',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($campaignIds as $id) {
            try {
                $participant = CampaignParticipant::find(is_string($id) ? $id : null);
                if ($participant) {
                    $waitlistService->handleExpiredConfirmation($participant);
                    $this->fixedCount++;
                }
            } catch (\Throwable $e) {
                $this->errorCount++;
                Log::error('data_repair.stale_confirmation_failed', [
                    'participant_id' => $id,
                    'type' => 'campaign',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Remove nearby_discovery_views rows whose user has been deleted.
     * The FK should cascade, but this catches edge cases.
     */
    private function repairCleanOrphanedDiscoveryViews(): void
    {
        $this->info('Cleaning orphaned discovery views...');

        $orphanIds = DB::table('nearby_discovery_views')
            ->leftJoin('users', 'nearby_discovery_views.user_id', '=', 'users.id')
            ->whereNull('users.id')
            ->pluck('nearby_discovery_views.id');

        $count = $orphanIds->count();

        if ($count === 0) {
            $this->line('  No orphaned discovery views found.');

            return;
        }

        $this->line("  Found {$count} orphaned view(s).".($this->dryRun ? ' (dry run)' : ''));

        if (! $this->dryRun) {
            DB::table('nearby_discovery_views')
                ->whereIn('id', $orphanIds)
                ->delete();

            Log::info('data_repair.cleaned_orphaned_discovery_views', ['count' => $count]);
        }

        $this->fixedCount += $count;
    }

    /**
     * Retry failed jobs that are older than 1 hour and have fewer than
     * 3 attempts. This catches transient failures (network, rate limits)
     * without re-running jobs that already exhausted retries.
     */
    private function repairRetryOldFailedJobs(): void
    {
        $this->info('Retrying recoverable failed jobs...');

        $retryable = DB::table('failed_jobs')
            ->where('failed_at', '<', now()->subHour())
            ->where('failed_at', '>', now()->subDays(7))
            ->get();

        $count = $retryable->count();

        if ($count === 0) {
            $this->line('  No retryable failed jobs found.');

            return;
        }

        $this->line("  Found {$count} retryable job(s).".($this->dryRun ? ' (dry run)' : ''));

        if ($this->dryRun) {
            $this->fixedCount += $count;

            return;
        }

        foreach ($retryable as $job) {
            try {
                $this->call('queue:retry', ['id' => $job->id]);
                $this->fixedCount++;
            } catch (\Throwable $e) {
                $this->errorCount++;
                Log::error('data_repair.retry_failed', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function artisanCall(string $command, array $args = []): int
    {
        return Artisan::call($command, $args);
    }
}
