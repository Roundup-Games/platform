<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\DashboardCacheService;
use App\Services\Geohash;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Async job that warms dashboard cache sections for a user.
 *
 * Triggered by: cache_miss (any section), location_change, game_event.
 *
 * Computes contributions, feed, and opportunities in the background,
 * writing results to cache so subsequent dashboard loads are fast.
 * Follows the same pattern as UpdateUserDiscoveryCache.
 */
class WarmDashboardCache implements ShouldQueue, ShouldBeUnique
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
    public int $timeout = 120;

    /**
     * Discard the job on failure after all retries exhausted.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Unique key per user — prevents duplicate cache warm jobs stacking up.
     */
    public function uniqueId(): string
    {
        return $this->userId;
    }

    /**
     * @param  string  $userId  The user whose dashboard cache to warm.
     * @param  string  $triggerType  What triggered this warm (e.g. 'cache_miss_week', 'sweep').
     */
    public function __construct(
        public string $userId,
        public string $triggerType,
    ) {
        $this->onQueue('discovery');
    }

    /**
     * Execute the job.
     */
    public function handle(DashboardCacheService $cacheService): void
    {
        $startedAt = now();

        Log::info('dashboard.warm.started', [
            'user_id' => $this->userId,
            'trigger_type' => $this->triggerType,
        ]);

        $user = User::find($this->userId);

        // Fail gracefully if user was deleted between dispatch and execution
        if (! $user) {
            Log::warning('dashboard.warm.user_not_found', [
                'user_id' => $this->userId,
                'trigger_type' => $this->triggerType,
            ]);

            return;
        }

        $itemCounts = [];

        // Warm contributions
        $contributions = $cacheService->warmContributions($user);
        $itemCounts['contributions'] = count($contributions);

        // Warm feed
        $feed = $cacheService->warmFeed($user);
        $itemCounts['feed'] = count($feed['items'] ?? []);

        // Warm recaps
        $recaps = $cacheService->warmRecaps($user);
        $itemCounts['recaps'] = count($recaps);

        // Warm opportunities (requires location)
        $location = $user->linkedLocation;
        if ($location && $location->latitude && $location->longitude) {
            $geohash4 = Geohash::tilePrefix(
                (float) $location->latitude,
                (float) $location->longitude,
                4,
            );

            $opportunities = $cacheService->warmOpportunities($user, $geohash4);
            $itemCounts['opportunities'] = $opportunities['total_available'] ?? 0;
        } else {
            $itemCounts['opportunities'] = 0;
        }

        $durationMs = $startedAt->diffInMilliseconds(now());

        Log::info('dashboard.warm.completed', [
            'user_id' => $this->userId,
            'trigger_type' => $this->triggerType,
            'duration_ms' => $durationMs,
            'item_counts' => $itemCounts,
        ]);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('dashboard.warm.failed', [
            'user_id' => $this->userId,
            'trigger_type' => $this->triggerType,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
