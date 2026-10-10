<?php

namespace Tests\Feature\Services;

use App\Enums\EventStatus;
use App\Enums\GameStatus;
use App\Enums\VenueType;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Models\User;
use App\Services\CityDirectoryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// CityDirectoryService (M062 T01): slug resolution, cluster disambiguation,
// activity counting, and the OR-based qualification guard behind city hubs.
//
// geohash_4 is recomputed from lat/lng on save (model saving hook), so tests
// always control coordinates and never set geohash_4 directly. Fixed DACH
// coordinates pin the cluster regions deterministically:
//   Berlin  52.5200/13.4050  -> u33d (region u33)
//   Berlin  52.5100/13.6200  -> u33d (same region, merges)
//   Hamburg 53.5511/9.9937   -> u1x0 (region u1x)
//   Munich  48.1351/11.5820  -> u281 (region u28)
//   Cologne 50.9375/6.9603   -> u1hc (region u1h)

beforeEach(function () {
    Cache::flush();
});

function cityLocation(string $city, float $lat, float $lng, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
}

function upcomingGame(?Location $location, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'location_id' => $location?->id,
        'date_time' => now()->addDays(3),
    ], $overrides));
}

// ═══════════════════════════════════════════════════════════
// CLUSTER RESOLUTION
// ═══════════════════════════════════════════════════════════

