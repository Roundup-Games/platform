<?php

namespace App\Jobs;

use App\Models\NearbyDiscoveryView;
use App\Models\User;
use App\Services\Geohash;
use App\Services\PeopleDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Async job that populates the nearby-people discovery cache for a user.
 *
 * Triggered by: location_change, vibe_change, game_system_change, follow,
 * unfollow, block, unblock, sweep.
 *
 * The job does NOT return results — it writes to cache as a side effect.
 */
class UpdateUserDiscoveryCache implements ShouldBeUnique, ShouldQueue
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
     * Unique key per user — prevents duplicate cache updates stacking up.
     */
    public function uniqueId(): string
    {
        return $this->userId;
    }

    /**
     * Discard the job on failure after all retries exhausted.
     * Prevents the failed_jobs table from growing unboundedly.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  string  $userId  The user whose discovery cache to refresh.
     * @param  string  $triggerType  What triggered this refresh (e.g. 'location_change', 'sweep').
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
    public function handle(PeopleDiscoveryService $discovery): void
    {
        $startedAt = now();
        Log::info('discovery.job.started', [
            'user_id' => $this->userId,
            'trigger_type' => $this->triggerType,
        ]);

        $user = User::find($this->userId);

        // Fail gracefully if user was deleted between dispatch and execution
        if (! $user) {
            Log::warning('discovery.job.user_not_found', [
                'user_id' => $this->userId,
                'trigger_type' => $this->triggerType,
            ]);

            return;
        }

        // Skip users without a complete profile — they won't appear in results
        if (! $user->profile_complete) {
            Log::info('discovery.job.skipped_not_complete', [
                'user_id' => $this->userId,
                'trigger_type' => $this->triggerType,
            ]);

            return;
        }

        // Resolve user's location via linkedLocation relationship
        $location = $user->linkedLocation;

        if (! $location || ! $location->latitude || ! $location->longitude) {
            Log::info('discovery.job.skipped_no_location', [
                'user_id' => $this->userId,
                'trigger_type' => $this->triggerType,
            ]);

            return;
        }

        $lat = (float) $location->latitude;
        $lng = (float) $location->longitude;
        $geohash4 = Geohash::tilePrefix($lat, $lng, 4);

        // Delegate cache population to the service
        $scoredResults = $discovery->computeAndCache($user, $lat, $lng);
        $candidateCount = count($scoredResults);

        // Update the NearbyDiscoveryView tracking row with current geohash
        NearbyDiscoveryView::updateOrCreate(
            ['user_id' => $user->id],
            [
                'geohash_4' => $geohash4,
                'last_discovery_view' => now(),
            ],
        );

        $durationMs = $startedAt->diffInMilliseconds(now());

        Log::info('discovery.job.completed', [
            'user_id' => $this->userId,
            'trigger_type' => $this->triggerType,
            'candidate_count' => $candidateCount,
            'duration_ms' => $durationMs,
            'geohash_4' => $geohash4,
        ]);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('discovery.job.failed', [
            'user_id' => $this->userId,
            'trigger_type' => $this->triggerType,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
