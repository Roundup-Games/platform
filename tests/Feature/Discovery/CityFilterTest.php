<?php

namespace Tests\Feature\Discovery;

use App\Livewire\Discovery\AdventuresDiscovery;
use App\Livewire\Discovery\BoardGamesDiscovery;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

use function Pest\Laravel\get;

// The ?city= URL filter on both discovery forks (M062 62-03). A qualifying
// ?city= filters results to the city cluster via CityDirectoryService (single
// authority) and folds the page canonical to that city's hub — all other
// params dropped (MEM997) — with hreflang alternates pointing at the hub URLs.
// Unknown / ambiguous / non-qualifying slugs are inert: 200, unfiltered
// results, and the transformer's path-derived canonical/alternates.
//
// geohash_4 is recomputed from lat/lng on save, so tests control coordinates
// and never set geohash_4 directly (same convention as CityHubPageTest /
// CityDirectoryServiceTest). Fixed DACH coordinates pin cluster regions:
//   Koeln  50.9375/6.9603  -> u1j region
//   Berlin 52.5200/13.4050 -> u33 region
//   Bonn   50.7375/7.0984  -> u1j region (distinct slug, so no ambiguity)

// Helpers carry a cityFilter prefix: CityHubPageTest defines the same shapes
// under a cityHub prefix and CityDirectoryServiceTest unprefixed — two
// same-named globals in one Pest process fatal ("cannot redeclare function").
function cityFilterLocation(string $city, float $lat, float $lng, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
}

function cityFilterUpcomingGame(?Location $location, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'location_id' => $location?->id,
        'date_time' => now()->addDays(3),
        // mountManagesDiscoveryFilters defaults the language filter to the
        // request locale, and applySharedFilters filters on it — the pages
        // under test are the de forks, so seeded entities must match.
        'language' => 'de',
    ], $overrides));
}

/** Qualifying Koeln cluster: 3 upcoming public games (min_upcoming_sessions). */
function cityFilterQualifyingKoeln(): Location
{
    $koeln = cityFilterLocation('Koeln', 50.9375, 6.9603);
    // Cards render the translatable name for the request locale, so seeded
    // names carry both app locales — assertions stay locale-independent.
    cityFilterUpcomingGame($koeln, ['name' => ['en' => 'Koeln Qualifier 01', 'de' => 'Koeln Qualifier 01']]);
    cityFilterUpcomingGame($koeln, ['name' => ['en' => 'Koeln Qualifier 02', 'de' => 'Koeln Qualifier 02']]);
    cityFilterUpcomingGame($koeln, ['name' => ['en' => 'Koeln Qualifier 03', 'de' => 'Koeln Qualifier 03']]);

    return $koeln;
}

beforeEach(function () {
    // City resolutions (positive AND negative) are cached; flush per test.
    Cache::flush();
});

// ═══════════════════════════════════════════════════════════
// RESULT FILTERING
// ═══════════════════════════════════════════════════════════

describe('CityFilter board-games results', function () {
    it('filters games to the city cluster via ?city=', function () {
        cityFilterQualifyingKoeln();
        $berlin = cityFilterLocation('Berlin', 52.5200, 13.4050);
        cityFilterUpcomingGame($berlin, ['name' => ['en' => 'Berlin Away Game', 'de' => 'Berlin Away Game']]);

        get(route('discover.board-games', 'de').'?city=koeln')
            ->assertOk()
            ->assertSee('Koeln Qualifier 01')
            ->assertSee('Koeln Qualifier 03')
            ->assertDontSee('Berlin Away Game');
    });
});

