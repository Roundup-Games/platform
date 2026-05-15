<?php

namespace App\Console\Commands;

use App\Models\ShortLink;
use App\Models\ShortLinkHit;
use Illuminate\Console\Command;
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

        // Collect IDs of completed/cancelled entities
        $completedGameIds = \App\Models\Game::whereIn('status', ['completed', 'canceled'])
            ->where('updated_at', '<', $cutoff)
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        $completedCampaignIds = \App\Models\Campaign::whereIn('status', ['completed', 'cancelled'])
            ->where('updated_at', '<', $cutoff)
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        $completedEventIds = \App\Models\Event::whereIn('status', ['completed', 'cancelled'])
            ->where('updated_at', '<', $cutoff)
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        // Query links without expires_at whose linkable entity is completed
        // If no completed entities exist past the grace period, skip entirely
        $hasCompletedEntities = $completedGameIds->isNotEmpty()
            || $completedCampaignIds->isNotEmpty()
            || $completedEventIds->isNotEmpty();

        if (! $hasCompletedEntities) {
            return 0;
        }

        $query = ShortLink::query()
            ->whereNull('expires_at')
            ->where(function ($q) use ($completedGameIds, $completedCampaignIds, $completedEventIds) {
                if ($completedGameIds->isNotEmpty()) {
                    $q->orWhere(function ($subQ) use ($completedGameIds) {
                        $subQ->where('linkable_type', \App\Models\Game::class)
                            ->whereIn('linkable_id', $completedGameIds);
                    });
                }
                if ($completedCampaignIds->isNotEmpty()) {
                    $q->orWhere(function ($subQ) use ($completedCampaignIds) {
                        $subQ->where('linkable_type', \App\Models\Campaign::class)
                            ->whereIn('linkable_id', $completedCampaignIds);
                    });
                }
                if ($completedEventIds->isNotEmpty()) {
                    $q->orWhere(function ($subQ) use ($completedEventIds) {
                        $subQ->where('linkable_type', \App\Models\Event::class)
                            ->whereIn('linkable_id', $completedEventIds);
                    });
                }
            });

        $count = $dryRun ? $query->count() : $query->update(['expires_at' => now()]);

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
                foreach ($hits as $hit) {
                    $hit->delete();
                    $count++;
                }
            });

        return $count;
    }
}
