<?php

namespace Tests\Feature\Services;

use App\Dto\CitySummary;
use App\Models\City;
use App\Models\Game;
use App\Models\Location;
use App\Services\CityDirectoryService;
use App\Services\CityHubSettings;
use Illuminate\Support\Facades\Cache;

// CityDirectoryService curation enforcement (62-04 T02): the cities table
// steers resolution from the inside, so the hub guard, the 62-03 sitemap +
// ?city= canonical folding, and the featured-cities rail can never disagree
// (MEM1023). Hidden rows sentinel-cache a STATUS_HIDDEN 404 on every
// surface; featured rows force-qualify over thresholds (MEM995); a curated
// region_prefix pins an ambiguous multi-region cluster to one region;
// thresholds read DB-over-config through CityHubSettings.
//
// geohash_4 is recomputed from lat/lng on save (PG trigger), so tests
// control coordinates and never set geohash_4 directly. Fixed DACH
// coordinates pin the cluster regions deterministically (same map as
// CityDirectoryServiceTest):
//   Berlin  52.5200/13.4050  -> u33 region
//   Hamburg 53.5511/9.9937   -> u1x region
//   Munich  48.1351/11.5820  -> u28 region
//
// Helpers carry a curation- prefix: CityDirectoryServiceTest defines the
// same shapes unprefixed in this namespace, and two same-named functions
// in one Pest process fatal ("cannot redeclare function").

beforeEach(function () {
    Cache::flush();
});

function curationLocation(string $city, float $lat, float $lng, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
}

function curationUpcomingGame(?Location $location, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'location_id' => $location?->id,
        'date_time' => now()->addDays(3),
    ], $overrides));
}

/** Berlin cluster with $games upcoming public games (1 by default — below both thresholds). */
function curationBerlin(int $games = 1): Location
{
    $berlin = curationLocation('Berlin', 52.5200, 13.4050);

    for ($i = 0; $i < $games; $i++) {
        curationUpcomingGame($berlin);
    }

    return $berlin;
}

/** Curate the registry row for a cluster — mirrors the product flow: discovered rows are edited, never duplicated. */
function curationCurate(string $slug, array $attributes): City
{
    return City::updateOrCreate(['slug' => $slug], $attributes);
}

// ═══════════════════════════════════════════════════════════
// HIDDEN CURATION
// ═══════════════════════════════════════════════════════════

describe('hidden curation', function () {
    it('404s a hidden city on every surface: hidden status, null summary, no qualifying entry', function () {
        curationBerlin(3); // would qualify via sessions uncurated
        curationCurate('berlin', ['city' => 'Berlin', 'hidden' => true]);

        $service = app(CityDirectoryService::class);

        expect($service->resolveCity('berlin'))->toBeNull()
            ->and($service->resolveStatus('berlin'))->toBe(CityDirectoryService::STATUS_HIDDEN)
            ->and($service->qualifyingCities()->pluck('slug')->all())->not->toContain('berlin');
    });

    it('lets hidden win over featured', function () {
        curationBerlin(3);
        curationCurate('berlin', ['city' => 'Berlin', 'featured' => true, 'hidden' => true]);

        $service = app(CityDirectoryService::class);

        expect($service->resolveStatus('berlin'))->toBe(CityDirectoryService::STATUS_HIDDEN)
            ->and($service->resolveCity('berlin'))->toBeNull()
            ->and($service->qualifyingCities())->toBeEmpty();
    });

    it('never conjures a hub for a curated slug with no locations', function () {
        City::factory()->featured()->withIntro()->create(['slug' => 'bremen', 'city' => 'Bremen']);

        $service = app(CityDirectoryService::class);

        expect($service->resolveCity('bremen'))->toBeNull()
            ->and($service->resolveStatus('bremen'))->toBe(CityDirectoryService::STATUS_NOT_FOUND)
            ->and($service->qualifyingCities())->toBeEmpty();
    });
});

// ═══════════════════════════════════════════════════════════
// FEATURED CURATION
// ═══════════════════════════════════════════════════════════

