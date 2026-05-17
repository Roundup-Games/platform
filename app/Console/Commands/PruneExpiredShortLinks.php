<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Enums\EventStatus;
use App\Enums\GameStatus;
use App\Models\ShortLink;
use App\Models\ShortLinkHit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PruneExpiredShortLinks extends Command
{
    protected $signature = 'short-links:prune
                            {--dry-run : Show what would be done without making changes}
                            {--days=90 : Remove analytics hits older than N days}
                            {--grace=7 : Grace period in days after entity completion}';

    protected $description = 'Expire links for completed entities, soft-delete expired links, and hard-delete old analytics hits';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = (int) $this->option('days');
        $grace = (int) $this->option('grace');

        if ($days < 1) {
            $this->error('The --days option must be at least 1.');

            return self::FAILURE;
        }

        if ($grace < 0) {
            $this->error('The --grace option must be non-negative.');

            return self::FAILURE;
        }

        try {
            // Phase 1: Entity-driven expiry — set expires_at on links whose entity is completed/cancelled
            $expiredCount = $this->expireLinksForCompletedEntities($grace, $dryRun);

            // Phase 2: Soft-delete expired links
            $deletedCount = $this->softDeleteExpiredLinks($dryRun);

            // Phase 3: Hard-delete old analytics hits
            $hitsDeleted = $this->pruneOldAnalyticsHits($days, $dryRun);

            $this->info("Phase 1 (entity expiry): {$expiredCount} link(s) " . ($dryRun ? 'would be ' : '') . "marked for expiry");
            $this->info("Phase 2 (expired cleanup): {$deletedCount} link(s) " . ($dryRun ? 'would be ' : '') . "soft-deleted");
            $this->info("Phase 3 (analytics retention): {$hitsDeleted} hit(s) " . ($dryRun ? 'would be ' : '') . "hard-deleted (older than {$days} days)");

            Log::channel('daily')->info('prune.expired_links', [
                'entity_expiry_count' => $expiredCount,
                'soft_deleted_count' => $deletedCount,
                'analytics_deleted_count' => $hitsDeleted,
                'grace_days' => $grace,
                'retention_days' => $days,
                'dry_run' => $dryRun,
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Prune failed: {$e->getMessage()}");

            Log::channel('daily')->error('prune.expired_links.failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * Phase 1: Set expires_at on links whose linkable entity has been completed/cancelled
     * for longer than the grace period and that don't already have an expiry set.
     */
    protected function expireLinksForCompletedEntities(int $graceDays, bool $dryRun): int
    {
        $cutoff = now()->subDays($graceDays);

        // Use explicit subqueries with CAST(id AS VARCHAR) to handle the type
        // mismatch between integer entity PKs and the string linkable_id column.
        // This scales to millions of rows without OOM risk and works correctly
        // with PostgreSQL's strict type checking.
        $query = ShortLink::query()
            ->whereNull('expires_at')
            ->where(function ($q) use ($cutoff) {
                $q->orWhere(function ($subQ) use ($cutoff) {
                    $subQ->where('linkable_type', \App\Models\Game::class)
                        ->whereIn('linkable_id', function ($sub) use ($cutoff) {
                            $sub->selectRaw('CAST(id AS VARCHAR)')
                                ->from('games')
                                ->whereIn('status', [GameStatus::Completed->value, GameStatus::Canceled->value])
                                ->where('updated_at', '<', $cutoff);
                        });
                });
                $q->orWhere(function ($subQ) use ($cutoff) {
                    $subQ->where('linkable_type', \App\Models\Campaign::class)
                        ->whereIn('linkable_id', function ($sub) use ($cutoff) {
                            $sub->selectRaw('CAST(id AS VARCHAR)')
                                ->from('campaigns')
                                ->whereIn('status', [CampaignStatus::Completed->value, CampaignStatus::Cancelled->value])
                                ->where('updated_at', '<', $cutoff);
                        });
                });
                $q->orWhere(function ($subQ) use ($cutoff) {
                    $subQ->where('linkable_type', \App\Models\Event::class)
                        ->whereIn('linkable_id', function ($sub) use ($cutoff) {
                            $sub->selectRaw('CAST(id AS VARCHAR)')
                                ->from('events')
                                ->whereIn('status', [EventStatus::Completed->value, EventStatus::Cancelled->value])
                                ->where('updated_at', '<', $cutoff);
                        });
                });
            });

        if ($dryRun) {
            return $query->count();
        }

        // Chunked processing to avoid loading all matching links at once.
        // Bulk update per chunk is efficient; cache invalidation follows each chunk.
        $count = 0;
        $query->chunkById(500, function ($links) use (&$count) {
            $ids = $links->pluck('id');
            $count += ShortLink::whereIn('id', $ids)->update(['expires_at' => now()]);

            foreach ($links as $link) {
                Cache::forget("short_link:{$link->code}");
                Cache::forget("short_link_id:{$link->id}");
            }
        });

        return $count;
    }

    /**
     * Phase 2: Soft-delete all links where expires_at has passed.
     */
    protected function softDeleteExpiredLinks(bool $dryRun): int
    {
        $query = ShortLink::query()
            ->where('expires_at', '<', now());

        if ($dryRun) {
            return $query->count();
        }

        $count = 0;
        $query->chunkById(500, function ($links) use (&$count) {
            foreach ($links as $link) {
                Cache::forget("short_link:{$link->code}");
                Cache::forget("short_link_id:{$link->id}");
                $link->delete();
                $count++;
            }
        });

        return $count;
    }

    /**
     * Phase 3: Hard-delete analytics hits older than the retention period.
     */
    protected function pruneOldAnalyticsHits(int $days, bool $dryRun): int
    {
        $cutoff = now()->subDays($days);

        if ($dryRun) {
            return ShortLinkHit::where('hit_at', '<', $cutoff)->count();
        }

        $count = 0;
        ShortLinkHit::where('hit_at', '<', $cutoff)
            ->chunkById(500, function ($hits) use (&$count) {
                // ShortLinkHit has no model events — bulk DELETE is safe and
                // generates one statement per chunk instead of 500 individual deletes.
                $count += ShortLinkHit::whereIn('id', $hits->pluck('id'))->delete();
            });

        return $count;
    }
}
