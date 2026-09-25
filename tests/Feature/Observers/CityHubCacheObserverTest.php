<?php

use App\Enums\GameStatus;
use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Services\SeoCacheService;

use function Pest\Laravel\get;

// ── CityHubCacheObserver (M062/62-03-T04) ─────────────
//
// The observer wires Game/Event/Location saves to CityDirectoryService::
// forget() for every affected city (BOTH original and current, since
// sessions can move between clusters and locations can rename/move) plus
// a cities-sitemap and sitemap-index flush. Until this task, the 900s
// summary TTL was the sole staleness bound (config/cityhubs.php).
//
// Conventions match SeoModelObserverTest: Cache::flush() in beforeEach,
// keys pre-seeded via SeoCacheService::setSitemap/setIndex (summary keys
// via Cache::set), asserted null after the model operation.
//
// Helper naming carries a cityHubObserver prefix: CitySitemapTest defines
// the same shapes with a citySitemap prefix — same-named globals in one
// Pest process fatal ("cannot redeclare function"). geohash_4 recomputes
// from lat/lng on save, so locations are seeded via coordinates.

beforeEach(function () {
    Cache::flush();
});

function cityHubObserverLocation(string $city, float $lat, float $lng): Location
{
    return Location::factory()->create([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ]);
}

function cityHubObserverSeedKeys(array $summarySlugs): SeoCacheService
{
    $service = app(SeoCacheService::class);
    $service->setSitemap('cities', '<test>xml</test>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');
    foreach ($summarySlugs as $slug) {
        Cache::set("city-hubs:summary:{$slug}", ['status' => 'ok', 'summary' => null]);
    }

    return $service;
}

// ── Single-model flush ────────────────────────────────

it('forgets the city summary, cities sitemap, and index when a game is saved at a cluster location', function () {
    $cologne = cityHubObserverLocation('Koeln', 50.9375, 6.9603);
    cityHubObserverSeedKeys(['koeln']);

    Game::factory()->create(['location_id' => $cologne->id]);

    expect(Cache::get('city-hubs:summary:koeln'))->toBeNull();
    expect(Cache::get('seo:sitemap:cities'))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

it('forgets both cities when a game moves between clusters', function () {
    $cologne = cityHubObserverLocation('Koeln', 50.9375, 6.9603);
    $berlin = cityHubObserverLocation('Berlin', 52.5200, 13.4050);
    cityHubObserverSeedKeys(['koeln', 'berlin']);

    $game = Game::factory()->create(['location_id' => $cologne->id]);
    Cache::set('city-hubs:summary:koeln', ['status' => 'ok', 'summary' => null]);
    Cache::set('city-hubs:summary:berlin', ['status' => 'ok', 'summary' => null]);

    $game->update(['location_id' => $berlin->id]);

    expect(Cache::get('city-hubs:summary:koeln'))->toBeNull();
    expect(Cache::get('city-hubs:summary:berlin'))->toBeNull();
    expect(Cache::get('seo:sitemap:cities'))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

it('forgets the city summary, cities sitemap, and index when an event is saved at a cluster location', function () {
    $cologne = cityHubObserverLocation('Koeln', 50.9375, 6.9603);
    cityHubObserverSeedKeys(['koeln']);

    Event::factory()->create(['location_id' => $cologne->id]);

    expect(Cache::get('city-hubs:summary:koeln'))->toBeNull();
    expect(Cache::get('seo:sitemap:cities'))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

it('forgets both cities when a location renames to another city', function () {
    // City stored as ASCII 'Koeln' (CitySitemapTest convention): the
    // seeded keys must match Str::slug of the STORED value — 'Köln' would
    // slug to 'koln' (ö transliterates to 'o'), not 'koeln'.
    $location = cityHubObserverLocation('Koeln', 50.9375, 6.9603);
    cityHubObserverSeedKeys(['koeln', 'berlin']);

    $location->update(['city' => 'Berlin']);

    expect(Cache::get('city-hubs:summary:koeln'))->toBeNull();
    expect(Cache::get('city-hubs:summary:berlin'))->toBeNull();
    expect(Cache::get('seo:sitemap:cities'))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

it('forgets the city summary and sitemap caches when a game is deleted', function () {
    $cologne = cityHubObserverLocation('Koeln', 50.9375, 6.9603);
    cityHubObserverSeedKeys(['koeln']);

    $game = Game::factory()->create(['location_id' => $cologne->id]);
    Cache::set('city-hubs:summary:koeln', ['status' => 'ok', 'summary' => null]);

    $game->delete();

    expect(Cache::get('city-hubs:summary:koeln'))->toBeNull();
    expect(Cache::get('seo:sitemap:cities'))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

// ── No-op edges ───────────────────────────────────────

it('touches no summary key for a game without a location, but still flushes sitemap and index', function () {
    cityHubObserverSeedKeys(['koeln']);

    Game::factory()->create(['location_id' => null]);

    // No derivable slug — the summary cache is untouched...
    expect(Cache::get('city-hubs:summary:koeln'))->not->toBeNull();
    // ...while the sitemap flush still fires (harmless — a locationless
    // game cannot have changed any hub's content).
    expect(Cache::get('seo:sitemap:cities'))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

it('leaves other cities summary caches intact', function () {
    $cologne = cityHubObserverLocation('Koeln', 50.9375, 6.9603);
    cityHubObserverSeedKeys(['koeln', 'hamburg']);

    Game::factory()->create(['location_id' => $cologne->id]);

    expect(Cache::get('city-hubs:summary:koeln'))->toBeNull();
    expect(Cache::get('city-hubs:summary:hamburg'))->not->toBeNull();
});

// ── Acceptance chain: drop below threshold → sitemap entry disappears ──

it('drops a city from the cities sitemap when its games are canceled (real HTTP chain)', function () {
    $cologne = cityHubObserverLocation('Koeln', 50.9375, 6.9603);
    $games = collect([
        Game::factory()->create(['location_id' => $cologne->id]),
        Game::factory()->create(['location_id' => $cologne->id]),
        Game::factory()->create(['location_id' => $cologne->id]), // 3 upcoming public → qualifies
    ]);

    // The first GET caches the XML AND the koeln summary (via
    // qualifyingCities → resolveCity). No Cache::flush after this point —
    // the observer's flush is what makes the second GET recompute.
    expect(get('/sitemap-cities.xml')->content())->toContain('/de/cities/koeln');

    // Cancel via per-model saves (mass QueryBuilder updates fire no model
    // events); each save flushes the koeln summary + cities sitemap + index.
    $games->each(fn (Game $game) => $game->update(['status' => GameStatus::Canceled]));

    // Summary recomputed below threshold → koeln no longer qualifies →
    // absent from the regenerated sitemap.
    expect(get('/sitemap-cities.xml')->content())->not->toContain('/cities/koeln');
});
