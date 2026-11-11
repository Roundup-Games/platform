<?php

use App\Models\City;
use App\Models\Location;
use App\Services\CityRegistryService;
use Illuminate\Support\Facades\Cache;

//
// CityRegistryService (D171): the single write authority mapping locations
// to registry clusters. Deterministic from the location's own data —
// Str::slug(city) + 3-char geohash region — never from admin input.
//
// Coordinates pin regions deterministically (shared test convention):
//   Berlin  52.5200/13.4050 -> u33     Hamburg 53.5511/9.9937 -> u1x
//   Munich  48.1351/11.5820 -> u28
//
// The migration 2026_10_09_160000 duplicates this logic self-contained; its
// backfill parity test lives in tests/Feature/Migrations/
// CityRegistryBackfillMigrationTest.php (the DDL it rewinds — locations FK,
// cities column — deadlocks sibling --parallel workers, so it runs in the
// serial ddl-serial tail; moved 2026-11-11).
beforeEach(function () {
    Cache::flush();
});

function registryLocation(string $city, float $lat, float $lng, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
}

describe('CityRegistryService provisioning', function () {
    it('provisions a registry row on first location save and links it', function () {
        expect(City::count())->toBe(0);

        $berlin = registryLocation('Berlin', 52.5200, 13.4050);

        $city = City::query()->where('slug', 'berlin')->first();

        expect($city)->not->toBeNull()
            ->and($city->city)->toBe('Berlin')
            ->and($city->region_prefix)->toBe('u33')
            ->and($city->curation_state)->toBe('discovered')
            ->and($berlin->fresh()->city_id)->toBe($city->id);
    });

    it('merges string variants that slug identically into one cluster', function () {
        // Trim-on-write normalizes the trailing space; both rows link to
        // the same registry row even if a variant slips past (e.g. legacy
        // data mutated directly).
        $a = registryLocation('Berlin', 52.5200, 13.4050);
        $b = new Location;
        $b->forceFill([
            'city' => 'Berlin ',
            'country' => 'DEU',
            'latitude' => 52.5100,
            'longitude' => 13.4200,
            'name' => 'Variant Venue',
        ])->save();

        app(CityRegistryService::class)->syncLocation($b->fresh());

        expect($a->fresh()->city_id)->toBe($b->fresh()->city_id)
            ->and(City::query()->where('slug', 'berlin')->count())->toBe(1);
    });

    it('trims city strings on write so variants never form', function () {
        $location = registryLocation('  Berlin  ', 52.5200, 13.4050);

        expect($location->fresh()->city)->toBe('Berlin');
    });

    it('suffixes a same-name cluster in a different region and keeps both addressable', function () {
        registryLocation('Neustadt', 52.5200, 13.4050); // u33 first: bare slug
        $u1x = registryLocation('Neustadt', 53.5511, 9.9937); // u1x: suffixed

        $primary = City::query()->where('slug', 'neustadt')->firstOrFail();
        $secondary = City::query()->where('slug', 'neustadt-u1x')->firstOrFail();

        expect($primary->region_prefix)->toBe('u33')
            ->and($secondary->region_prefix)->toBe('u1x')
            ->and($u1x->fresh()->city_id)->toBe($secondary->id)
            ->and(City::count())->toBe(2);
    });

    it('reuses an existing curated row for its cluster instead of colliding', function () {
        registryLocation('Berlin', 52.5200, 13.4050);

        // Curate first (the product flow), then a second location joins —
        // no new row, no unique violation, curation untouched.
        City::query()->where('slug', 'berlin')->update(['featured' => true, 'curation_state' => 'curated']);

        $second = registryLocation('Berlin', 52.5300, 13.4100);

        $city = City::query()->where('slug', 'berlin')->firstOrFail();
        expect($second->fresh()->city_id)->toBe($city->id)
            ->and($city->featured)->toBeTrue()
            ->and($city->curation_state)->toBe('curated')
            ->and(City::count())->toBe(1);
    });
});

describe('CityRegistryService relinking', function () {
    it('relinks a location that moves cities and flushes both hub caches', function () {
        $koeln = registryLocation('Koeln', 50.9375, 6.9603);
        registryLocation('Berlin', 52.5200, 13.4050);

        Cache::set('city-hubs:summary:koeln', ['status' => 'ok', 'summary' => null]);
        Cache::set('city-hubs:summary:berlin', ['status' => 'ok', 'summary' => null]);

        $koeln->update(['city' => 'Berlin', 'latitude' => 52.5200, 'longitude' => 13.4050]);

        expect($koeln->fresh()->city_id)->toBe(City::query()->where('slug', 'berlin')->first()->id)
            ->and(Cache::get('city-hubs:summary:koeln'))->toBeNull()
            ->and(Cache::get('city-hubs:summary:berlin'))->toBeNull();
    });

    it('leaves un-geocoded locations unlinked instead of guessing a region', function () {
        $location = Location::factory()->create([
            'city' => 'Berlin',
            'country' => 'DEU',
            'latitude' => null,
            'longitude' => null,
        ]);

        expect($location->fresh()->city_id)->toBeNull()
            ->and(City::count())->toBe(0);
    });
});
