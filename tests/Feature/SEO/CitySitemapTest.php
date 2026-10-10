<?php

use App\Models\Game;
use App\Models\Location;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

// ── Cities Sitemap (M062/62-03) ───────────────────────
//
// The cities sitemap is the crawler entry point for city hub pages. It MUST
// list exactly CityDirectoryService::qualifyingCities() — the same
// OR-threshold guard (upcoming activity OR verified venues) that gates the
// /cities/{slug} route's 404 — so no non-qualifying or ambiguous city is
// ever indexable. Per-city lastmod comes from lastModifiedFor(): the max
// updated_at across the cluster's locations and the entities feeding its
// sessions section.
//
// Conventions match CityDirectoryServiceTest: geohash_4 is recomputed from
// lat/lng on save, so tests control coordinates and never set it directly.
// Fixed DACH coordinates pin cluster regions deterministically:
//   Cologne 50.9375/6.9603  -> u1hc (region u1h)
//   Berlin  52.5200/13.4050 -> u33d (region u33)
//   Hamburg 53.5511/9.9937  -> u1x0 (region u1x)
//   Potsdam 52.3989/13.0657 -> u33 (Berlin region)
//
// Helpers carry a city-sitemap prefix: CityDirectoryServiceTest defines the
// same shapes unprefixed, and two same-named globals in one Pest process
// fatal ("cannot redeclare function") — see the createVerifiedVenue note in
// Pest.php.

beforeEach(function () {
    // Each test builds fresh entries; never serve a stale cached sitemap.
    Cache::flush();
});

function citySitemapLocation(string $city, float $lat, float $lng, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
}

function citySitemapUpcomingGame(?Location $location, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'location_id' => $location?->id,
        'date_time' => now()->addDays(3),
    ], $overrides));
}

describe('Cities Sitemap — inclusion', function () {
    it('includes a sessions-qualified city with both locale URLs, correct flags, and per-city lastmod', function () {
        $cologne = citySitemapLocation('Koeln', 50.9375, 6.9603);
        $games = collect([
            citySitemapUpcomingGame($cologne),
            citySitemapUpcomingGame($cologne),
            citySitemapUpcomingGame($cologne), // 3 upcoming sessions → qualifies
        ]);

        // Pin timestamps deterministically: cluster and games old, one game
        // newest — lastmod must equal that game's date, not merely "today".
        $older = now()->subDays(20)->setTime(9, 0);
        $newest = now()->subDays(5)->setTime(10, 30);

        Location::whereKey($cologne)->update(['updated_at' => $older]);
        Game::whereKey($games->pluck('id')->all())->update(['updated_at' => $older]);
        Game::whereKey($games->first()->getKey())->update(['updated_at' => $newest]);

        $baseUrl = config('app.url');
        $content = get('/sitemap-cities.xml')->content();

        expect($content)->toContain("{$baseUrl}/de/cities/koeln");
        expect($content)->toContain("{$baseUrl}/en/cities/koeln");

        preg_match_all('/<url>(.*?)<\/url>/s', $content, $blocks);
        $entry = collect($blocks[0])->first(fn ($block) => str_contains($block, '/de/cities/koeln'));

        expect($entry)->not->toBeNull('koeln entry missing from cities sitemap')
            ->and($entry)->toContain('<changefreq>daily</changefreq>')
            ->and($entry)->toContain('<priority>0.7</priority>')
            ->and($entry)->toContain('<lastmod>'.$newest->toDateString().'</lastmod>');
    });

    it('includes a venue-qualified city with no sessions (OR threshold)', function () {
        // Two verified slug-bearing venues, zero games — enough on the venue arm.
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.3989, 'longitude' => 13.0657]);
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.4000, 'longitude' => 13.0700]);

        $baseUrl = config('app.url');
        $content = get('/sitemap-cities.xml')->content();

        expect($content)->toContain("{$baseUrl}/en/cities/potsdam");
        expect($content)->toContain("{$baseUrl}/de/cities/potsdam");
    });
});

describe('Cities Sitemap — exclusion', function () {
    it('excludes a non-qualifying city (activity below both thresholds)', function () {
        $hamburg = citySitemapLocation('Hamburg', 53.5511, 9.9937);
        citySitemapUpcomingGame($hamburg); // 1 session < 3, 0 venues < 2

        $content = get('/sitemap-cities.xml')->content();

        expect($content)->not->toContain('/cities/hamburg');
    });

    it('indexes same-name clusters as two distinct hub URLs (D171 registry)', function () {
        // Two German Neustadts, ~290km apart, both independently qualify —
        // each gets its own indexable URL instead of the pre-D171 404.
        $berlinArea = citySitemapLocation('Neustadt', 52.5200, 13.4050); // u33, first: bare slug
        $hamburgArea = citySitemapLocation('Neustadt', 53.5511, 9.9937); // u1x: suffixed
        for ($i = 0; $i < 3; $i++) {
            citySitemapUpcomingGame($berlinArea);
            citySitemapUpcomingGame($hamburgArea);
        }

        $content = get('/sitemap-cities.xml')->content();

        expect($content)->toContain('/cities/neustadt')
            ->and($content)->toContain('/cities/neustadt-u1x');
    });
});

describe('Cities Sitemap — index registration', function () {
    it('lists the cities sub-sitemap with a lastmod in the sitemap index', function () {
        $content = get('/sitemap.xml')->content();

        preg_match_all('/<sitemap>(.*?)<\/sitemap>/s', $content, $blocks);
        $citiesBlock = collect($blocks[0])->first(fn ($block) => str_contains($block, '/sitemap-cities.xml'));

        expect($citiesBlock)->not->toBeNull('cities sub-sitemap block missing from index')
            ->and($citiesBlock)->toContain('<lastmod>');
    });
});

describe('Cities Sitemap — caching', function () {
    it('returns byte-identical content on the second fetch (cache round-trip)', function () {
        $cologne = citySitemapLocation('Koeln', 50.9375, 6.9603);
        citySitemapUpcomingGame($cologne);
        citySitemapUpcomingGame($cologne);
        citySitemapUpcomingGame($cologne);

        $first = get('/sitemap-cities.xml')->content();
        $second = get('/sitemap-cities.xml')->content();

        expect($second)->toBe($first);
    });
});
