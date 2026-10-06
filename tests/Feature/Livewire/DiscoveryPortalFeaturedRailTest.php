<?php

namespace Tests\Feature\Livewire;

use App\Models\City;
use App\Models\Game;
use App\Models\Location;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

// DiscoveryPortal featured-cities rail (62-04 T06): admin-featured cities
// surfaced on /discover as hub link chips. The rail iterates
// CityDirectoryService::featuredCities(), which resolves every featured row
// through the same cached resolution the hub guard uses — so hide/feature
// semantics come from one place (MEM1023) and curation never conjures a hub:
// a featured row whose slug does not resolve to a servable cluster drops out.
//
// geohash_4 is recomputed from lat/lng on save (PG trigger), so tests control
// coordinates and never set geohash_4 directly (same convention as
// CityHubPageTest). Fixed DACH coordinates pin the cluster regions:
//   Berlin  52.5200/13.4050 -> u33 region
//   Hamburg 53.5511/9.9937  -> u1x region

// Helpers carry a discovery-rail prefix: CityHubPageTest defines the same
// shapes under a city-hub prefix in this namespace, and two same-named
// functions in one Pest process fatal ("cannot redeclare function") — see
// the createVerifiedVenue note in Pest.php.
function discoveryRailLocation(string $city, float $lat, float $lng, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
}

function discoveryRailUpcomingGame(?Location $location, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'location_id' => $location?->id,
        'date_time' => now()->addDays(3),
    ], $overrides));
}

/** Berlin cluster with $games upcoming public games (3 by default — qualifies via sessions). */
function discoveryRailBerlin(int $games = 3): Location
{
    $berlin = discoveryRailLocation('Berlin', 52.5200, 13.4050);

    for ($i = 0; $i < $games; $i++) {
        discoveryRailUpcomingGame($berlin);
    }

    return $berlin;
}

beforeEach(function () {
    // Resolutions (positive AND negative) are cached; flush per test for isolation.
    Cache::flush();
});

describe('DiscoveryPortal featured-cities rail', function () {
    it('renders a featured qualifying city as a hub link chip labeled with its display name', function () {
        discoveryRailBerlin(); // 3 sessions: qualifies without curation
        City::factory()->featured()->create(['slug' => 'berlin', 'city' => 'Berlin']);

        get(route('discover'))
            ->assertOk()
            ->assertSee(__('discovery.content_featured_city_hubs'))
            ->assertSee(route('city-hubs.show', ['locale' => 'en', 'slug' => 'berlin']), false)
            ->assertSee(__('discovery.action_view_city_hub', ['city' => 'Berlin']));
    });

    it('omits a featured-but-hidden city', function () {
        discoveryRailBerlin(); // would qualify on its own; hidden wins over featured
        City::factory()->featured()->hidden()->create(['slug' => 'berlin', 'city' => 'Berlin']);

        get(route('discover'))
            ->assertOk()
            ->assertDontSee(__('discovery.content_featured_city_hubs'))
            ->assertDontSee(route('city-hubs.show', ['locale' => 'en', 'slug' => 'berlin']), false);
    });

    it('renders a featured city below both thresholds via force-qualification', function () {
        discoveryRailBerlin(1); // 1 session < 3, 0 verified venues < 2
        City::factory()->featured()->create(['slug' => 'berlin', 'city' => 'Berlin']);

        get(route('discover'))
            ->assertOk()
            ->assertSee(route('city-hubs.show', ['locale' => 'en', 'slug' => 'berlin']), false);
    });

    it('omits a qualifying city that has no featured curation row', function () {
        discoveryRailBerlin(); // qualifies via sessions, but the curated row is unfeatured
        City::factory()->create(['slug' => 'berlin', 'city' => 'Berlin']);

        get(route('discover'))
            ->assertOk()
            ->assertDontSee(__('discovery.content_featured_city_hubs'))
            ->assertDontSee('/en/cities/berlin');
    });

    it('omits a featured curated slug with no resolvable hub', function () {
        // Curation never conjures a hub: a featured row for a slug with no
        // locations resolves null and drops out of the rail.
        City::factory()->featured()->create(['slug' => 'bremen', 'city' => 'Bremen']);

        get(route('discover'))
            ->assertOk()
            ->assertDontSee(__('discovery.content_featured_city_hubs'))
            ->assertDontSee('/en/cities/bremen');
    });

    it('renders no rail markup at all when the set is empty', function () {
        get(route('discover'))
            ->assertOk()
            ->assertDontSee(__('discovery.content_featured_city_hubs'))
            ->assertDontSee(__('discovery.content_featured_city_hubs_teaser'))
            // The chip icon and any hub path only render inside the rail.
            ->assertDontSee('location_city')
            ->assertDontSee('/cities/');
    });

    it('renders the heading copy per locale', function () {
        discoveryRailBerlin();
        City::factory()->featured()->create(['slug' => 'berlin', 'city' => 'Berlin']);

        get(route('discover', ['locale' => 'en']))
            ->assertOk()
            ->assertSee(__('discovery.content_featured_city_hubs', [], 'en'))
            ->assertDontSee(__('discovery.content_featured_city_hubs', [], 'de'));

        get(route('discover', ['locale' => 'de']))
            ->assertOk()
            ->assertSee(__('discovery.content_featured_city_hubs', [], 'de'))
            ->assertDontSee(__('discovery.content_featured_city_hubs', [], 'en'));
    });
});
