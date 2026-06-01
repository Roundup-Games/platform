<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\DashboardCacheService;
use App\Services\DashboardModeService;
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
     * Delay between retry attempts (seconds).
     * Exponential backoff to avoid hammering a transiently-failing service.
     */
    public array $backoff = [30, 60, 120];

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
    public function handle(DashboardCacheService $cacheService, DashboardModeService $modeService): void
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

        // ── Existing sections ──────────────────────────

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
        $geohash4 = null;
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

        // ── Two-mode sections ──────────────────────────
        // Resolve mode to conditionally warm newcomer vs established sections.

        $mode = $modeService->resolve($user);

        Log::info('dashboard.warm.mode_resolved', [
            'user_id' => $this->userId,
            'mode' => $mode,
        ]);

        // ── Shared sections ────────────────────────────
        $actionCenter = $cacheService->warmActionCenter($user);
        $itemCounts['action_center'] = count($actionCenter);

        if ($mode === 'newcomer') {
            // Newcomer sections
            $newcomerWelcome = $cacheService->warmNewcomerWelcome($user);
            $itemCounts['newcomer_welcome'] = count($newcomerWelcome);

            $progressTracker = $cacheService->warmProgressTracker($user);
            $itemCounts['progress_tracker'] = count($progressTracker);

            // Nearby people (requires location)
            if ($geohash4 !== null) {
                $nearbyPeople = $cacheService->warmNearbyPeople($user, $geohash4);
                $itemCounts['nearby_people'] = count($nearbyPeople);

                $newcomerMatches = $cacheService->warmNewcomerMatches($user, $geohash4);
                $itemCounts['newcomer_matches'] = $newcomerMatches['total_nearby'] ?? 0;
            } else {
                $itemCounts['nearby_people'] = 0;
                $itemCounts['newcomer_matches'] = 0;
            }

            // Skip established-only sections
            $itemCounts['host_again'] = 0;
            $itemCounts['milestone_cards'] = 0;
        } else {
            // Established sections
            $hostAgain = $cacheService->warmHostAgain($user);
            $itemCounts['host_again'] = count($hostAgain);

            $milestoneCards = $cacheService->warmMilestoneCards($user);
            $itemCounts['milestone_cards'] = count($milestoneCards);

            // Nearby people (requires location) — shown in both modes
            if ($geohash4 !== null) {
                $nearbyPeople = $cacheService->warmNearbyPeople($user, $geohash4);
                $itemCounts['nearby_people'] = count($nearbyPeople);
            } else {
                $itemCounts['nearby_people'] = 0;
            }

            // Skip newcomer-only sections
            $itemCounts['newcomer_welcome'] = 0;
            $itemCounts['progress_tracker'] = 0;
        }

        $durationMs = $startedAt->diffInMilliseconds(now());

        Log::info('dashboard.warm.completed', [
            'user_id' => $this->userId,
            'trigger_type' => $this->triggerType,
            'mode' => $mode,
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
