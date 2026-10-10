<?php

use App\Models\City;
use App\Models\Location;
use App\Services\CityRegistryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

//
// CityRegistryService (D171): the single write authority mapping locations
// to registry clusters. Deterministic from the location's own data —
// Str::slug(city) + 3-char geohash region — never from admin input.
//
// Coordinates pin regions deterministically (shared test convention):
//   Berlin  52.5200/13.4050 -> u33     Hamburg 53.5511/9.9937 -> u1x
//   Munich  48.1351/11.5820 -> u28
//
// The migration 2026_10_09_160000 duplicates this logic self-contained;
// the backfill parity test below calls the migration directly (the D170
// UuidPolicyTest pattern).
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

describe('migration 2026_10_09_160000 backfill', function () {
    it('provisions the registry and links every geocoded location from pre-migration rows', function () {
        // Pre-migration rows via raw inserts — factories would fire the
        // Location observer and provision the registry immediately.
        $berlinA = (string) Str::uuid7();
        $berlinB = (string) Str::uuid7();
        $neustadtPrimary = (string) Str::uuid7();
        $neustadtSecondary = (string) Str::uuid7();
        DB::table('locations')->insert([
            ['id' => $berlinA, 'name' => 'A', 'city' => 'Berlin', 'country' => 'DEU', 'latitude' => 52.52, 'longitude' => 13.405, 'geohash_4' => 'u33d'],
            ['id' => $berlinB, 'name' => 'B', 'city' => 'Berlin ', 'country' => 'DEU', 'latitude' => 52.53, 'longitude' => 13.41, 'geohash_4' => 'u33d'],
            ['id' => $neustadtPrimary, 'name' => 'C', 'city' => 'Neustadt', 'country' => 'DEU', 'latitude' => 52.5300, 'longitude' => 13.4100, 'geohash_4' => 'u33d'],
            ['id' => $neustadtSecondary, 'name' => 'D', 'city' => 'Neustadt', 'country' => 'DEU', 'latitude' => 53.5511, 'longitude' => 9.9937, 'geohash_4' => 'u1x0'],
        ]);

        // Rewind to the pre-migration schema state the backfill expects.
        Location::query()->whereIn('id', [$berlinA, $berlinB, $neustadtPrimary, $neustadtSecondary])->update(['city_id' => null]);
        Schema::table('locations', fn ($table) => $table->dropConstrainedForeignId('city_id'));
        Schema::table('cities', fn ($table) => $table->dropColumn('curation_state'));
        City::query()->delete();

        $migration = require database_path('migrations/2026_10_09_160000_city_registry_entity_links.php');
        $migration->up();

        $berlin = City::query()->where('slug', 'berlin')->firstOrFail();
        // Backfill order: larger cluster keeps the bare slug — the u33
        // Neustadt (2 locations incl. the primary+secondary... 1 each —
        // u33 has the Neustadt primary; both regions have 1 Neustadt
        // location, so the lexicographically smaller u1x wins the tie.
        $bare = City::query()->where('slug', 'neustadt')->firstOrFail();
        $suffixed = City::query()->where('slug', 'neustadt-u33')->firstOrFail();

        // The dirty variant was trimmed and linked into one cluster; the
        // second-region same-name cluster got the suffixed slug.
        expect(Location::where('city', 'Berlin')->count())->toBe(2)
            ->and($berlin->locations()->count())->toBe(2)
            ->and($berlin->region_prefix)->toBe('u33')
            ->and($bare->region_prefix)->toBe('u1x')
            ->and($suffixed->region_prefix)->toBe('u33')
            ->and($suffixed->locations()->count())->toBe(1)
            ->and(Location::whereNull('city_id')->count())->toBe(0);

        $migration->down();

        expect(Schema::hasColumn('locations', 'city_id'))->toBeFalse()
            ->and(Schema::hasColumn('cities', 'curation_state'))->toBeFalse();
    });
});