describe('CityFilter adventures results', function () {
    it('filters campaigns to those with a session inside the city cluster', function () {
        $ttrpg = GameSystem::factory()->create(['type' => 'ttrpg']);
        $koeln = cityFilterQualifyingKoeln();
        $berlin = cityFilterLocation('Berlin', 52.5200, 13.4050);

        // Cologne campaign: a scheduled session at a cluster location.
        $cologneCampaign = Campaign::factory()->create([
            'name' => ['en' => 'Koeln Campaign', 'de' => 'Koeln Campaign'],
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $ttrpg->id,
            'language' => 'de',
        ]);
        cityFilterUpcomingGame($koeln, ['campaign_id' => $cologneCampaign->id]);

        // Berlin campaign: all sessions at a Berlin location.
        $berlinCampaign = Campaign::factory()->create([
            'name' => ['en' => 'Berlin Campaign', 'de' => 'Berlin Campaign'],
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $ttrpg->id,
            'language' => 'de',
        ]);
        cityFilterUpcomingGame($berlin, ['campaign_id' => $berlinCampaign->id]);

        get(route('discover.adventures', 'de').'?city=koeln')
            ->assertOk()
            ->assertSee('Koeln Campaign')
            ->assertDontSee('Berlin Campaign');
    });
});

// ═══════════════════════════════════════════════════════════
// CANONICAL FOLDING + HREFLANG
// ═══════════════════════════════════════════════════════════

// Tag literals follow the package's attribute order (rel → hreflang → href);
// expected URLs come from route() — never hardcoded hosts — so app.url config
// changes cannot silently break these assertions (same convention as the
// CityHubPage canonical + hreflang block).
describe('CityFilter canonical + hreflang', function () {
    it('folds the canonical to the city hub under ?city=', function () {
        cityFilterQualifyingKoeln();

        $deHub = route('city-hubs.show', ['locale' => 'de', 'slug' => 'koeln']);
        $enHub = route('city-hubs.show', ['locale' => 'en', 'slug' => 'koeln']);

        $response = get(route('discover.board-games', 'de').'?city=koeln')->assertOk();

        $response->assertSee('<link rel="canonical" href="'.$deHub.'">', false);
        $response->assertSee('<link rel="alternate" hreflang="en" href="'.$enHub.'">', false);
        $response->assertSee('<link rel="alternate" hreflang="de" href="'.$deHub.'">', false);
        // x-default targets the first configured locale (en), not the request locale.
        $response->assertSee('<link rel="alternate" hreflang="x-default" href="'.$enHub.'">', false);
        // One canonical only: the explicit value must override, not duplicate.
        expect(substr_count($response->content(), 'rel="canonical"'))->toBe(1);
    });

    it('keeps the hub canonical when other filter params ride along (MEM997: all other params dropped)', function () {
        cityFilterQualifyingKoeln();

        $deHub = route('city-hubs.show', ['locale' => 'de', 'slug' => 'koeln']);

        $response = get(route('discover.board-games', 'de').'?city=koeln&q=x&radius=50')->assertOk();

        $response->assertSee('<link rel="canonical" href="'.$deHub.'">', false);
        $response->assertDontSee('<link rel="canonical" href="'.$deHub.'?city=koeln">', false);
        expect(substr_count($response->content(), 'rel="canonical"'))->toBe(1);
    });

    it('folds the adventures fork identically', function () {
        cityFilterQualifyingKoeln();

        $deHub = route('city-hubs.show', ['locale' => 'de', 'slug' => 'koeln']);
        $enHub = route('city-hubs.show', ['locale' => 'en', 'slug' => 'koeln']);

        $response = get(route('discover.adventures', 'de').'?city=koeln')->assertOk();

        $response->assertSee('<link rel="canonical" href="'.$deHub.'">', false);
        $response->assertSee('<link rel="alternate" hreflang="en" href="'.$enHub.'">', false);
        $response->assertSee('<link rel="alternate" hreflang="de" href="'.$deHub.'">', false);
        $response->assertSee('<link rel="alternate" hreflang="x-default" href="'.$enHub.'">', false);
        expect(substr_count($response->content(), 'rel="canonical"'))->toBe(1);
    });
});

// ═══════════════════════════════════════════════════════════
// INERT SLUGS — unknown and non-qualifying
// ═══════════════════════════════════════════════════════════

