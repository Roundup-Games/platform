<?php

namespace App\Jobs;

use App\Services\DashboardCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Async job that warms the trending-nearby cache for a geohash tile.
 *
 * Computes trending games within the tile's bounding box:
 *   - status = scheduled
 *   - date_time within next 14 days
 *   - sorted by (confirmed participant count DESC, created_at DESC)
 *   - top 5 results stored as serializable arrays
 *
 * Triggered by: trending cache miss, game event in tile, scheduled sweep.
 *
 * Follows the same pattern as WarmDashboardCache — ShouldBeUnique per tile
 * so duplicate jobs don't stack up for the same geohash.
 */
class WarmTrendingNearby implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum retry attempts before marking as failed.
     */
    public int $tries = 3;

    /**
     * Maximum time the job may run before timing out.
     */
    public int $timeout = 60;

    /**
     * Discard the job on failure after all retries exhausted.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Unique key per geohash tile — prevents duplicate warm jobs stacking up.
     */
    public function uniqueId(): string
    {
        return $this->geohash4;
    }

    /**
     * @param  string  $geohash4  The 4-character geohash tile prefix to warm.
     * @param  string  $triggerType  What triggered this warm (e.g. 'cache_miss', 'game_event', 'sweep').
     */
    public function __construct(
        public string $geohash4,
        public string $triggerType = 'cache_miss',
    ) {
        $this->onQueue('discovery');
    }

    /**
     * Execute the job.
     */
    public function handle(DashboardCacheService $cacheService): void
    {
        $startedAt = now();

        Log::info('dashboard.warm_trending.started', [
            'geohash_4' => $this->geohash4,
            'trigger_type' => $this->triggerType,
        ]);

        $resultCount = $cacheService->warmTrendingNearby($this->geohash4);

        $durationMs = $startedAt->diffInMilliseconds(now());

        Log::info('dashboard.warm_trending.completed', [
            'geohash_4' => $this->geohash4,
            'trigger_type' => $this->triggerType,
            'duration_ms' => $durationMs,
            'result_count' => $resultCount,
        ]);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('dashboard.warm_trending.failed', [
            'geohash_4' => $this->geohash4,
            'trigger_type' => $this->triggerType,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
