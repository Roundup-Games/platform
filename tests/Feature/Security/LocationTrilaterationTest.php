<?php

use App\Enums\VenueType;
use App\Livewire\Components\NearbySessions;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationDisclosureService;
use App\Services\ProximityQuery;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| CRITICAL-1 regression guard: distance trilateration via spoofed guest coords
|--------------------------------------------------------------------------
|
| Threat model: an unauthenticated attacker controls the "guest location" the
| NearbySessions landing widget queries against. By submitting three sets of
| spoofed coordinates from distinct vantage points, the attacker hopes to read
| three precise target→vantage distances and intersect the three circles to
| resolve a private host's exact home location (classic trilateration).
|
| Defence (M053/S1): LocationDisclosureService grid-snaps every DISPLAYED
| distance to a 5km bucket (D060) for any non-verified-commercial location,
| regardless of viewer relationship. Each displayed reading therefore carries
| ≥5km of uncertainty, so three circles never intersect at a single resolvable
| point — only a broad region. ProximityQuery keeps the precise distance for
| retrieval/sorting; only the rendered badge is snapped.
|
| These tests prove the defence end-to-end: driving NearbySessions::onGuest-
| LocationUpdated from three triangulation-pattern vantage points and capturing
| the rendered session-card badge, plus the discovery game-card, campaign-card,
| and the raw <x-distance-display> component. A venue-aware contrast proves the
| snap is targeted (verified commercial venues still get precise distance), and
| a brute-force sweep proves unlimited queries cannot beat the 5km floor.
|
| Rate-limit note: per-session throttling of guest coordinate updates is the
| MEDIUM-4 defence-in-depth layer shipped by T07. The final test here is the
| explicit cross-check; it is skipped until T07 lands so the suite stays green
| and the cross-task link is discoverable.
*/

// ── Target: a private home game at a precise, known point (Berlin Alex) ─────
const TARGET_LAT = 52.5219;
const TARGET_LNG = 13.4117;

/**
 * Three spoofed vantage points forming a triangulation pattern AROUND the
 * target at deliberately DIFFERENT distances (~3 / ~6 / ~9 km). Different
 * precise distances are what make trilateration meaningful: three distinct
 * circles can intersect at a single point. All sit well inside the 50km query
 * radius so NearbySessions reliably surfaces the target from each.
 */
function tri_spoofVantagePoints(): array
{
    return [
        'north_close' => ['lat' => 52.5489, 'lng' => 13.4117],   // ~3.0km N  → "In your area"
        'east_mid' => ['lat' => 52.5219, 'lng' => 13.5005],        // ~6.0km E  → "~5 km"
        'south_far' => ['lat' => 52.4408, 'lng' => 13.4117],       // ~9.0km S  → "~10 km"
    ];
}

/**
 * Precise Haversine distance (km) between two points — the exact value
 * ProximityQuery computes internally for retrieval and sorting. This is the
 * value an attacker would need to read verbatim to trilaterate.
 */
function tri_preciseDistanceBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    return ProximityQuery::haversineDistance($lat1, $lng1, $lat2, $lng2);
}

/**
 * Assert a rendered badge is grid-snapped: it is EITHER "In your area" (the
 * <5km / same-tile rung) OR a "Nearby — ~N km" bucket where N is a multiple of
 * 5 with a 5km floor — and crucially it does NOT expose the raw precise
 * decimal the attacker needs. $preciseKm is the value that WOULD render if the
 * disclosure service were bypassed; we assert that exact decimal is absent.
 */
