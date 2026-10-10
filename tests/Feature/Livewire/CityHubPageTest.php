<?php

namespace Tests\Feature\Livewire;

use App\Models\Campaign;
use App\Models\City;
use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Helpers\TestablePostHogClient;

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
        $response->assertSee(__('city-hubs.heading_city_hub', ['city' => 'Berlin']));
        $response->assertSee(__('city-hubs.section_upcoming_sessions'));
        $response->assertSee(__('city-hubs.section_venues'));
    });

    it('renders live activity counts from the guarded summary', function () {
        cityHubQualifyingBerlin();

        $response = get(route('city-hubs.show', ['slug' => 'berlin']));

        $response->assertOk();
        $response->assertSee(__('city-hubs.label_upcoming_sessions_count', ['count' => 3]));
    });

    it('qualifies through the verified-venue threshold with no sessions', function () {
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.3989, 'longitude' => 13.0657]);
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.4000, 'longitude' => 13.0700]);

        get(route('city-hubs.show', ['slug' => 'potsdam']))
            ->assertOk()
            ->assertSee(__('city-hubs.label_verified_venues_count', ['count' => 2]));
    });

    it('renders the German heading and sections under the de locale', function () {
        cityHubQualifyingBerlin();

        $response = get(route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']));

        $response->assertOk();
        $response->assertSee(__('city-hubs.heading_city_hub', ['city' => 'Berlin'], 'de'));
        $response->assertSee(__('city-hubs.section_venues', [], 'de'));
    });

    it('renders the canonical city name for an umlaut city from its ASCII slug', function () {
        $munich = cityHubLocation('München', 48.1351, 11.5820);
        cityHubUpcomingGame($munich);
        cityHubUpcomingGame($munich);
        cityHubUpcomingGame($munich);

        get(route('city-hubs.show', ['slug' => 'munchen']))
            ->assertOk()
            ->assertSee('München')
            ->assertSee(__('city-hubs.heading_city_hub', ['city' => 'München']));
    });

    it('switches heading and section copy between the en and de locales', function () {
        cityHubQualifyingBerlin();

        // Known literals from lang/en|de/city-hubs.php — asserting the
        // files' actual copy, not a re-derivation of it.
        $en = get(route('city-hubs.show', ['locale' => 'en', 'slug' => 'berlin']))
            ->assertOk()
            ->assertSee('Tabletop gaming in Berlin')
            ->assertSee('Upcoming Sessions')
            ->assertDontSee('Tabletop-Spiele in Berlin');

        $de = get(route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']))
            ->assertOk()
            ->assertSee('Tabletop-Spiele in Berlin')
            ->assertSee('Verifizierte Veranstaltungsorte')
            ->assertDontSee('Tabletop gaming in Berlin');
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

    it('serves same-name clusters as two independent hubs (D171 registry)', function () {
        // u33 Neustadt (created first) keeps the bare slug and qualifies;
        // the u1x Neustadt is a separate hub under its region-suffixed slug.
        $neustadt = cityHubLocation('Neustadt', 52.5200, 13.4050); // u33
        for ($i = 0; $i < 3; $i++) {
            cityHubUpcomingGame($neustadt);
        }
        cityHubLocation('Neustadt', 53.5511, 9.9937); // u1x, no activity

        get(route('city-hubs.show', ['slug' => 'neustadt']))->assertOk();
        get(route('city-hubs.show', ['slug' => 'neustadt-u1x']))->assertNotFound(); // below threshold
    });

    it('404s slugs outside the route regex', function () {
        // Underscores never match [a-zA-Z0-9\-]+ (same shape as game-systems).
        get('/en/cities/not_a_city')->assertNotFound();
    });

    it('serves both independently qualifying same-name clusters under their own URLs', function () {
        // Both Neustadts qualify alone — post-D171 both are live hubs,
        // never merged: the bare slug serves the first-provisioned cluster.
        $berlinArea = cityHubLocation('Neustadt', 52.5200, 13.4050); // u33, first
        $hamburgArea = cityHubLocation('Neustadt', 53.5511, 9.9937); // u1x
        for ($i = 0; $i < 3; $i++) {
            cityHubUpcomingGame($berlinArea);
            cityHubUpcomingGame($hamburgArea);
        }

        get(route('city-hubs.show', ['slug' => 'neustadt']))->assertOk();
        get(route('city-hubs.show', ['slug' => 'neustadt-u1x']))->assertOk();
    });

    it('starts 404ing once activity falls out of the window and the cache TTL has passed', function () {
        config(['cityhubs.cache_ttl' => 60]);

        $berlin = cityHubLocation('Berlin', 52.5200, 13.4050);
        cityHubUpcomingGame($berlin, ['date_time' => now()->addDays(3)]);
        cityHubUpcomingGame($berlin, ['date_time' => now()->addDays(4)]);
        cityHubUpcomingGame($berlin, ['date_time' => now()->addDays(5)]);

        get(route('city-hubs.show', ['slug' => 'berlin']))->assertOk();

        Log::spy();

        // Beyond the TTL (60s) and past all three session dates: the
        // recomputed summary has zero upcoming activity, so the guard
        // 404s with the below_threshold reason.
        $this->travelTo(now()->addDays(10));

        get(route('city-hubs.show', ['slug' => 'berlin']))->assertNotFound();

        Log::shouldHaveReceived('info')
            ->with('cityhub.rejected', ['slug' => 'berlin', 'reason' => 'below_threshold'])
            ->once();
    });
});

// ═════════════════════════════════════════════════════════
// HUB SECTIONS (T03) — aggregation, cards, caps, empty states, onward links
// ═════════════════════════════════════════════════════════

describe('CityHubPage hub sections', function () {
    it('lists upcoming public games, campaigns, and events in the sessions section', function () {
        $berlin = cityHubLocation('Berlin', 52.5200, 13.4050);

        cityHubUpcomingGame($berlin, ['name' => ['en' => 'Hub Session Alpha']]);
        cityHubUpcomingGame($berlin, ['name' => ['en' => 'Hub Session Beta']]);

        $campaign = Campaign::factory()->create(['name' => ['en' => 'Hub Campaign Gamma']]);
        cityHubUpcomingGame($berlin, ['campaign_id' => $campaign->id]);

        Event::factory()->create([
            'name' => ['en' => 'Hub Event Delta'],
            'location_id' => $berlin->id,
            'start_date' => now()->addDays(10),
        ]);

        get(route('city-hubs.show', ['slug' => 'berlin']))
            ->assertOk()
            ->assertSee('Hub Session Alpha')
            ->assertSee('Hub Session Beta')
            ->assertSee('Hub Campaign Gamma')
            ->assertSee('Hub Event Delta');
    });

    it('excludes private, past, and out-of-cluster items from the sections', function () {
        $berlin = cityHubLocation('Berlin', 52.5200, 13.4050);
        $hamburg = cityHubLocation('Hamburg', 53.5511, 9.9937);

        cityHubUpcomingGame($berlin, ['name' => ['en' => 'Hub Keep One']]);
        cityHubUpcomingGame($berlin, ['name' => ['en' => 'Hub Keep Two']]);
        cityHubUpcomingGame($berlin, ['name' => ['en' => 'Hub Keep Three']]);

        cityHubUpcomingGame($berlin, ['name' => ['en' => 'Hub Private Session'], 'visibility' => 'private']);
        cityHubUpcomingGame($berlin, ['name' => ['en' => 'Hub Past Session'], 'date_time' => now()->subDays(2)]);
        cityHubUpcomingGame($hamburg, ['name' => ['en' => 'Hub Hamburg Session']]);

        get(route('city-hubs.show', ['slug' => 'berlin']))
            ->assertOk()
            ->assertSee('Hub Keep One')
            ->assertDontSee('Hub Private Session')
            ->assertDontSee('Hub Past Session')
            ->assertDontSee('Hub Hamburg Session');
    });

    it('caps the sessions section at 12 items ordered chronologically', function () {
        $berlin = cityHubLocation('Berlin', 52.5200, 13.4050);

        // Zero-padded names keep assertSee substring matches unambiguous
        // ("...01" never matches "...10").
        for ($i = 1; $i <= 15; $i++) {
            cityHubUpcomingGame($berlin, [
                'name' => ['en' => sprintf('Hub Cap Session %02d', $i)],
                'date_time' => now()->addDays($i),
            ]);
        }

        $response = get(route('city-hubs.show', ['slug' => 'berlin']));
        $response->assertOk();

        // Earliest 12 render (date-ordered); the last 3 are cut by the cap.
        for ($i = 1; $i <= 12; $i++) {
            $response->assertSee(sprintf('Hub Cap Session %02d', $i));
        }
        $response->assertDontSee('Hub Cap Session 13')
            ->assertDontSee('Hub Cap Session 14')
            ->assertDontSee('Hub Cap Session 15');
    });

    it('renders the translated empty state linking to discovery when a venue-qualified city has no sessions', function () {
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.3989, 'longitude' => 13.0657]);
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.4000, 'longitude' => 13.0700]);

        get(route('city-hubs.show', ['slug' => 'potsdam']))
            ->assertOk()
            ->assertSee(__('city-hubs.empty_upcoming_sessions', ['city' => 'Potsdam']))
            ->assertSee(__('city-hubs.empty_upcoming_sessions_cta'))
            // The venue section is populated here (the city qualified via
            // venues), so its empty state must NOT render.
            ->assertDontSee(__('city-hubs.empty_venues', ['city' => 'Potsdam']));
    });

    it('lists verified venues as links to their public venue pages', function () {
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.3989, 'longitude' => 13.0657]);
        createVerifiedVenue(['city' => 'Potsdam', 'latitude' => 52.4000, 'longitude' => 13.0700]);

        $response = get(route('city-hubs.show', ['slug' => 'potsdam']));
        $response->assertOk();

        Location::query()
            ->where('city', 'Potsdam')
            ->whereNotNull('slug')
            ->pluck('slug')
            ->each(fn (string $slug) => $response->assertSee('/venue/'.$slug));
    });

    it('links onward to the discovery forks and the city-filtered venue and event lists', function () {
        cityHubQualifyingBerlin();

        get(route('city-hubs.show', ['slug' => 'berlin']))
            ->assertOk()
            ->assertSee(__('city-hubs.action_view_all_board_games'))
            ->assertSee(__('city-hubs.action_view_all_adventures'))
            ->assertSee(__('city-hubs.action_view_all_events', ['city' => 'Berlin']))
            ->assertSee('/venues?q=Berlin')
            ->assertSee('/events?q=Berlin')
            // Berlin qualified via sessions and has no venues, so the
            // venues empty state renders (translated, links onward).
            ->assertSee(__('city-hubs.empty_venues', ['city' => 'Berlin']))
            ->assertSee(__('city-hubs.empty_venues_cta'));
    });
});

