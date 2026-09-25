<?php

namespace Tests\Feature\Livewire;

use App\Models\Game;
use App\Models\Location;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

// CityHubPage (M062 T02): the /cities/{slug} route and its qualification
// guard. A hub renders only when CityDirectoryService resolves the slug to a
// single city cluster meeting an activity threshold; every other path is a
// real 404 — never a soft empty page.
//
// geohash_4 is recomputed from lat/lng on save, so tests control coordinates
// and never set geohash_4 directly (same convention as
// CityDirectoryServiceTest). Fixed DACH coordinates pin cluster regions:
//   Berlin  52.5200/13.4050 -> u33 region
//   Hamburg 53.5511/9.9937  -> u1x region
//   Munich  48.1351/11.5820 -> u28 region

// Helpers carry a city-hub prefix: CityDirectoryServiceTest defines the same
// shapes unprefixed, and two same-named globals in one Pest process fatal
// ("cannot redeclare function") — see the createVerifiedVenue note in Pest.php.
function cityHubLocation(string $city, float $lat, float $lng, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
}

function cityHubUpcomingGame(?Location $location, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'location_id' => $location?->id,
        'date_time' => now()->addDays(3),
    ], $overrides));
}

/** Berlin cluster with 3 upcoming public games — qualifies via sessions. */
function cityHubQualifyingBerlin(): Location
{
    $berlin = cityHubLocation('Berlin', 52.5200, 13.4050);
    cityHubUpcomingGame($berlin);
    cityHubUpcomingGame($berlin);
    cityHubUpcomingGame($berlin);

    return $berlin;
}

beforeEach(function () {
    // Resolutions (positive AND negative) are cached; flush per test for isolation.
    Cache::flush();
});

// ═══════════════════════════════════════════════════════════
// RENDER — qualifying cities
// ═══════════════════════════════════════════════════════════

describe('CityHubPage render', function () {
    it('renders the hub for a qualifying city with translated heading and section shells', function () {
        cityHubQualifyingBerlin();

        $response = get(route('city-hubs.show', ['slug' => 'berlin']));

        $response->assertOk();
        $response->assertSee('Berlin');
        $response->assertSee(__('city-hubs.heading', ['city' => 'Berlin']));
        $response->assertSee(__('city-hubs.sections.upcoming_sessions'));
        $response->assertSee(__('city-hubs.sections.venues'));
    });

    it('renders live activity counts from the guarded summary', function () {
        cityHubQualifyingBerlin();

        $response = get(route('city-hubs.show', ['slug' => 'berlin']));

        $response->assertOk();
        $response->assertSee(__('city-hubs.stats.upcoming_sessions', ['count' => 3]));
    });

    it('qualifies through the verified-venue threshold with no sessions', function () {
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.3989, 'longitude' => 13.0657]);
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.4000, 'longitude' => 13.0700]);

        get(route('city-hubs.show', ['slug' => 'potsdam']))
            ->assertOk()
            ->assertSee(__('city-hubs.stats.verified_venues', ['count' => 2]));
    });

    it('renders the German heading and sections under the de locale', function () {
        cityHubQualifyingBerlin();

        $response = get(route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']));

        $response->assertOk();
        $response->assertSee(__('city-hubs.heading', ['city' => 'Berlin'], 'de'));
        $response->assertSee(__('city-hubs.sections.venues', [], 'de'));
    });

    it('renders the canonical city name for an umlaut city from its ASCII slug', function () {
        $munich = cityHubLocation('München', 48.1351, 11.5820);
        cityHubUpcomingGame($munich);
        cityHubUpcomingGame($munich);
        cityHubUpcomingGame($munich);

        get(route('city-hubs.show', ['slug' => 'munchen']))
            ->assertOk()
            ->assertSee('München')
            ->assertSee(__('city-hubs.heading', ['city' => 'München']));
    });
});

// ═══════════════════════════════════════════════════════════
// 404 GUARD
// ═══════════════════════════════════════════════════════════

describe('CityHubPage 404 guard', function () {
    it('404s an unknown city slug', function () {
        get(route('city-hubs.show', ['slug' => 'no-such-city']))->assertNotFound();
    });

    it('404s a city below both thresholds', function () {
        $berlin = cityHubLocation('Berlin', 52.5200, 13.4050);
        cityHubUpcomingGame($berlin);
        cityHubUpcomingGame($berlin); // 2 sessions < min_upcoming_sessions (3)

        createVerifiedVenue(['city' => 'Berlin', 'latitude' => 52.5250, 'longitude' => 13.4100]); // 1 venue < min_verified_venues (2)

        get(route('city-hubs.show', ['slug' => 'berlin']))->assertNotFound();
    });

    it('404s an ambiguous city name across regions', function () {
        cityHubLocation('Neustadt', 52.5200, 13.4050); // u33 (Berlin area)
        cityHubLocation('Neustadt', 53.5511, 9.9937); // u1x (Hamburg area)
        cityHubUpcomingGame(cityHubLocation('Neustadt', 52.5300, 13.4100));

        get(route('city-hubs.show', ['slug' => 'neustadt']))->assertNotFound();
    });

    it('404s slugs outside the route regex', function () {
        // Underscores never match [a-zA-Z0-9\-]+ (same shape as game-systems).
        get('/en/cities/not_a_city')->assertNotFound();
    });
});
