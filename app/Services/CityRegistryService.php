<?php

namespace App\Services;

use App\Models\City;
use App\Models\Location;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The single write authority for city registry identity (D171).
 *
 * One registry row per resolved cluster: same Str::slug(city) AND same
 * 3-char geohash region. Every location save flows through
 * syncLocation(), which deterministically derives the cluster from the
 * row's own city string + geohash_4 and links it — never from admin
 * input. Registry rows are therefore pure functions of location data:
 * they appear when a cluster first exists, move when their locations
 * move, and can never be hand-created or hand-pointed.
 *
 * Slug rules (frozen once created — public URL contract, D170
 * discipline): the first cluster for a slug takes the bare slug; a
 * same-name cluster in a different region gets slug-{region}. A
 * pre-existing curated row that already owns a slug keeps it and is
 * reused (curation is never overwritten by provisioning).
 */
class CityRegistryService
{
    public const CLUSTER_GEOHASH_PREFIX_LENGTH = 3;

    public function __construct(
        private readonly CityDirectoryService $cityDirectory,
    ) {}

    /**
     * Relink a location to its cluster. Runs from Location::saved — after
     * the geohash_4 saving hook, so the region is always derivable for
     * geocoded rows. Idempotent: exits without writing when the link is
     * already correct. Uses a mass update (no model events) so it cannot
     * recurse and cannot disturb other dirty state; the affected hub
     * caches are flushed explicitly for both the old and new cluster.
     */
    public function syncLocation(Location $location): void
    {
        $city = is_string($location->city) ? trim($location->city) : null;
        $geohash = is_string($location->geohash_4) ? $location->geohash_4 : null;

        if ($city === null || $city === '' || $geohash === null) {
            return;
        }

        $slug = Str::slug($city);
        $region = substr($geohash, 0, self::CLUSTER_GEOHASH_PREFIX_LENGTH);

        if ($slug === '') {
            return;
        }

        $registry = $this->registryFor($slug, $region, $city, $location->country);

        if ($registry === null) {
            return;
        }

        // DB truth for the previous link — the in-memory city_id can be
        // stale (this hook itself writes it via mass update, which never
        // hydrates the instance).
        $previousId = Location::query()->whereKey($location->getKey())->value('city_id');

        if ($previousId === $registry->id) {
            return;
        }

        Location::query()
            ->whereKey($location->getKey())
            ->update(['city_id' => $registry->id]);

        $this->flushCaches(is_string($previousId) ? $previousId : null, $registry->id);
    }

    /**
     * Find (or provision) the registry row for one cluster. Reuses an
     * existing row — curated or discovered — whenever its slug matches,
     * so curation and backfill results are never duplicated.
     */
    private function registryFor(string $slug, string $region, string $city, ?string $country): ?City
    {
        foreach ($this->candidateSlugs($slug, $region) as $candidate) {
            $existing = City::query()->where('slug', $candidate)->first();

            if ($existing !== null) {
                if ($existing->region_prefix === null || $existing->region_prefix === $region) {
                    if ($existing->region_prefix === null) {
                        City::query()->whereKey($existing->getKey())->update(['region_prefix' => $region]);
                    }

                    return $existing;
                }

                // Same slug, different region — try the next candidate.
                continue;
            }

            return $this->createRegistryRow($candidate, $region, $city, $country);
        }

        return null;
    }

    /**
     * Deterministic slug candidates for a cluster: the bare slug first
     * (first-come keeps it), then the region-suffixed slug.
     *
     * @return Collection<int, string>
     */
    private function candidateSlugs(string $slug, string $region): Collection
    {
        return collect([$slug, "{$slug}-{$region}"])->unique()->values();
    }

    /**
     * Create the registry row; on a unique-slug race (two concurrent
     * first-saves of the same cluster) the loser's retry finds the
     * winner's row and reuses it. No wrapping transaction: a failed
     * INSERT aborts a Postgres transaction, so the retry lookup must run
     * outside it — City::create is a single atomic statement anyway.
     */
    private function createRegistryRow(string $slug, string $region, string $city, ?string $country): ?City
    {
        try {
            return City::create([
                'slug' => $slug,
                'city' => $city,
                'country' => $country,
                'region_prefix' => $region,
                'curation_state' => 'discovered',
            ]);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 23505) {
                throw $e;
            }

            return City::query()->where('slug', $slug)->first();
        }
    }

    /**
     * Flush the summary caches of both affected clusters — a location
     * moving between hubs invalidates the old and the new one.
     */
    private function flushCaches(?string $previousId, string $currentId): void
    {
        $slugs = City::query()
            ->whereKey(array_filter([$previousId, $currentId]))
            ->pluck('slug');

        foreach ($slugs as $slug) {
            if (is_string($slug)) {
                $this->cityDirectory->forget($slug);
            }
        }
    }
}