function tri_assertBadgeIsGridSnapped(string $html, float $preciseKm): void
{
    // 1. The precise figure an attacker wants must NOT appear verbatim.
    //    A raw render would be number_format($precise,1).' km' (e.g. "3.0 km").
    expect($html)->not->toContain(number_format($preciseKm, 1).' km');

    // 2. No precise decimal distance appears at all (defends against any
    //    variant like "6.0 km", "9.0 km"). Grid-snapped output is either
    //    "In your area" or "Nearby — ~N km" with an integer N.
    expect($html)->not->toMatch('/\d+\.\d+\s?km/i');

    // 3. At least one legitimate grid-snapped surface IS present.
    //    The bucket label embeds the digit count where :distance sits
    //    ("Nearby — ~N km"), so build the pattern by placeholder-swapping
    //    rather than appending — digits are mid-string, not a suffix.
    $inArea = __('people.nearby_in_your_area');
    $hasInArea = str_contains($html, $inArea);
    $bucketTemplate = __('people.nearby_distance_label', ['distance' => '__DIG__']);
    $bucketPattern = '/'.str_replace('__DIG__', '(\d+)', preg_quote($bucketTemplate, '/')).'/';
    $hasBucket = (bool) preg_match($bucketPattern, $html, $m);

    expect($hasInArea || $hasBucket)->toBeTrue(
        "Expected a grid-snapped badge ('{$inArea}' or a '~N km' bucket) but found neither. HTML: ".trim(strip_tags($html)),
    );

    // 4. When a bucket is shown, its value must be a multiple of 5, ≥5.
    if ($hasBucket && isset($m[1])) {
        $bucket = (int) $m[1];
        expect($bucket)->toBeGreaterThanOrEqual(5);
        expect($bucket % 5)->toBe(0, "Distance bucket {$bucket}km is not a multiple of 5 (D060 grid-snap).");
    }
}

/**
 * Render the session-card partial directly (CI-safe, no layout) for a given
 * entity + precise distance — the CRITICAL-1 guest-reachable surface.
 */
function tri_renderSessionCard(Game|Campaign $entity, ?GameSystem $system, float $distanceKm, string $type = 'session'): string
{
    return view('livewire.components.partials.session-card', [
        'entity' => $entity,
        'gameSystem' => $system,
        'distanceKm' => $distanceKm,
        'participantCount' => 0,
        'type' => $type,
    ])->render();
}

/**
 * Render the discovery game-card partial. Sets the distance_km attribute the
 * partial reads via `isset($game->distance_km)`.
 */
function tri_renderGameCard(Game $game, float $distanceKm): string
{
    $game->distance_km = $distanceKm;
    $game->load('linkedLocation');

    return view('livewire.discovery.partials.game-card', ['game' => $game])->render();
}

/**
 * Render the discovery campaign-card partial.
 */
function tri_renderCampaignCard(Campaign $campaign, float $distanceKm): string
{
    $campaign->distance_km = $distanceKm;
    $campaign->load('linkedLocation');

    return view('livewire.discovery.partials.campaign-card', ['campaign' => $campaign])->render();
}

beforeEach(function () {
    $this->gameSystem = GameSystem::factory()->create(['name' => ['en' => 'Catan'], 'bgg_rank' => 250]);
    $this->owner = User::factory()->create();

    // A PRIVATE home location: unverified, no venue type. The realistic
    // CRITICAL-1 target — a host's home address that must never be resolvable
    // by a stranger reading distance badges.
    $this->privateLocation = Location::factory()->create([
        'name' => 'A Private Home',
        'address' => '456 Secret Grove Ave',
        'city' => 'Berlin',
        'country' => 'DEU',
        'latitude' => TARGET_LAT,
        'longitude' => TARGET_LNG,
        'is_verified' => false,
        'venue_type' => null,
    ]);

    $this->privateGame = Game::factory()->create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'location_id' => $this->privateLocation->id,
        'visibility' => 'public',
        'status' => 'scheduled',
        'name' => ['en' => 'Private Home Game'],
    ]);
    $this->privateGame->load('linkedLocation', 'gameSystem');

    // Guest viewer throughout (no Auth::login) — the unauthenticated attacker.
    Auth::logout();
});

// ═══════════════════════════════════════════════════════════
// CRITICAL-1: three spoofed vantage points cannot trilaterate
// ═══════════════════════════════════════════════════════════