describe('resolveCity cluster resolution', function () {
    it('resolves a city by slug with upcoming public game count', function () {
        $berlin = cityLocation('Berlin', 52.5200, 13.4050);
        upcomingGame($berlin);
        upcomingGame($berlin);
        upcomingGame($berlin);

        $service = app(CityDirectoryService::class);
        $summary = $service->resolveCity('berlin');

        expect($summary)->not->toBeNull()
            ->and($summary->slug)->toBe('berlin')
            ->and($summary->city)->toBe('Berlin')
            ->and($summary->country)->toBe('DEU')
            ->and($summary->upcomingGamesCount)->toBe(3)
            ->and($summary->upcomingActivityCount())->toBe(3)
            ->and($service->resolveStatus('berlin'))->toBe(CityDirectoryService::STATUS_OK);
    });

    it('normalizes non-ascii input slugs via Str::slug', function () {
        cityLocation('München', 48.1351, 11.5820);

        $service = app(CityDirectoryService::class);

        // Route slugs are ASCII ([a-zA-Z0-9\-]+), but the service still
        // normalizes whatever it receives.
        expect($service->resolveCity('München'))->not->toBeNull()
            ->and($service->resolveCity(Str::slug('München'))->city)->toBe('München');
    });

    it('merges same-city locations in one region into a single cluster', function () {
        $west = cityLocation('Berlin', 52.5200, 13.4050);
        $east = cityLocation('Berlin', 52.5100, 13.6200);
        upcomingGame($west);
        upcomingGame($east);

        $summary = app(CityDirectoryService::class)->resolveCity('berlin');

        expect($summary)->not->toBeNull()
            ->and($summary->locationIds)->toContain($west->id)
            ->and($summary->locationIds)->toContain($east->id)
            ->and(count($summary->locationIds))->toBe(2)
            // Tiles come from the recomputed geohash_4 (never factory values).
            ->and($summary->geohashTiles)->toContain($west->fresh()->geohash_4)
            ->and($summary->upcomingGamesCount)->toBe(2);
    });

    it('returns null with not_found for an unknown slug', function () {
        cityLocation('Berlin', 52.5200, 13.4050);

        $service = app(CityDirectoryService::class);

        expect($service->resolveCity('no-such-city'))->toBeNull()
            ->and($service->resolveStatus('no-such-city'))->toBe(CityDirectoryService::STATUS_NOT_FOUND);
    });

    it('resolves same-name cities in different regions as two distinct hubs', function () {
        // Two German Neustadts, ~290km apart: distinct geohash regions.
        // D171 registry: never merge, never 404 — the larger cluster keeps
        // the bare slug, the other is addressable under slug-{region}.
        cityLocation('Neustadt', 52.5200, 13.4050); // u33 (Berlin area)
        cityLocation('Neustadt', 53.5511, 9.9937);  // u1x (Hamburg area)
        upcomingGame(cityLocation('Neustadt', 52.5300, 13.4100)); // u33 again

        $service = app(CityDirectoryService::class);

        // The u33 cluster is larger (2 locations vs 1), so it owns 'neustadt'.
        $primary = $service->resolveCity('neustadt');
        expect($primary)->not->toBeNull()
            ->and($primary->regionPrefix)->toBe('u33')
            ->and(count($primary->locationIds))->toBe(2)
            ->and($service->resolveStatus('neustadt'))->toBe(CityDirectoryService::STATUS_OK);

        // The Hamburg-area Neustadt is separately addressable and separate
        // data — resolving it never mixes in the u33 locations.
        $secondary = $service->resolveCity('neustadt-u1x');
        expect($secondary)->not->toBeNull()
            ->and($secondary->regionPrefix)->toBe('u1x')
            ->and(count($secondary->locationIds))->toBe(1);
    });

    it('resolves an umlaut city under exactly its Str::slug output, keeping ASCII spellings distinct', function () {
        cityLocation('München', 48.1351, 11.5820); // u28 (Munich region)

        $service = app(CityDirectoryService::class);
        $slug = Str::slug('München');

        // Known value, not re-derived implementation logic: ü transliterates
        // to a plain u, so the canonical URL slug is ASCII 'munchen'.
        expect($slug)->toBe('munchen');

        $summary = $service->resolveCity('München');

        expect($summary)->not->toBeNull()
            ->and($summary->slug)->toBe($slug)
            ->and($summary->city)->toBe('München')
            ->and($service->resolveCity('munchen')->slug)->toBe($slug)
            ->and($service->resolveStatus($slug))->toBe(CityDirectoryService::STATUS_OK);

        // The ASCII spelling 'Muenchen' slugs differently, so it must NOT
        // alias into the München cluster — city matching is exact
        // Str::slug equality, never fuzzy.
        expect(Str::slug('Muenchen'))->toBe('muenchen')
            ->and($service->resolveCity('muenchen'))->toBeNull()
            ->and($service->resolveStatus('muenchen'))->toBe(CityDirectoryService::STATUS_NOT_FOUND);
    });

    it('never merges a city-name collision even when both regions independently qualify', function () {
        // Two Neustadts, each with enough activity to qualify alone. A
        // merge (or pick-the-active-cluster) regression would produce one
        // 6-session cluster — the registry must keep two 3-session hubs.
        $berlinArea = cityLocation('Neustadt', 52.5200, 13.4050); // u33
        $hamburgArea = cityLocation('Neustadt', 53.5511, 9.9937); // u1x
        for ($i = 0; $i < 3; $i++) {
            upcomingGame($berlinArea);
            upcomingGame($hamburgArea);
        }

        $service = app(CityDirectoryService::class);

        // Runtime provisioning is first-come: the u33 cluster was created
        // first, so it keeps the bare slug; the u1x cluster gets the
        // region-suffixed slug.
        $primary = $service->resolveCity('neustadt');
        $secondary = $service->resolveCity('neustadt-u1x');

        expect($primary)->not->toBeNull()
            ->and($secondary)->not->toBeNull()
            ->and($primary->upcomingGamesCount)->toBe(3)
            ->and($secondary->upcomingGamesCount)->toBe(3)
            ->and($primary->locationIds)->not->toBe($secondary->locationIds);

        // Both surface independently as qualifying hubs — a visitor can
        // reach each Neustadt under its own URL.
        $qualifyingSlugs = $service->qualifyingCities()->pluck('slug')->all();
        expect($qualifyingSlugs)->toContain('neustadt')
            ->and($qualifyingSlugs)->toContain('neustadt-u1x');
    });

    it('returns null with not_found for an empty slug', function () {
        $service = app(CityDirectoryService::class);

        expect($service->resolveCity(''))->toBeNull()
            ->and($service->resolveStatus(''))->toBe(CityDirectoryService::STATUS_NOT_FOUND);
    });
});

