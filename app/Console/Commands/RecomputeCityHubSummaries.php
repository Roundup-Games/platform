<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Services\CityDirectoryService;
use App\Services\SeoCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled city-hub recompute (M062 / 62-04-T07).
 *
 * Keeps the city-hub surfaces fresh between observer flushes: per-request
 * caching (62-01) rides a TTL and the CityHubCacheObserver (62-03-T04)
 * covers only Game/Event/Location/City saves — drift that bypasses model
 * events (mass updates, campaign-session changes, threshold edits) would
 * otherwise linger in the per-city summary caches up to the 900s TTL and
 * in the cached sitemap up to 30 minutes.
 *
 * For every known city slug: forget() the cached resolution, then
 * resolveCity() to re-warm it with fresh counts. Negative resolutions
 * (unknown, ambiguous, hidden) are sentinel-cached by the same call — a
 * hidden curated city costs one cached lookup and stays hidden everywhere
 * (the hub guard, the sitemap, the rail) because curation is enforced
 * inside the resolution. Curated City rows get their snapshot columns
 * (upcoming_activity_count, verified_venues_count, recomputed_at) updated
 * from the freshly computed summary via a surgical column-targeted mass
 * update — no model events, so it cannot re-flush the cache this command
 * just warmed, and no hydrate-and-save, so concurrent admin curation edits
 * (intro, featured, region_prefix) are never overwritten with stale
 * in-memory attributes.
 *
 * Finishes by invalidating the cities sub-sitemap and the sitemap index so
 * qualifying-set changes (a city crossing a threshold) surface on the next
 * crawl, and logs a structured cityhubs.recomputed line for ops.
 *
 * Usage:
 *   php artisan cityhubs:recompute
 */
class RecomputeCityHubSummaries extends Command
{
    protected $signature = 'cityhubs:recompute';

    protected $description = 'Re-warm every known city summary cache, refresh curated-city snapshot columns, and invalidate the cities sitemap';

    public function handle(): int
    {
        $startedAt = now();

        $cityDirectory = app(CityDirectoryService::class);

        $slugs = $cityDirectory->citySlugs();

        // Curated rows keyed by slug up front — one query instead of one
        // per slug, and a stable in-memory row per city for the loop.
        $curated = City::query()
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        $snapshots = 0;

        foreach ($slugs as $slug) {
            $cityDirectory->forget($slug);

            $summary = $cityDirectory->resolveCity($slug);

            $city = $curated->get($slug);

            // No summary (unknown/ambiguous/hidden) or no curated row:
            // nothing to snapshot — the cache re-warm above is the whole job.
            if ($summary === null || $city === null) {
                continue;
            }

            City::query()->whereKey($city->getKey())->update([
                'upcoming_activity_count' => $summary->upcomingActivityCount(),
                'verified_venues_count' => $summary->verifiedVenuesCount,
                'recomputed_at' => now(),
            ]);

            $snapshots++;
        }

        app(SeoCacheService::class)->forgetSitemap('cities');
        app(SeoCacheService::class)->forgetIndex();

        $durationMs = (int) $startedAt->diffInMilliseconds(now());

        Log::info('cityhubs.recomputed', [
            'cities' => $slugs->count(),
            'snapshots' => $snapshots,
            'duration_ms' => $durationMs,
        ]);

        $this->info(
            "Recomputed {$slugs->count()} city summary cache(s), "
            ."updated {$snapshots} snapshot(s), cleared the cities sitemap in {$durationMs}ms."
        );

        return self::SUCCESS;
    }
}