describe('spoofed-coordinate trilateration defence', function () {
    it('snaps all three spoofed vantage-point distances on the session card via NearbySessions', function () {
        // End-to-end: drive NearbySessions::onGuestLocationUpdated from each
        // spoofed vantage point (the exact attack vector), then capture the
        // rendered session-card badge from the component's HTML.
        $badges = [];

        foreach (tri_spoofVantagePoints() as $label => $point) {
            $component = Livewire::test(NearbySessions::class, ['radius' => 50, 'limit' => 4])
                ->call('onGuestLocationUpdated', $point['lat'], $point['lng'], 'browser');

            // ProximityQuery DID compute a precise distance for retrieval —
            // the value an attacker needs. Confirm it is a real, distinct km.
            $sessions = $component->instance()->getSessions();
            expect($sessions)->not->toBeEmpty("NearbySessions found no sessions from vantage {$label}");

            $item = $sessions->firstWhere(fn ($s) => $s->entity instanceof Game && $s->entity->id === $this->privateGame->id);
            expect($item)->not->toBeNull("Target private game not surfaced from vantage {$label}");

            $precise = $item->distance_km;
            expect($precise)->toBeGreaterThan(0.0);

            // The rendered component HTML carries the session-card badge.
            $html = $component->html();
            tri_assertBadgeIsGridSnapped($html, $precise);

            $badges[$label] = ['precise' => $precise, 'html' => $html];
        }

        // The three precise distances are DISTINCT — which is precisely the
        // condition under which raw distances WOULD enable trilateration.
        // (Three identical circles intersect everywhere; three distinct ones
        // pin a point.) The defence snaps them, so the attack fails.
        $preciseValues = array_column($badges, 'precise');
        sort($preciseValues);
        expect($preciseValues[0])->toBeLessThan($preciseValues[2], 'Vantage points should be at distinct distances to make trilateration meaningful.');
    });

    it('snaps the spoofed distances on the discovery game-card', function () {
        foreach (tri_spoofVantagePoints() as $label => $point) {
            $precise = tri_preciseDistanceBetween($point['lat'], $point['lng'], TARGET_LAT, TARGET_LNG);
            $html = tri_renderGameCard($this->privateGame, $precise);
            tri_assertBadgeIsGridSnapped($html, $precise);
        }
    });

    it('snaps the spoofed distances on the discovery campaign-card', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'location_id' => $this->privateLocation->id,
            'visibility' => 'public',
            'status' => 'active',
            'name' => ['en' => 'Private Home Campaign'],
        ]);
        $campaign->load('linkedLocation', 'gameSystem');

        foreach (tri_spoofVantagePoints() as $label => $point) {
            $precise = tri_preciseDistanceBetween($point['lat'], $point['lng'], TARGET_LAT, TARGET_LNG);
            $html = tri_renderCampaignCard($campaign, $precise);
            tri_assertBadgeIsGridSnapped($html, $precise);
        }
    });

    it('renders no precise sub-5km decimal on any distance-bearing surface to a guest', function () {
        // Sweep a range of precise distances a guest could trigger; none may
        // render as a precise decimal for the private location.
        foreach ([0.3, 1.2, 2.7, 3.0, 4.9, 5.5, 8.0, 12.4] as $precise) {
            $card = tri_renderSessionCard($this->privateGame, $this->gameSystem, $precise);
            tri_assertBadgeIsGridSnapped($card, $precise);
        }
    });
});

// ═══════════════════════════════════════════════════════════
// Venue-aware contrast: the snap is targeted, not blanket
// ═══════════════════════════════════════════════════════════