// ═══════════════════════════════════════════════════════════
// ACTIVITY COUNTS
// ═══════════════════════════════════════════════════════════

describe('upcoming activity counts', function () {
    it('counts only future scheduled public games inside the window', function () {
        $berlin = cityLocation('Berlin', 52.5200, 13.4050);

        upcomingGame($berlin); // valid: default public/scheduled/+3d
        upcomingGame($berlin, ['date_time' => now()->addDays(29)]); // valid: inside 30d window
        upcomingGame($berlin, ['visibility' => 'private']); // excluded: private
        upcomingGame($berlin, ['status' => GameStatus::Completed->value, 'date_time' => now()->subDays(3)]); // excluded: completed/past
        upcomingGame($berlin, ['status' => GameStatus::Canceled->value]); // excluded: canceled
        upcomingGame($berlin, ['date_time' => now()->addDays(40)]); // excluded: outside window

        $summary = app(CityDirectoryService::class)->resolveCity('berlin');

        expect($summary->upcomingGamesCount)->toBe(2);
    });

    it('does not count games at locations in other cities', function () {
        $berlin = cityLocation('Berlin', 52.5200, 13.4050);
        $hamburg = cityLocation('Hamburg', 53.5511, 9.9937);

        upcomingGame($berlin);
        upcomingGame($hamburg);
        upcomingGame($hamburg);

        expect(app(CityDirectoryService::class)
            ->resolveCity('berlin')
            ->upcomingGamesCount)->toBe(1);
    });

    it('counts campaigns once when they have an upcoming session in the cluster', function () {
        $berlin = cityLocation('Berlin', 52.5200, 13.4050);
        $hamburg = cityLocation('Hamburg', 53.5511, 9.9937);

        $inCity = Campaign::factory()->create(); // default: public + active
        upcomingGame($berlin, ['campaign_id' => $inCity->id]);
        upcomingGame($berlin, ['campaign_id' => $inCity->id]); // second session: still one campaign

        $private = Campaign::factory()->create(['visibility' => 'private']);
        upcomingGame($berlin, ['campaign_id' => $private->id]); // excluded: private campaign

        $elsewhere = Campaign::factory()->create(); // sessions outside the cluster
        upcomingGame($hamburg, ['campaign_id' => $elsewhere->id]);

        $summary = app(CityDirectoryService::class)->resolveCity('berlin');

        expect($summary->upcomingCampaignsCount)->toBe(1);
    });

    it('counts public upcoming events at cluster locations only', function () {
        $berlin = cityLocation('Berlin', 52.5200, 13.4050);

        Event::factory()->create([ // valid: default is_public + registration_open
            'location_id' => $berlin->id,
            'start_date' => now()->addDays(10),
        ]);
        Event::factory()->create([ // excluded: draft
            'location_id' => $berlin->id,
            'status' => EventStatus::Draft->value,
            'start_date' => now()->addDays(10),
        ]);
        Event::factory()->create([ // excluded: not public
            'location_id' => $berlin->id,
            'is_public' => false,
            'start_date' => now()->addDays(10),
        ]);
        Event::factory()->create([ // excluded: outside window
            'location_id' => $berlin->id,
            'start_date' => now()->addDays(45),
        ]);
        Event::factory()->create([ // excluded: no cluster location
            'start_date' => now()->addDays(10),
            'city' => 'Berlin',
        ]);

        $summary = app(CityDirectoryService::class)->resolveCity('berlin');

        expect($summary->upcomingEventsCount)->toBe(1);
    });
});

// ═══════════════════════════════════════════════════════════
// VERIFIED VENUES
// ═══════════════════════════════════════════════════════════

