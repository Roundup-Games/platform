<?php

use App\Models\City;
use App\Models\CityAlias;
use App\Models\Location;
use App\Models\User;
use App\Services\CityMergeService;
use App\Support\CityNormalizer;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

//
// City matching + merges (D174): confirmed merges create alias rules;
// free-text intake resolves aliases to the surviving entity before
// provisioning anything new. Trigram stays suggestions-only.
//
// Coordinates pin regions (test convention): Berlin 52.52/13.405 -> u33,
// Munich 48.1351/11.582 -> u28, Hamburg 53.5511/9.9937 -> u1x.
function aliasLocation(string $city, float $lat, float $lng, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
}

describe('CityNormalizer', function () {
    it('folds umlauts and transliterations into one key', function () {
        expect(CityNormalizer::key('München'))->toBe('munchen')
            ->and(CityNormalizer::key('Muenchen'))->toBe('munchen')
            ->and(CityNormalizer::key(' muenchen '))->toBe('munchen')
            ->and(CityNormalizer::key('Köln'))->toBe('koln')
            ->and(CityNormalizer::key('Koeln'))->toBe('koln');
    });
});

describe('alias resolution on the write path', function () {
    it('links a merged spelling to the surviving hub instead of provisioning a duplicate', function () {
        // The real München cluster.
        $muc = aliasLocation('München', 48.1351, 11.582);
        $mucCity = City::query()->where('slug', 'munchen')->firstOrFail();

        // Admin merged a "munchin" duplicate into München once.
        $munchin = City::create(['slug' => 'munchin', 'city' => 'munchin', 'country' => 'DEU', 'region_prefix' => 'u28', 'curation_state' => 'discovered']);
        app(CityMergeService::class)->merge($munchin, $mucCity->fresh());

        // Next user types the merged spelling: stored as a LINK to
        // München, never as free-text or a new cluster.
        $next = aliasLocation('munchin', 48.1500, 11.5900);

        expect($next->fresh()->city_id)->toBe($mucCity->id)
            ->and(City::query()->where('slug', 'munchin')->doesntExist())
            ->and(CityAlias::query()->where('normalized_key', 'munchin')->where('city_id', $mucCity->id)->exists())->toBeTrue();
    });

    it('auto-links ue-transliterations without any admin action (normalized slug tier)', function () {
        aliasLocation('München', 48.1351, 11.582);

        $variant = aliasLocation('Muenchen', 48.1500, 11.5900);

        expect($variant->fresh()->city_id)
            ->toBe(City::query()->where('slug', 'munchen')->first()->id);
    });
});

describe('CityMergeService', function () {
    it('moves locations, absorbs aliases, deletes the source, and flushes caches', function () {
        Cache::flush();
        $target = City::create(['slug' => 'berlin', 'city' => 'Berlin', 'country' => 'DEU', 'region_prefix' => 'u33', 'curation_state' => 'curated']);
        $source = City::create(['slug' => 'berlin-oh', 'city' => 'Berlin Oh', 'country' => 'DEU', 'region_prefix' => 'u33', 'curation_state' => 'discovered']);

        $a = Location::factory()->create(['city' => 'Berlin Oh', 'country' => 'DEU', 'latitude' => 52.52, 'longitude' => 13.405]);
        Location::query()->whereKey($a->id)->update(['city_id' => $source->id]);

        Cache::set('city-hubs:summary:berlin-oh', ['status' => 'ok', 'summary' => null]);

        app(CityMergeService::class)->merge($source->fresh(), $target->fresh());

        expect($a->fresh()->city_id)->toBe($target->id)
            ->and(City::query()->whereKey($source->id)->doesntExist())
            ->and(CityAlias::query()->where('city_id', $target->id)->where('normalized_key', 'berlin-oh')->exists())->toBeTrue()
            ->and(Cache::get('city-hubs:summary:berlin-oh'))->toBeNull();

        // The alias now resolves future free input.
        $later = aliasLocation('Berlin Oh', 52.53, 13.41);
        expect($later->fresh()->city_id)->toBe($target->id);
    });

    it('refuses to merge a hub into itself', function () {
        $city = City::create(['slug' => 'selfmerge', 'city' => 'Selfmerge', 'country' => 'DEU', 'region_prefix' => 'u33', 'curation_state' => 'discovered']);

        expect(fn () => app(CityMergeService::class)->merge($city, $city))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('suggest endpoint', function () {
    it('ranks alias and normalized hits first, trigram as suggestions', function () {
        seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole('Platform Admin');
        actingAs($admin);

        aliasLocation('München', 48.1351, 11.582); // munchen cluster

        // "Muenchen": normalized-exact tier — suggests the München hub.
        $response = get('/api/v1/cities/suggest?q=Muenchen');
        $suggestions = $response->json('suggestions');
        expect($suggestions)->not->toBeEmpty()
            ->and($suggestions[0]['slug'])->toBe('munchen');

        // Typos: trigram tier — a suggestion, never an auto-merge.
        // (Exonyms like 'Munich' vs 'München' are below trigram similarity
        // — that is the geocoder precedence path, deliberately not fuzzy.)
        $response = get('/api/v1/cities/suggest?q=Münchn');
        expect(collect($response->json('suggestions'))->pluck('slug'))->toContain('munchen');

        // Post-merge alias surfaces exactly like an exact hit.
        $munchin = City::create(['slug' => 'munchin', 'city' => 'munchin', 'country' => 'DEU', 'region_prefix' => 'u28', 'curation_state' => 'discovered']);
        app(CityMergeService::class)->merge($munchin, City::query()->where('slug', 'munchen')->first());

        $response = get('/api/v1/cities/suggest?q=munchin');
        $suggestions = $response->json('suggestions');
        expect($suggestions)->not->toBeEmpty()
            ->and($suggestions[0]['slug'])->toBe('munchen');
    });

    it('returns nothing for very short queries', function () {
        seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole('Platform Admin');
        actingAs($admin);

        get('/api/v1/cities/suggest?q=mu')
            ->assertOk()
            ->assertJson(['suggestions' => []]);
    });
});