describe('venue-aware contrast (snap is not blanket)', function () {
    it('shows a PRECISE distance for a verified commercial venue to a guest', function () {
        // A verified café is a public space — a precise distance is safe and
        // expected. Proving this confirms the snap above is venue-targeted
        // (private locations only), not an accidental blanket round().
        $venue = Location::factory()->create([
            'name' => 'Café Brixe',
            'address' => '1 Market Square',
            'city' => 'Berlin',
            'country' => 'DEU',
            'latitude' => TARGET_LAT,
            'longitude' => TARGET_LNG,
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
        ]);

        $venueGame = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'location_id' => $venue->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'name' => ['en' => 'Café Game'],
        ]);
        $venueGame->load('linkedLocation', 'gameSystem');

        $precise = 3.27; // arbitrary precise figure

        // The service itself returns a PRECISE value for the verified venue.
        $display = app(LocationDisclosureService::class)
            ->distanceDisplay($precise, $venue, null, $venueGame);
        expect($display->isPrecise())->toBeTrue();
        expect($display->display())->toBe(number_format($precise, 1).' km');

        // And the rendered session-card badge exposes that precise decimal —
        // the inverse of the private-location assertion.
        $html = tri_renderSessionCard($venueGame, $this->gameSystem, $precise);
        expect($html)->toContain(number_format($precise, 1).' km');
        expect($html)->not->toContain(__('people.nearby_in_your_area'));
    });
});

// ═══════════════════════════════════════════════════════════
// Brute-force yield cap: unlimited queries cannot beat the 5km floor
// ═══════════════════════════════════════════════════════════

describe('brute-force yield cap', function () {
    it('ten spoofed coordinates still cannot resolve a sub-5km figure', function () {
        // Even if an attacker ignored any per-request throttle and spammed
        // coordinate updates, every single rendered badge remains grid-snapped.
        // The 5km floor caps the information yield of brute force at 5km of
        // uncertainty — trilateration never sharpens below that.
        for ($i = 0; $i < 10; $i++) {
            // Spread 10 vantage points on a rough ring around the target.
            $bearing = (2 * M_PI * $i) / 10;
            $radiusDeg = 0.05; // ~5.5km
            $lat = TARGET_LAT + rad2deg($radiusDeg * cos($bearing));
            $lng = TARGET_LNG + rad2deg($radiusDeg * sin($bearing) / max(0.01, cos(deg2rad(TARGET_LAT))));

            $precise = tri_preciseDistanceBetween($lat, $lng, TARGET_LAT, TARGET_LNG);
            $html = tri_renderSessionCard($this->privateGame, $this->gameSystem, $precise);
            tri_assertBadgeIsGridSnapped($html, $precise);
        }
    });
});

// ═══════════════════════════════════════════════════════════
// T07 cross-check: per-session guest-coordinate rate-limiting
// ═══════════════════════════════════════════════════════════

describe('guest coordinate rate-limit (T07 defence-in-depth cross-check)', function () {
    it('throttles rapid guest coordinate updates so brute-force triangulation is rate-limited', function () {
        // T07 ships a per-session/IP RateLimiter on the coordinate-update path
        // (HasGuestLocation trait + NearbySessions override) as MEDIUM-4
        // defence-in-depth. Even though the 5km grid-snap proven above already
        // caps triangulation yield at 5km regardless, this limiter adds
        // request-level braking so an attacker cannot spam vantage points at
        // high speed. This test is the permanent cross-check that the throttle
        // is wired and active on the CRITICAL-1 guest-reachable surface.
        $component = Livewire::test(NearbySessions::class, ['radius' => 50, 'limit' => 4]);

        // Exhaust the per-session cap with 10 accepted spoofed vantage points.
        for ($i = 0; $i < 10; $i++) {
            $component->call('onGuestLocationUpdated', 52.5 + ($i * 0.01), 13.4 + ($i * 0.01), 'browser');
            $component->assertSet('guestLat', 52.5 + ($i * 0.01));
        }

        // The 11th spoofed coordinate is silently dropped — the last accepted
        // value is retained (the attacker gets no feedback that they were
        // throttled). This is the request-level brake layering on top of the
        // 5km grid-snap yield cap.
        $component->call('onGuestLocationUpdated', 52.45, 13.35, 'browser');
        $component->assertSet('guestLat', 52.5 + (9 * 0.01))
            ->assertSet('guestLng', 13.4 + (9 * 0.01));
    });
});