describe('verified venue count', function () {
    it('counts slug-bearing public-venue-page locations only', function () {
        // Counted: verified commercial venue.
        createVerifiedVenue([
            'city' => 'Berlin',
            'latitude' => 52.5200,
            'longitude' => 13.4050,
        ]);

        // Counted: admin-managed commercial venue (unverified).
        cityLocation('Berlin', 52.5250, 13.4100, [
            'is_verified' => false,
            'venue_type' => VenueType::Flgs,
            'managed_by' => User::factory()->create()->id,
            'slug' => 'managed-venue-'.Str::random(8),
        ]);

        // Excluded: unverified, no venue profile.
        cityLocation('Berlin', 52.5300, 13.4200);

        // Excluded: verified but non-commercial type.
        createVerifiedVenue([
            'city' => 'Berlin',
            'latitude' => 52.5350,
            'longitude' => 13.4250,
            'venue_type' => VenueType::Other,
        ]);

        $summary = app(CityDirectoryService::class)->resolveCity('berlin');

        expect($summary->verifiedVenuesCount)->toBe(2);
    });

    it('qualifies a city with no sessions through the venue threshold', function () {
        // No games at all — two verified venues is enough (min_verified_venues = 2).
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.3989, 'longitude' => 13.0657]);
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.4000, 'longitude' => 13.0700]);

        $service = app(CityDirectoryService::class);
        $summary = $service->resolveCity('potsdam');

        expect($summary)->not->toBeNull()
            ->and($summary->verifiedVenuesCount)->toBe(2)
            ->and($summary->upcomingActivityCount())->toBe(0)
            ->and($service->isQualifying($summary))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// QUALIFICATION GUARD
// ═══════════════════════════════════════════════════════════

describe('qualification guard', function () {
    it('rejects a city below both thresholds', function () {
        $berlin = cityLocation('Berlin', 52.5200, 13.4050);
        upcomingGame($berlin);
        upcomingGame($berlin); // 2 < min_upcoming_sessions (3)

        createVerifiedVenue([ // 1 < min_verified_venues (2)
            'city' => 'Berlin',
            'latitude' => 52.5250,
            'longitude' => 13.4100,
        ]);

        $service = app(CityDirectoryService::class);
        $summary = $service->resolveCity('berlin');

        expect($service->isQualifying($summary))->toBeFalse();
    });

    it('reads thresholds from config', function () {
        $berlin = cityLocation('Berlin', 52.5200, 13.4050);
        upcomingGame($berlin);

        createVerifiedVenue(['city' => 'Berlin', 'latitude' => 52.5250, 'longitude' => 13.4100]);

        $service = app(CityDirectoryService::class);
        $summary = $service->resolveCity('berlin');

        // Default thresholds: 1 game < 3, 1 venue < 2 → not qualifying.
        expect($service->isQualifying($summary))->toBeFalse();

        config(['cityhubs.min_verified_venues' => 1]);
        expect($service->isQualifying($summary))->toBeTrue(); // venue path

        config(['cityhubs.min_verified_venues' => 2, 'cityhubs.min_upcoming_sessions' => 1]);
        expect($service->isQualifying($summary))->toBeTrue(); // sessions path
    });
});

// ═══════════════════════════════════════════════════════════
// QUALIFYING CITIES
// ═══════════════════════════════════════════════════════════

describe('qualifyingCities', function () {
    it('returns only cities meeting a threshold', function () {
        // Berlin: 3 upcoming games → qualifies via sessions.
        $berlin = cityLocation('Berlin', 52.5200, 13.4050);
        upcomingGame($berlin);
        upcomingGame($berlin);
        upcomingGame($berlin);

        // Hamburg: 1 game → below both thresholds.
        $hamburg = cityLocation('Hamburg', 53.5511, 9.9937);
        upcomingGame($hamburg);

        // Cologne: no games, 2 verified venues → qualifies via venues.
        createVerifiedVenue(['city' => 'Cologne', 'latitude' => 50.9375, 'longitude' => 6.9603]);
        createVerifiedVenue(['city' => 'Cologne', 'latitude' => 50.9400, 'longitude' => 6.9650]);

        $slugs = app(CityDirectoryService::class)
            ->qualifyingCities()
            ->map(fn ($summary) => $summary->slug)
            ->sort()
            ->values()
            ->all();

        expect($slugs)->toEqual(['berlin', 'cologne']);
    });
});