// ═══════════════════════════════════════════════════════════
// SEO + ANALYTICS (T04) — per-locale SEOData, cityhub.rendered log,
// PostHog page-view, cityhub.rejected with 404 reason
// ═══════════════════════════════════════════════════════════

describe('CityHubPage SEO', function () {
    it('renders the localized SEO title and description', function () {
        cityHubQualifyingBerlin();

        $response = get(route('city-hubs.show', ['slug' => 'berlin']))->assertOk();

        assertPageTitle($response, __('city-hubs.seo_title', ['city' => 'Berlin']));
        $response->assertSee(__('city-hubs.seo_description', ['city' => 'Berlin']));
    });

    it('renders the German SEO title and description under the de locale', function () {
        cityHubQualifyingBerlin();

        $response = get(route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']))->assertOk();

        assertPageTitle($response, __('city-hubs.seo_title', ['city' => 'Berlin'], 'de'));
        $response->assertSee(__('city-hubs.seo_description', ['city' => 'Berlin'], 'de'));
    });
});

// ═══════════════════════════════════════════════════════════
// CANONICAL + HREFLANG — explicit self-canonical pins the clean hub URL;
// transformer-derived en/de/x-default alternates; unprefixed /cities/{slug}
// 302s to the locale-negotiated hub.
//
// Tag literals follow the package's attribute order (rel → hreflang → href);
// expected URLs come from route() — never hardcoded hosts — so app.url
// config changes cannot silently break these assertions.
// ═══════════════════════════════════════════════════════════

describe('CityHubPage canonical + hreflang', function () {
    it('emits the de self-canonical with en/de alternates and an en x-default', function () {
        cityHubQualifyingBerlin();

        $deHub = route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']);
        $enHub = route('city-hubs.show', ['locale' => 'en', 'slug' => 'berlin']);

        $response = get($deHub)->assertOk();

        $response->assertSee('<link rel="canonical" href="'.$deHub.'">', false);
        $response->assertSee('<link rel="alternate" hreflang="en" href="'.$enHub.'">', false);
        $response->assertSee('<link rel="alternate" hreflang="de" href="'.$deHub.'">', false);
        // x-default targets the first configured locale (en), not the de request locale.
        $response->assertSee('<link rel="alternate" hreflang="x-default" href="'.$enHub.'">', false);
        // The global transformer only fills canonical_url when null, so the
        // explicit value must not produce a duplicate canonical tag.
        expect(substr_count($response->content(), 'rel="canonical"'))->toBe(1);
    });

    it('mirrors canonical and alternates on the en hub', function () {
        cityHubQualifyingBerlin();

        $deHub = route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']);
        $enHub = route('city-hubs.show', ['locale' => 'en', 'slug' => 'berlin']);

        $response = get($enHub)->assertOk();

        $response->assertSee('<link rel="canonical" href="'.$enHub.'">', false);
        $response->assertSee('<link rel="alternate" hreflang="en" href="'.$enHub.'">', false);
        $response->assertSee('<link rel="alternate" hreflang="de" href="'.$deHub.'">', false);
        $response->assertSee('<link rel="alternate" hreflang="x-default" href="'.$enHub.'">', false);
        expect(substr_count($response->content(), 'rel="canonical"'))->toBe(1);
    });

    it('keeps the clean canonical hub URL on query-param variants', function () {
        cityHubQualifyingBerlin();

        $deHub = route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']);

        $response = get($deHub.'?utm=foo')->assertOk();

        // Explicit canonical wins: the pinned clean hub URL, no query string.
        $response->assertSee('<link rel="canonical" href="'.$deHub.'">', false);
        $response->assertDontSee('<link rel="canonical" href="'.$deHub.'?utm=foo">', false);
        expect(substr_count($response->content(), 'rel="canonical"'))->toBe(1);
    });

    it('redirects the unprefixed hub path 302 to the locale-negotiated hub', function () {
        cityHubQualifyingBerlin();

        $response = get('/cities/berlin');

        // The platform-wide locale-negotiated catch-all (session >
        // Accept-Language > fallback) 302s to /{locale}/cities/{slug} — the
        // same semantics as every other unprefixed path, so a hub-specific
        // 301-to-de would contradict the platform. Assert shape only: the
        // resolved locale depends on session/Accept-Language negotiation.
        $response->assertStatus(302);
        // Location may be relative or absolute (URL generator prefixes the
        // app root), so match the path shape only — host-agnostic.
        expect($response->headers->get('Location'))->toMatch('#^(https?://[^/]+)?/(en|de)/cities/berlin$#');
    });
});

describe('CityHubPage analytics', function () {
    it('logs cityhub.rendered with the city slug and section counts', function () {
        Log::spy();
        cityHubQualifyingBerlin();

        get(route('city-hubs.show', ['slug' => 'berlin']))->assertOk();

        Log::shouldHaveReceived('info')
            ->with('cityhub.rendered', Mockery::on(fn (array $context) => $context['slug'] === 'berlin'
                && $context['session_count'] === 3
                && $context['venue_count'] === 0))
            ->once();
    });

    it('captures a consent-gated PostHog page-view with a guest fingerprint', function () {
        $client = new TestablePostHogClient;
        $this->app->instance(PostHogClient::class, $client);
        $this->mock(PostHogConsentChecker::class)
            ->shouldReceive('hasAnalyticsConsent')
            ->andReturn(true);

        cityHubQualifyingBerlin();

        get(route('city-hubs.show', ['slug' => 'berlin']))->assertOk();

        $event = collect($client->capturedCalls)->first(fn (array $call) => $call['event'] === 'cityhub.viewed');
        expect($event)->not->toBeNull('cityhub.viewed was not captured')
            ->and($event['distinctId'])->toStartWith('cityhub:')
            ->and($event['properties']['slug'])->toBe('berlin')
            ->and($event['properties']['session_count'])->toBe(3)
            ->and($event['properties']['venue_count'])->toBe(0)
            ->and($event['properties']['is_authenticated'])->toBeFalse();
    });

    it('skips the PostHog page-view without analytics consent', function () {
        $client = new TestablePostHogClient;
        $this->app->instance(PostHogClient::class, $client);
        $this->mock(PostHogConsentChecker::class)
            ->shouldReceive('hasAnalyticsConsent')
            ->andReturn(false);

        cityHubQualifyingBerlin();

        get(route('city-hubs.show', ['slug' => 'berlin']))->assertOk();

        expect($client->capturedCalls)->toBeEmpty();
    });

    it('logs cityhub.rejected with reason not_found before the 404', function () {
        Log::spy();

        get(route('city-hubs.show', ['slug' => 'no-such-city']))->assertNotFound();

        Log::shouldHaveReceived('info')
            ->with('cityhub.rejected', ['slug' => 'no-such-city', 'reason' => 'not_found'])
            ->once();
    });

    it('logs cityhub.rejected with reason below_threshold', function () {
        Log::spy();

        $berlin = cityHubLocation('Berlin', 52.5200, 13.4050);
        cityHubUpcomingGame($berlin);
        cityHubUpcomingGame($berlin); // 2 sessions < 3, 0 venues < 2

        get(route('city-hubs.show', ['slug' => 'berlin']))->assertNotFound();

        Log::shouldHaveReceived('info')
            ->with('cityhub.rejected', ['slug' => 'berlin', 'reason' => 'below_threshold'])
            ->once();
    });

    it('never logs the pre-D171 ambiguous reason — same-name clusters resolve independently', function () {
        Log::spy();

        cityHubLocation('Neustadt', 52.5200, 13.4050); // u33 (Berlin area)
        cityHubLocation('Neustadt', 53.5511, 9.9937); // u1x (Hamburg area)

        // No activity: the bare slug serves the u33 cluster and rejects
        // with below_threshold, not ambiguous — each cluster is its own hub.
        get(route('city-hubs.show', ['slug' => 'neustadt']))->assertNotFound();

        Log::shouldHaveReceived('info')
            ->with('cityhub.rejected', ['slug' => 'neustadt', 'reason' => 'below_threshold'])
            ->once();
    });

    it('logs cityhub.rejected below_threshold on every request, cached or fresh', function () {
        Log::spy();

        $berlin = cityHubLocation('Berlin', 52.5200, 13.4050);
        cityHubUpcomingGame($berlin);
        cityHubUpcomingGame($berlin); // 2 sessions < 3, 0 venues < 2

        get(route('city-hubs.show', ['slug' => 'berlin']))->assertNotFound();

        // The second request resolves from the cached ok-status summary;
        // the guard must re-evaluate it and reject again — a cached
        // summary must never serve a hub to a below-threshold city.
        get(route('city-hubs.show', ['slug' => 'berlin']))->assertNotFound();

        Log::shouldHaveReceived('info')
            ->with('cityhub.rejected', ['slug' => 'berlin', 'reason' => 'below_threshold'])
            ->twice();
    });
});

// ═════════════════════════════════════════════════════════
// CURATION (62-04) — hidden guard reason, featured force-qualify,
// curated locale intro in the hero, zero-cities-query warm renders
// ═════════════════════════════════════════════════════════

function cityHubCurate(string $slug, array $attributes): City
{
    return City::updateOrCreate(['slug' => $slug], $attributes);
}

describe('CityHubPage curation', function () {
    it('404s a hidden city that would otherwise qualify and logs the hidden reason', function () {
        Log::spy();

        cityHubQualifyingBerlin(); // 3 sessions: qualifies without curation
        cityHubCurate('berlin', ['city' => 'Berlin', 'hidden' => true]);

        get(route('city-hubs.show', ['slug' => 'berlin']))->assertNotFound();

        Log::shouldHaveReceived('info')
            ->with('cityhub.rejected', ['slug' => 'berlin', 'reason' => 'hidden'])
            ->once();
    });

    it('renders a featured city that sits below both thresholds', function () {
        $berlin = cityHubLocation('Berlin', 52.5200, 13.4050);
        cityHubUpcomingGame($berlin); // 1 session < 3, 0 venues < 2
        cityHubCurate('berlin', ['city' => 'Berlin', 'featured' => true]);

        get(route('city-hubs.show', ['slug' => 'berlin']))->assertOk();
    });

    it('renders the curated intro per locale in the hero instead of the generated copy', function () {
        cityHubQualifyingBerlin();
        cityHubCurate('berlin', [
            'city' => 'Berlin',
            'intro' => ['en' => 'Curated Berlin hero intro.', 'de' => 'Kuratierte Berlin-Einleitung.'],
        ]);

        get(route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']))
            ->assertOk()
            ->assertSee('Kuratierte Berlin-Einleitung.')
            ->assertDontSee(__('city-hubs.content_intro', ['city' => 'Berlin'], 'de'));

        get(route('city-hubs.show', ['locale' => 'en', 'slug' => 'berlin']))
            ->assertOk()
            ->assertSee('Curated Berlin hero intro.')
            ->assertDontSee(__('city-hubs.content_intro', ['city' => 'Berlin']));
    });

    it('falls back to the generated hero copy when the locale has no curated intro', function () {
        cityHubQualifyingBerlin();
        cityHubCurate('berlin', [
            'city' => 'Berlin',
            'intro' => ['en' => 'English-only curated intro.', 'de' => '   '],
        ]);

        // Whitespace-only de translation counts as absent (introFor
        // trims and nulls): the generated copy renders, not the en text.
        get(route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']))
            ->assertOk()
            ->assertSee(__('city-hubs.content_intro', ['city' => 'Berlin'], 'de'))
            ->assertDontSee('English-only curated intro.');

        get(route('city-hubs.show', ['locale' => 'en', 'slug' => 'berlin']))
            ->assertOk()
            ->assertSee('English-only curated intro.');
    });

    it('renders curated per-locale SEO overrides and falls back to generated copy for uncurated locales', function () {
        cityHubQualifyingBerlin();
        cityHubCurate('berlin', [
            'city' => 'Berlin',
            'seo_title' => ['en' => 'Curated Berlin SEO title'],
            'seo_description' => ['en' => 'Curated Berlin meta description.'],
        ]);

        // Curated locale: the overrides win.
        get(route('city-hubs.show', ['locale' => 'en', 'slug' => 'berlin']))
            ->assertOk()
            ->assertSee('Curated Berlin SEO title', false)
            ->assertSee('Curated Berlin meta description.', false);

        // Uncurated locale: the generated lang-key copy renders (with the
        // city name interpolated), never the English override.
        get(route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']))
            ->assertOk()
            ->assertSee(__('city-hubs.seo_title', ['city' => 'Berlin'], 'de'), false)
            ->assertDontSee('Curated Berlin SEO title', false);
    });

    it('falls back to the generated hero copy for a city with no curated row', function () {
        cityHubQualifyingBerlin();

        get(route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']))
            ->assertOk()
            ->assertSee(__('city-hubs.content_intro', ['city' => 'Berlin'], 'de'));
    });

    it('serves the curated intro from the cached summary with no cities query on warm renders', function () {
        cityHubQualifyingBerlin();
        cityHubCurate('berlin', [
            'city' => 'Berlin',
            'intro' => ['en' => 'Warm-cache intro.', 'de' => 'Warm-Cache-Einleitung.'],
        ]);

        // Cold pass resolves and caches the summary (cities query included).
        get(route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']))->assertOk();

        // Warm pass: the intro rides the cached resolution, so no query
        // may touch the cities table (same query-log guard the caching
        // tests use).
        DB::enableQueryLog();
        get(route('city-hubs.show', ['locale' => 'de', 'slug' => 'berlin']))
            ->assertOk()
            ->assertSee('Warm-Cache-Einleitung.');
        $cityQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, '"cities"'));
        DB::disableQueryLog();

        expect($cityQueries)->toBeEmpty();
    });
});
