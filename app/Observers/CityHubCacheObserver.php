<?php

namespace App\Observers;

use App\Models\City;
use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Services\CityDirectoryService;
use App\Services\SeoCacheService;
use Illuminate\Support\Facades\Log;

/**
 * Observes Game/Event/Location changes that can move a city hub's
 * qualifying content and invalidates the affected city summary caches
 * plus the cities sitemap and sitemap index (M062/62-03-T04).
 *
 * A single generic observer registered against all three models in
 * AppServiceProvider — Laravel's observe() dispatches by event name
 * (saved/deleted), never model-prefixed methods. Constructor DI like
 * GameObserver/SeoModelObserver.
 *
 * Invalidation is unconditional on save (no wasChanged narrowing): any
 * save of a session-bearing entity can move a city's counts, and the
 * flush is a handful of cache-key deletes — cheap and correctness-first.
 * Affected slugs always include BOTH the original and current city, since
 * a game/event can move between clusters and a location can rename or
 * move cities; a stale cluster would otherwise linger up to the 900s TTL
 * in its summary cache and 30 min in the cached sitemap.
 *
 * Scope limitation: Campaign membership flows through campaign-session
 * locations (a deeper chain), so campaigns deliberately ride the 900s
 * summary TTL — city summaries keep working, just eventually-consistent
 * for campaign-driven count changes.
 */
class CityHubCacheObserver
{
    public function __construct(
        private CityDirectoryService $cityDirectory,
        private SeoCacheService $seoCache,
    ) {}

    /**
     * Handle the "saved" event for any observed model.
     */
    public function saved(object $model): void
    {
        $this->invalidate($model);
    }

    /**
     * Handle the "deleted" event for any observed model. Model attributes
     * remain hydrated after delete, so slug derivation works the same as
     * on save.
     */
    public function deleted(object $model): void
    {
        $this->invalidate($model);
    }

    /**
     * Flush every affected city summary, then the cities sitemap and the
     * sitemap index (lastmod/entry sets both derive from the summaries).
     * No-ops cleanly when no slug is derivable — the sitemap flush still
     * fires, which is harmless: a model with no city simply cannot have
     * changed any hub's content.
     */
    private function invalidate(object $model): void
    {
        $slugs = $this->affectedCitySlugs($model);

        foreach ($slugs as $slug) {
            $this->cityDirectory->forget($slug);
        }

        $this->seoCache->forgetSitemap('cities');
        $this->seoCache->forgetIndex();

        Log::debug('cityhub.cache_invalidated', [
            'slugs' => $slugs,
            'source' => class_basename($model),
        ]);
    }

    /**
     * Unique non-empty registry slugs whose hub content this model can
     * move.
     *
     * Game/Event: the cities of BOTH the original and current location
     * (a session can move between clusters). Location: BOTH the original
     * and current registry row (a location can rename its city or move
     * clusters — CityRegistryService relinks on save and flushes the
     * freshly-linked side itself, so the union here is correct regardless
     * of listener order). City: BOTH the original and current slug — the
     * registry row IS the slug authority, already normalized. Unknown
     * model types: no slugs — the observer never throws on shape drift.
     *
     * @return array<int, string>
     */
    private function affectedCitySlugs(object $model): array
    {
        if ($model instanceof City) {
            return collect([$model->getOriginal('slug'), $model->slug])
                ->filter(fn ($slug): bool => is_string($slug) && $slug !== '')
                ->unique()
                ->values()
                ->all();
        }

        if ($model instanceof Location) {
            return City::query()
                ->whereKey(array_filter([
                    $model->getOriginal('city_id'),
                    $model->city_id,
                ], fn ($id): bool => is_string($id) && $id !== ''))
                ->pluck('slug')
                ->filter(fn ($slug): bool => is_string($slug))
                ->unique()
                ->values()
                ->all();
        }

        if ($model instanceof Game || $model instanceof Event) {
            $locationIds = collect([$model->getOriginal('location_id'), $model->location_id])
                ->filter()
                ->unique()
                ->values()
                ->all();

            return City::query()
                ->whereIn('id', Location::query()->select('city_id')->whereKey($locationIds))
                ->pluck('slug')
                ->filter(fn ($slug): bool => is_string($slug))
                ->unique()
                ->values()
                ->all();
        }

        return [];
    }
}
