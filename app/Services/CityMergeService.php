<?php

namespace App\Services;

use App\Models\City;
use App\Models\CityAlias;
use App\Models\Location;
use App\Support\CityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin-confirmed city merges (D174): moves every location of the source
 * hub to the target hub, absorbs the source's aliases, records the
 * source's name (plus its raw variants) as aliases of the target, and
 * deletes the now-empty source row. The alias rules mean any future
 * free-text input of the merged spelling resolves straight to the
 * surviving entity — merges are learned from, not just performed once.
 *
 * Identity of the TARGET never changes: its slug, curation, and SEO stay
 * as they are. The merge is one transaction; caches for both slugs and
 * the cities sitemap are flushed on completion.
 */
class CityMergeService
{
    public function __construct(
        private readonly CityDirectoryService $cityDirectory,
    ) {}

    public function merge(City $source, City $target): void
    {
        if ($source->getKey() === $target->getKey()) {
            throw new \InvalidArgumentException('A city cannot be merged into itself.');
        }

        DB::transaction(function () use ($source, $target): void {
            // Alias spellings the target learns: the source's canonical
            // name and every geocoder variant its locations carried.
            $spellings = collect([$source->city])
                ->merge($source->locations()->distinct()->pluck('city'))
                ->push($source->slug)
                ->filter(fn ($name): bool => is_string($name) && $name !== '')
                ->unique()
                ->values();

            foreach ($spellings as $spelling) {
                $key = CityNormalizer::key($spelling);

                // Skip spellings that already resolve to the target or
                // another existing alias — first rule wins.
                if ($key === CityNormalizer::key($target->city) || $target->slug === $key) {
                    continue;
                }

                CityAlias::firstOrCreate(
                    ['normalized_key' => $key, 'city_id' => $target->getKey()],
                    ['alias' => $spelling, 'source' => 'merge'],
                );
            }

            // Absorb aliases that pointed at the source.
            CityAlias::query()->where('city_id', $source->getKey())
                ->update(['city_id' => $target->getKey()]);

            // Move the evidence: locations re-point, mass update (no
            // events — CityRegistryService relinking here would recurse
            // into provisioning decisions the merge just settled).
            $moved = Location::query()->where('city_id', $source->getKey())
                ->update(['city_id' => $target->getKey()]);

            // The source row is now empty by definition — delete it.
            $source->delete();

            Log::info('city_registry.merged', [
                'source_slug' => $source->slug,
                'source_city' => $source->city,
                'target_slug' => $target->slug,
                'locations_moved' => $moved,
                'aliases_created' => $spellings->count(),
            ]);

            // Fresh state for both hub caches + the cities sitemap.
            $this->cityDirectory->forget($source->slug);
            $this->cityDirectory->forget($target->slug);
            app(SeoCacheService::class)->forgetSitemap('cities');
            app(SeoCacheService::class)->forgetIndex();
        });
    }
}
