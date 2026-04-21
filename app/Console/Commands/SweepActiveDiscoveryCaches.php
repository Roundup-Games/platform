<?php

namespace App\Console\Commands;

use App\Jobs\UpdateUserDiscoveryCache;
use App\Models\NearbyDiscoveryView;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Periodic sweep that recomputes discovery caches for users who recently
 * viewed the Nearby tab.
 *
 * Targets only active users (recent nearby_discovery_views) and skips those
 * whose cached geohash_4 still matches their current location's geohash_4,
 * avoiding redundant cache recomputation for users who haven't moved.
 */
class SweepActiveDiscoveryCaches extends Command
{
    protected $signature = 'discovery:sweep-active
                            {--window=60 : Lookback window in minutes}
                            {--dry-run : List qualifying users without dispatching jobs}';

    protected $description = 'Recompute discovery caches for users who recently viewed the Nearby tab';

    public function handle(): int
    {
        $window = (int) $this->option('window');
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = now();

        $this->info("Starting discovery sweep (window: {$window}m" . ($dryRun ? ', dry-run' : '') . ')');
        Log::info('discovery.sweep.started', [
            'window_minutes' => $window,
            'dry_run' => $dryRun,
        ]);

        // Build the query: active discovery views joined through users → locations
        // to compare cached geohash_4 vs current location geohash_4.
        $baseQuery = NearbyDiscoveryView::query()
            ->where('last_discovery_view', '>=', now()->subMinutes($window))
            ->whereHas('user', function ($query) {
                $query->where('profile_complete', true)
                    ->whereNotNull('location_id');
            });

        // Count total qualifying active users (before geohash filtering)
        $totalActive = (clone $baseQuery)->count();
        $this->info("Found {$totalActive} users with recent discovery views.");

        if ($totalActive === 0) {
            $this->info('No active users to sweep.');

            return self::SUCCESS;
        }

        $dispatchCount = 0;
        $skipCount = 0;
        $failCount = 0;

        // Use chunkById for memory safety on large result sets.
        // We need to load user + location to compare geohashes, so we
        // eager-load the relationship chain.
        (clone $baseQuery)
            ->with('user.linkedLocation')
            ->chunkById(100, function ($views) use ($dryRun, &$dispatchCount, &$skipCount, &$failCount) {
                foreach ($views as $view) {
                    $user = $view->user;

                    if (! $user) {
                        continue;
                    }

                    $location = $user->linkedLocation;

                    if (! $location) {
                        $skipCount++;

                        continue;
                    }

                    // Optimization: skip if the cached geohash matches current location.
                    // Only sweep when location has changed (or no prior cache).
                    $cachedGeohash = $view->geohash_4;
                    $currentGeohash = $location->geohash_4;

                    if ($cachedGeohash && $currentGeohash && $cachedGeohash === $currentGeohash) {
                        $skipCount++;

                        continue;
                    }

                    if ($dryRun) {
                        $this->line("  Would dispatch for user {$user->id}" .
                            " (cached: {$cachedGeohash}, current: {$currentGeohash})");
                        $dispatchCount++;

                        continue;
                    }

                    try {
                        UpdateUserDiscoveryCache::dispatch($user->id, 'sweep');
                        $dispatchCount++;
                    } catch (\Throwable $e) {
                        $failCount++;
                        Log::error('discovery.sweep.dispatch_failed', [
                            'user_id' => $user->id,
                            'exception' => $e->getMessage(),
                        ]);
                        $this->warn("  Failed to dispatch for user {$user->id}: {$e->getMessage()}");
                    }
                }
            });

        $durationMs = $startedAt->diffInMilliseconds(now());

        $this->newLine();
        $this->info("Sweep complete.");
        $this->info("  Active users: {$totalActive}");
        $this->info("  " . ($dryRun ? 'Would dispatch' : 'Dispatched') . ": {$dispatchCount}");
        $this->info("  Skipped (location unchanged): {$skipCount}");

        if ($failCount > 0) {
            $this->warn("  Dispatch failures: {$failCount}");
        }

        $this->info("  Duration: {$durationMs}ms");

        Log::info('discovery.sweep.completed', [
            'user_count' => $totalActive,
            'job_dispatch_count' => $dispatchCount,
            'skip_count' => $skipCount,
            'fail_count' => $failCount,
            'duration_ms' => $durationMs,
            'dry_run' => $dryRun,
            'window_minutes' => $window,
        ]);

        return self::SUCCESS;
    }
}