// ═══════════════════════════════════════════════════════════
// CACHING
// ═══════════════════════════════════════════════════════════

describe('per-city summary caching', function () {
    it('serves repeat resolutions from the cache until invalidated', function () {
        $berlin = cityLocation('Berlin', 52.5200, 13.4050);
        upcomingGame($berlin);
        upcomingGame($berlin);
        upcomingGame($berlin);

        $service = app(CityDirectoryService::class);

        expect($service->resolveCity('berlin')->upcomingGamesCount)->toBe(3);
        // Repeat resolution with no intervening save: served from cache.
        expect($service->resolveCity('berlin')->upcomingGamesCount)->toBe(3);

        // New activity surfaces immediately: since 62-03-T04 the
        // CityHubCacheObserver flushes the affected summary on every
        // Game/Event/Location save — the TTL is no longer the staleness
        // bound for those models (campaign-side drift still rides it).
        upcomingGame($berlin);
        upcomingGame($berlin);

        expect($service->resolveCity('berlin')->upcomingGamesCount)->toBe(5);
    });

    it('caches negative resolutions so 404 traffic does not re-query', function () {
        $service = app(CityDirectoryService::class);

        expect($service->resolveCity('kiel'))->toBeNull();

        // Repeat miss served from the negative cache.
        expect($service->resolveCity('kiel'))->toBeNull()
            ->and($service->resolveStatus('kiel'))->toBe(CityDirectoryService::STATUS_NOT_FOUND);

        // The city springs into existence after the miss was cached. The
        // Location/Game saves flush the negative entry (62-03-T04), so the
        // city surfaces immediately instead of lingering until the TTL.
        $kiel = cityLocation('Kiel', 54.3233, 10.1394);
        upcomingGame($kiel);
        upcomingGame($kiel);
        upcomingGame($kiel);

        expect($service->resolveCity('kiel'))->not->toBeNull()
            ->and($service->resolveCity('kiel')->upcomingGamesCount)->toBe(3);
    });

    it('returns an identical summary across repeated resolutions', function () {
        $berlin = cityLocation('Berlin', 52.5200, 13.4050);
        upcomingGame($berlin);
        upcomingGame($berlin);
        upcomingGame($berlin);

        $service = app(CityDirectoryService::class);
        $first = $service->resolveCity('berlin');

        // Consistent across requests: the cached round-trip
        // (toArray/fromArray) must be lossless and stable, whether the
        // summary came fresh from the DB or from the cache store (Redis
        // in production, array under phpunit — expiry semantics are
        // store-agnostic and asserted in the TTL test below).
        expect($service->resolveCity('berlin')->toArray())->toEqual($first->toArray());
    });

    it('expires the per-city summary after the configured TTL and recomputes', function () {
        config(['cityhubs.cache_ttl' => 60]);

        $berlin = cityLocation('Berlin', 52.5200, 13.4050);
        upcomingGame($berlin);
        upcomingGame($berlin);
        upcomingGame($berlin);

        $service = app(CityDirectoryService::class);

        expect($service->resolveCity('berlin')->upcomingGamesCount)->toBe(3);

        // Drift that bypasses model events (mass QueryBuilder update —
        // the same class as campaign-side changes, which deliberately
        // ride the TTL): nothing flushes the summary, so inside the TTL
        // the cached summary still wins.
        Game::query()->where('location_id', $berlin->id)->update(['date_time' => now()->subDay()]);

        expect($service->resolveCity('berlin')->upcomingGamesCount)->toBe(3);

        $this->travelTo(now()->addSeconds(61));

        expect($service->resolveCity('berlin')->upcomingGamesCount)->toBe(0);
    });

    it('keeps the hub cache TTL aligned with the discovery cache TTL', function () {
        // Same staleness class as discovery (T05 contract): both default
        // to 900s and share the env-tuning pattern. Drift here changes
        // how stale a hub can be relative to every discovery page.
        expect((int) config('cityhubs.cache_ttl'))->toBe((int) config('discovery.cache_ttl'));
    });
});
