<?php

use App\Models\City;
use App\Models\Game;
use App\Models\Location;
use App\Services\CityDirectoryService;
use App\Services\SeoCacheService;
use Illuminate\Support\Facades\Cache;

/**
 * cityhubs:recompute (M062 / 62-04-T07): re-warms every known city summary
 * cache, refreshes curated-city snapshot columns, and invalidates the
 * cities sitemap — the scheduled freshness path for drift that bypasses
 * model events (mass updates, campaign-session changes) and therefore
 * rides neither the observer (62-03-T04) nor per-request caching (62-01).
 *
 * Helpers are top-level with a recompute- prefix: sibling Console tests
 * share the global namespace, so names must not collide with
 * seedDriftSweepFixtures() etc. (LocationDriftSweepTest's convention).
 *
 * Coordinates pin geohash regions deterministically (same map as
 * CityDirectoryServiceTest): Berlin 52.5200/13.4050 -> u33,
 * Hamburg 53.5511/9.9937 -> u1x.
 */
function recomputeCityLocation(string $city, float $lat, float $lng, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
}

function recomputeUpcomingGame(?Location $location, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'location_id' => $location?->id,
        'date_time' => now()->addDays(3),
    ], $overrides));
}

beforeEach(function () {
    Cache::flush();
});

describe('cityhubs:recompute command', function () {
    it('re-warms a stale summary cache with current data in both directions', function () {
        $berlin = recomputeCityLocation('Berlin', 52.5200, 13.4050);
        recomputeUpcomingGame($berlin);
        recomputeUpcomingGame($berlin);
        recomputeUpcomingGame($berlin);

        $service = app(CityDirectoryService::class);

        expect($service->resolveCity('berlin')->upcomingGamesCount)->toBe(3);

        // Drift that bypasses model events (mass QueryBuilder update — the
        // campaign-side class): nothing flushes the summary, so inside the
        // TTL the cached counts still win.
        Game::query()->where('location_id', $berlin->id)->update(['date_time' => now()->subDay()]);

        expect($service->resolveCity('berlin')->upcomingGamesCount)->toBe(3);

        $this->artisan('cityhubs:recompute')
            ->assertSuccessful()
            ->expectsOutputToContain('Recomputed');

        // Post-run resolution reflects current data: the sessions aged out.
        expect($service->resolveCity('berlin')->upcomingGamesCount)->toBe(0);

        // And back up: new activity added via another eventless mass update
        // is surfaced by a second run (the re-warm is not one-shot).
        Game::query()->where('location_id', $berlin->id)->update(['date_time' => now()->addDays(2)]);

        expect($service->resolveCity('berlin')->upcomingGamesCount)->toBe(0);

        $this->artisan('cityhubs:recompute')->assertSuccessful();

        expect($service->resolveCity('berlin')->upcomingGamesCount)->toBe(3);
    });

    it('updates curated city snapshot columns from the recomputed summary, surgically', function () {
        $berlin = recomputeCityLocation('Berlin', 52.5200, 13.4050);
        recomputeUpcomingGame($berlin);
        recomputeUpcomingGame($berlin);
        recomputeUpcomingGame($berlin);

        createVerifiedVenue(['city' => 'Berlin', 'latitude' => 52.5250, 'longitude' => 13.4100]);
        createVerifiedVenue(['city' => 'Berlin', 'latitude' => 52.5300, 'longitude' => 13.4200]);

        $city = City::factory()
            ->withIntro('Berlin intro EN', 'Berlin Intro DE')
            ->create([
                'slug' => 'berlin',
                'city' => 'Berlin',
                // Stale sentinel values proving the run overwrites them.
                'upcoming_activity_count' => 99,
                'verified_venues_count' => 99,
                'recomputed_at' => null,
            ]);

        $this->artisan('cityhubs:recompute')->assertSuccessful();

        $fresh = $city->fresh();

        expect($fresh->upcoming_activity_count)->toBe(3)
            ->and($fresh->verified_venues_count)->toBe(2)
            ->and($fresh->recomputed_at)->not->toBeNull();

        // Surgical: only the snapshot columns move — curation survives
        // (toEqual: PostgreSQL jsonb reorders translation keys, 'de' < 'en').
        expect($fresh->getTranslations('intro'))->toEqual(['en' => 'Berlin intro EN', 'de' => 'Berlin Intro DE'])
            ->and($fresh->featured)->toBeFalse()
            ->and($fresh->hidden)->toBeFalse();
    });

    it('drops a hidden city from the post-run qualifying set while a visible one stays', function () {
        $berlin = recomputeCityLocation('Berlin', 52.5200, 13.4050);
        recomputeUpcomingGame($berlin);
        recomputeUpcomingGame($berlin);
        recomputeUpcomingGame($berlin);

        // Naturally qualifying on sessions, but curated hidden — hidden
        // beats every other flag on every public surface (62-04).
        $hamburg = recomputeCityLocation('Hamburg', 53.5511, 9.9937);
        recomputeUpcomingGame($hamburg);
        recomputeUpcomingGame($hamburg);
        recomputeUpcomingGame($hamburg);

        City::factory()->hidden()->create(['slug' => 'hamburg', 'city' => 'Hamburg']);

        $this->artisan('cityhubs:recompute')->assertSuccessful();

        $service = app(CityDirectoryService::class);

        // The hidden sentinel is cached by the run: one cached lookup.
        expect($service->resolveCity('hamburg'))->toBeNull()
            ->and($service->resolveStatus('hamburg'))->toBe(CityDirectoryService::STATUS_HIDDEN)
            // The post-run qualifying set contains the visible city and
            // NOT the hidden one (Berlin's snapshot row is uncurated here,
            // so no City row muddies the assertion).
            ->and($service->qualifyingCities()->pluck('slug')->sort()->values()->all())->toBe(['berlin']);
    });

    it('clears the cities sitemap and sitemap index caches after the run', function () {
        // Fixtures first: Location/Game saves flush the cities sitemap and
        // index via CityHubCacheObserver (62-03-T04), so the pre-warm below
        // must come after seeding or it never survives to the assertion.
        recomputeCityLocation('Berlin', 52.5200, 13.4050);
        recomputeUpcomingGame(recomputeCityLocation('Berlin', 52.5250, 13.4100));

        $seo = app(SeoCacheService::class);

        // Pre-warm both caches the way a real request would.
        $seo->setSitemap('cities', '<urlset><url><loc>https://example.test/cities/berlin</loc></url></urlset>');
        $seo->setIndex('<sitemapindex><sitemap><loc>https://example.test/sitemap-cities.xml</loc></sitemap></sitemapindex>');

        expect($seo->getSitemap('cities'))->not->toBeNull()
            ->and($seo->getIndex())->not->toBeNull();

        $this->artisan('cityhubs:recompute')->assertSuccessful();

        expect($seo->getSitemap('cities'))->toBeNull()
            ->and($seo->getIndex())->toBeNull();
    });

    it('runs cleanly against an empty city universe', function () {
        $this->artisan('cityhubs:recompute')
            ->assertSuccessful()
            ->expectsOutputToContain('0 city summary cache(s)');
    });
});
