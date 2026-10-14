<?php

namespace Tests\Feature\Livewire;

use App\Models\City;
use App\Models\Game;
use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\get;

// CityIndexPage (M066/S06/T04): the /cities directory listing every city
// cluster CityDirectoryService currently considers hub-worthy. The page
// consumes qualifyingCities() — the same authority as the hub guard — so
// every card links to a servable hub: below-threshold and curated-hidden
// cities can never appear, and curated featured cities force-qualify
// (MEM995) and sort first.
//
// geohash_4 is recomputed from lat/lng on save, so tests control coordinates
// and never set geohash_4 directly (same convention as CityHubPageTest).
// Fixed DACH coordinates pin cluster regions:
//   Berlin  52.5200/13.4050 -> u33 region
//   Hamburg 53.5511/9.9937  -> u1x region

// Helpers carry a city-index prefix: CityHubPageTest and
// DiscoveryPortalFeaturedRailTest define the same shapes under their own
// prefixes, and two same-named globals in one Pest process fatal
// ("cannot redeclare function") — see the createVerifiedVenue note in Pest.php.
function cityIndexLocation(string $city, float $lat, float $lng, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
}

function cityIndexUpcomingGame(?Location $location, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'location_id' => $location?->id,
        'date_time' => now()->addDays(3),
    ], $overrides));
}

/** Berlin cluster with $games upcoming public games (3 by default — qualifies via sessions). */
function cityIndexQualifyingBerlin(int $games = 3): Location
{
    $berlin = cityIndexLocation('Berlin', 52.5200, 13.4050);

    for ($i = 0; $i < $games; $i++) {
        cityIndexUpcomingGame($berlin);
    }

    return $berlin;
}

beforeEach(function () {
    // Resolutions (positive AND negative) are cached; flush per test for isolation.
    Cache::flush();
});

// ═══════════════════════════════════════════════════════════
// RENDER — directory contents
// ═══════════════════════════════════════════════════════════

describe('CityIndexPage render', function () {
    it('renders the index with heading, program intro, and a hub card for a qualifying city', function () {
        cityIndexQualifyingBerlin();

        get(route('city-hubs.index'))
            ->assertOk()
            ->assertSee(__('city-hubs.heading_city_hubs_index'))
            ->assertSee(__('city-hubs.content_index_intro'))
            ->assertSee('Berlin')
            ->assertSee(route('city-hubs.show', ['locale' => 'en', 'slug' => 'berlin']), false)
            // Footer Platform group carries the /cities link on every public page.
            ->assertSee(__('city-hubs.nav_cities'));
    });

    it('renders live counts from the guarded summaries', function () {
        cityIndexQualifyingBerlin(); // 3 upcoming sessions, 0 venues
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.3989, 'longitude' => 13.0657]);
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.4000, 'longitude' => 13.0700]); // venue-qualified

        get(route('city-hubs.index'))
            ->assertOk()
            ->assertSee(__('city-hubs.label_upcoming_sessions_count', ['count' => 3]))
            ->assertSee(__('city-hubs.label_verified_venues_count', ['count' => 2]));
    });

    it('lists a curated featured city below both thresholds, featured first', function () {
        cityIndexQualifyingBerlin(); // qualifies naturally, uncurated

        $hamburg = cityIndexLocation('Hamburg', 53.5511, 9.9937);
        cityIndexUpcomingGame($hamburg); // 1 session < 3, 0 venues < 2
        City::updateOrCreate(['slug' => 'hamburg'], ['city' => 'Hamburg', 'featured' => true]);

        $response = get(route('city-hubs.index'))
            ->assertOk()
            ->assertSee('Berlin')
            ->assertSee('Hamburg')
            ->assertSee(__('city-hubs.label_featured'));

        // Featured Hamburg sorts before alphabetically-earlier Berlin.
        $content = $response->content();
        expect(strpos($content, 'Hamburg'))->toBeLessThan(strpos($content, 'Berlin'));
    });

    it('excludes a city below both thresholds', function () {
        cityIndexQualifyingBerlin();

        $hamburg = cityIndexLocation('Hamburg', 53.5511, 9.9937);
        cityIndexUpcomingGame($hamburg); // 1 session < 3, 0 venues < 2, no curation

        get(route('city-hubs.index'))
            ->assertOk()
            ->assertSee('Berlin')
            ->assertDontSee('Hamburg');
    });

    it('excludes a curated-hidden city even when featured', function () {
        cityIndexQualifyingBerlin();

        $hamburg = cityIndexLocation('Hamburg', 53.5511, 9.9937);
        for ($i = 0; $i < 3; $i++) {
            cityIndexUpcomingGame($hamburg); // would qualify on its own
        }
        City::updateOrCreate(['slug' => 'hamburg'], ['city' => 'Hamburg', 'featured' => true, 'hidden' => true]);

        get(route('city-hubs.index'))
            ->assertOk()
            ->assertSee('Berlin')
            ->assertDontSee('Hamburg')
            ->assertDontSee('/en/cities/hamburg');
    });

    it('renders the German heading under the de locale', function () {
        cityIndexQualifyingBerlin();

        get(route('city-hubs.index', ['locale' => 'de']))
            ->assertOk()
            ->assertSee(__('city-hubs.heading_city_hubs_index', [], 'de'))
            ->assertDontSee(__('city-hubs.heading_city_hubs_index', [], 'en'));
    });

    it('renders the SEO title and description', function () {
        cityIndexQualifyingBerlin();

        $response = get(route('city-hubs.index'))->assertOk();

        assertPageTitle($response, __('city-hubs.seo_title_index'));
        $response->assertSee(__('city-hubs.seo_description_index'));
    });

    it('renders the translated empty state when nothing qualifies', function () {
        get(route('city-hubs.index'))
            ->assertOk()
            ->assertSee(__('city-hubs.empty_city_index'))
            // Empty states always link onward — never a dead panel.
            ->assertSee(__('city-hubs.empty_city_index_cta'));
    });

    it('logs cityhub.index_rendered with the city count once per page load', function () {
        Log::spy();
        cityIndexQualifyingBerlin();

        get(route('city-hubs.index'))->assertOk();

        Log::shouldHaveReceived('info')
            ->with('cityhub.index_rendered', ['city_count' => 1])
            ->once();
    });
});