describe('featured curation', function () {
    it('force-qualifies a featured city below both thresholds', function () {
        curationBerlin(); // 1 game (< 3), 0 verified venues (< 2)
        curationCurate('berlin', ['city' => 'Berlin', 'featured' => true]);

        $service = app(CityDirectoryService::class);
        $summary = $service->resolveCity('berlin');

        expect($summary)->not->toBeNull()
            ->and($service->resolveStatus('berlin'))->toBe(CityDirectoryService::STATUS_OK)
            ->and($summary->featured)->toBeTrue()
            ->and($service->isQualifying($summary))->toBeTrue()
            ->and($service->qualifyingCities()->pluck('slug')->all())->toContain('berlin');
    });

    it('attaches the curated translatable intro onto the summary', function () {
        curationBerlin(3);
        curationCurate('berlin', [
            'city' => 'Berlin',
            'featured' => true,
            'intro' => ['en' => 'Board game nights in Berlin.', 'de' => 'Brettspielabende in Berlin.'],
        ]);

        $summary = app(CityDirectoryService::class)->resolveCity('berlin');

        expect($summary->introFor('en'))->toBe('Board game nights in Berlin.')
            ->and($summary->introFor('de'))->toBe('Brettspielabende in Berlin.')
            ->and($summary->introFor('fr'))->toBeNull(); // uncurated locale: hero falls back
    });

    it('leaves an uncurated city unfeatured with a null intro', function () {
        curationBerlin(3);

        $summary = app(CityDirectoryService::class)->resolveCity('berlin');

        expect($summary->featured)->toBeFalse()
            ->and($summary->intro)->toBe([])
            ->and($summary->introFor('de'))->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// SUMMARY CACHE ROUND-TRIP
// ═══════════════════════════════════════════════════════════

describe('summary cache round-trip', function () {
    it('carries featured and intro through the cached resolution', function () {
        curationBerlin(3);
        curationCurate('berlin', ['city' => 'Berlin', 'featured' => true, 'intro' => ['en' => 'EN intro', 'de' => 'DE intro']]);

        $service = app(CityDirectoryService::class);
        $first = $service->resolveCity('berlin');
        $cached = $service->resolveCity('berlin'); // served from cache

        expect($first->toArray())->toHaveKeys(['featured', 'intro'])
            ->and($cached->toArray())->toEqual($first->toArray())
            ->and($cached->featured)->toBeTrue()
            ->and($cached->introFor('en'))->toBe('EN intro')
            ->and($cached->introFor('de'))->toBe('DE intro');
    });

    it('carries per-locale SEO overrides through the cached resolution with intro-style null semantics', function () {
        curationBerlin(3);
        curationCurate('berlin', [
            'city' => 'Berlin',
            'seo_title' => ['en' => '  Berlin board game nights  ', 'de' => '   '],
            'seo_description' => ['en' => 'Sessions and venues in Berlin.', 'de' => 'Brettspiele in Berlin.'],
        ]);

        $summary = app(CityDirectoryService::class)->resolveCity('berlin');

        expect($summary->seoTitleFor('en'))->toBe('Berlin board game nights') // trimmed
            ->and($summary->seoTitleFor('de'))->toBeNull() // whitespace-only counts as absent
            ->and($summary->seoDescriptionFor('de'))->toBe('Brettspiele in Berlin.');

        // The cached pass returns the identical hydrated overrides.
        expect(app(CityDirectoryService::class)->resolveCity('berlin')->toArray())
            ->toEqual($summary->toArray());
    });

    it('degrades pre-curation cache entries to the documented defaults', function () {
        // A cache entry written before 62-04 lacks the new keys: fromArray
        // must hydrate featured=false / intro=[] and introFor must fall
        // back to null, never error — the TTL outlives the deploy.
        $legacy = CitySummary::fromArray([
            'slug' => 'berlin',
            'city' => 'Berlin',
            'country' => 'DEU',
            'regionPrefix' => 'u33',
            'geohashTiles' => ['u33d'],
            'locationIds' => ['location-uuid'],
            'upcomingGamesCount' => 3,
            'upcomingCampaignsCount' => 0,
            'upcomingEventsCount' => 0,
            'verifiedVenuesCount' => 0,
        ]);

        expect($legacy->featured)->toBeFalse()
            ->and($legacy->intro)->toBe([])
            ->and($legacy->introFor('en'))->toBeNull()
            ->and($legacy->seoTitle)->toBe([])
            ->and($legacy->seoTitleFor('en'))->toBeNull()
            ->and($legacy->seoDescriptionFor('en'))->toBeNull()
            ->and($legacy->upcomingGamesCount)->toBe(3); // rest of the entry intact
    });

    it('trims intro values and treats whitespace-only translations as absent', function () {
        $summary = CitySummary::fromArray([
            'slug' => 'berlin',
            'city' => 'Berlin',
            'regionPrefix' => 'u33',
            'intro' => ['en' => '  Hello Berlin!  ', 'de' => '   '],
        ]);

        expect($summary->introFor('en'))->toBe('Hello Berlin!')
            ->and($summary->introFor('de'))->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// REGION_PREFIX DISAMBIGUATION
// ═══════════════════════════════════════════════════════════

describe('registry identity (D171)', function () {
    it('provisions same-name clusters as two hubs and curation affects only the curated one', function () {
        $berlinArea = curationLocation('Neustadt', 52.5200, 13.4050); // u33, created first: keeps bare slug
        curationUpcomingGame($berlinArea);
        curationUpcomingGame($berlinArea);

        $hamburgArea = curationLocation('Neustadt', 53.5511, 9.9937); // u1x: suffixed slug
        curationUpcomingGame($hamburgArea);
        curationUpcomingGame($hamburgArea);
        curationUpcomingGame($hamburgArea);

        $service = app(CityDirectoryService::class);

        // Both clusters resolve independently — no ambiguity, no merge.
        $primary = $service->resolveCity('neustadt');
        $secondary = $service->resolveCity('neustadt-u1x');
        expect($primary)->not->toBeNull()
            ->and($secondary)->not->toBeNull()
            ->and($primary->locationIds)->toEqual([$berlinArea->id])
            ->and($secondary->locationIds)->toEqual([$hamburgArea->id])
            ->and($primary->upcomingGamesCount)->toBe(2)
            ->and($secondary->upcomingGamesCount)->toBe(3);

        // Featuring the suffixed hub force-qualifies only that hub.
        curationCurate('neustadt-u1x', ['city' => 'Neustadt', 'featured' => true]);
        $service->forget('neustadt-u1x');

        $qualifying = $service->qualifyingCities()->pluck('slug')->all();
        expect($qualifying)->toContain('neustadt-u1x')
            ->and($qualifying)->not->toContain('neustadt'); // 2 games < 3 threshold, 0 venues
    });

    it('treats region_prefix as descriptive identity — editing it never moves cluster membership', function () {
        curationLocation('Neustadt', 52.5200, 13.4050); // u33
        curationLocation('Neustadt', 53.5511, 9.9937);  // u1x

        $service = app(CityDirectoryService::class);

        $before = $service->resolveCity('neustadt');
        expect($before)->not->toBeNull()
            ->and($before->regionPrefix)->toBe('u33');

        // Even a nonsensical prefix edit (Munich's region) cannot steal or
        // move locations: membership is owned by locations.city_id, not by
        // any derivable string or admin-editable column.
        City::query()->where('slug', 'neustadt')->update(['region_prefix' => 'u28']);
        $service->forget('neustadt');

        $after = $service->resolveCity('neustadt');
        expect($after->locationIds)->toEqual($before->locationIds)
            ->and($after->regionPrefix)->toBe('u28'); // label follows the row, membership does not
    });
});

// ═══════════════════════════════════════════════════════════
// DB-BACKED THRESHOLDS
// ═══════════════════════════════════════════════════════════

describe('db-backed thresholds', function () {
    it('reads the guard thresholds from city_hub_settings rows over config', function () {
        curationBerlin(3); // exactly meets the config default (3 sessions)
        $service = app(CityDirectoryService::class);
        $summary = $service->resolveCity('berlin');

        // Config fallback: 3 sessions qualifies (1 venue threshold unused).
        expect($service->isQualifying($summary))->toBeTrue();

        // DB rows override config without a deploy (the Filament path).
        app(CityHubSettings::class)->set(5, 2);
        expect($service->isQualifying($summary))->toBeFalse(); // 3 < 5, 0 < 2

        app(CityHubSettings::class)->set(3, 2);
        expect($service->isQualifying($summary))->toBeTrue(); // 3 >= 3
    });

    it('still observes runtime config overrides after a prior guard read', function () {
        curationBerlin(); // 1 game, 0 venues

        $service = app(CityDirectoryService::class);
        $summary = $service->resolveCity('berlin');

        // Warms the settings cache — the config fallback must stay live,
        // not freeze the config snapshot at first read (the 49-test
        // baseline contract for tests without settings rows).
        expect($service->isQualifying($summary))->toBeFalse();

        config(['cityhubs.min_upcoming_sessions' => 1]);
        expect($service->isQualifying($summary))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// FORGETALL
// ═══════════════════════════════════════════════════════════

describe('forgetAll', function () {
    it('flushes every known city slug and returns the count', function () {
        curationBerlin(3);
        curationLocation('Hamburg', 53.5511, 9.9937);
        // berlin + hamburg registry rows already auto-provisioned above.

        $service = app(CityDirectoryService::class);

        // Warm both cities' resolutions.
        expect($service->resolveCity('berlin')->upcomingGamesCount)->toBe(3)
            ->and($service->resolveCity('hamburg'))->not->toBeNull();

        // Mass curation change with no model events, so the cached ok
        // resolution keeps winning until an explicit flush.
        City::query()->where('slug', 'berlin')->update(['hidden' => true]);

        expect($service->resolveCity('berlin'))->not->toBeNull(); // still cached ok

        expect($service->forgetAll())->toBe(2); // berlin + hamburg

        expect($service->resolveCity('berlin'))->toBeNull()
            ->and($service->resolveStatus('berlin'))->toBe(CityDirectoryService::STATUS_HIDDEN);
    });
});