describe('CityFilter inert slugs', function () {
    it('leaves an unknown city slug unfiltered with the path-derived canonical', function () {
        cityFilterQualifyingKoeln();
        $berlin = cityFilterLocation('Berlin', 52.5200, 13.4050);
        cityFilterUpcomingGame($berlin, ['name' => ['en' => 'Berlin Away Game', 'de' => 'Berlin Away Game']]);

        $plainPath = route('discover.board-games', 'de');

        $response = get(route('discover.board-games', 'de').'?city=atlantis')->assertOk();

        // Results unfiltered: both cities' games listed.
        $response->assertSee('Koeln Qualifier 01')
            ->assertSee('Berlin Away Game');
        // Canonical is the plain discovery path (transformer default)…
        $response->assertSee('<link rel="canonical" href="'.$plainPath.'">', false);
        // …and alternates point at the discovery path, never at hubs.
        $response->assertSee('<link rel="alternate" hreflang="de" href="'.$plainPath.'">', false);
        $response->assertDontSee(route('city-hubs.show', ['locale' => 'de', 'slug' => 'koeln']), false);
        $response->assertDontSee(route('city-hubs.show', ['locale' => 'en', 'slug' => 'koeln']), false);
        expect(substr_count($response->content(), 'rel="canonical"'))->toBe(1);
    });

    it('leaves a resolvable but non-qualifying city slug inert', function () {
        $bonn = cityFilterLocation('Bonn', 50.7375, 7.0984);
        cityFilterUpcomingGame($bonn, ['name' => ['en' => 'Bonn Only Game', 'de' => 'Bonn Only Game']]);
        $berlin = cityFilterLocation('Berlin', 52.5200, 13.4050);
        cityFilterUpcomingGame($berlin, ['name' => ['en' => 'Berlin Away Game', 'de' => 'Berlin Away Game']]);

        $plainPath = route('discover.board-games', 'de');

        $response = get(route('discover.board-games', 'de').'?city=bonn')->assertOk();

        // 1 session < min_upcoming_sessions and 0 venues: no hub exists, so no
        // filtering (Berlin's game stays listed) and no hub canonical.
        $response->assertSee('Bonn Only Game')
            ->assertSee('Berlin Away Game');
        $response->assertSee('<link rel="canonical" href="'.$plainPath.'">', false);
        $response->assertDontSee('/cities/', false);
        expect(substr_count($response->content(), 'rel="canonical"'))->toBe(1);
    });
});

// ═══════════════════════════════════════════════════════════
// LIVEWIRE FILTER LIFECYCLE
// ═══════════════════════════════════════════════════════════

describe('CityFilter Livewire lifecycle', function () {
    it('marks the board-games filter active, resets displayCount on update, and clears city', function () {
        cityFilterQualifyingKoeln();

        $component = Livewire::test(BoardGamesDiscovery::class);
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        // updatingCity resets the feed window, like every other filter hook.
        $component->set('displayCount', 24)
            ->set('city', 'koeln')
            ->assertSet('displayCount', 12);
        expect($component->instance()->hasActiveFilters())->toBeTrue();

        $component->instance()->clearFilters();
        expect($component->instance()->city)->toBeNull()
            ->and($component->instance()->hasActiveFilters())->toBeFalse();
    });

    it('marks the adventures filter active and clears city', function () {
        cityFilterQualifyingKoeln();

        $component = Livewire::test(AdventuresDiscovery::class);
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        $component->set('city', 'koeln');
        expect($component->instance()->hasActiveFilters())->toBeTrue();

        $component->instance()->clearFilters();
        expect($component->instance()->city)->toBeNull()
            ->and($component->instance()->hasActiveFilters())->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// HUB INTEGRATION — link flip
// ═══════════════════════════════════════════════════════════

describe('CityFilter hub integration', function () {
    it('links onward from the hub to the city-filtered discovery forks', function () {
        cityFilterQualifyingKoeln();

        get(route('city-hubs.show', ['locale' => 'de', 'slug' => 'koeln']))
            ->assertOk()
            ->assertSee('discover/board-games?city=koeln', false)
            ->assertSee('discover/adventures?city=koeln', false);
    });
});
