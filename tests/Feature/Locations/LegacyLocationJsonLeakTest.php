<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\VenueType;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| HIGH-2 regression guard: legacy games/campaigns `location` JSON address leak
|--------------------------------------------------------------------------
|
| History: the games table carried a legacy `location` JSON column
| ({details,address,lat,lng,placeId}) holding a free-text street address that
| views rendered to strangers via a comma-chunk heuristic — most dangerously
| on the indexable public game detail page (`/games/{id}`) and the schema.org
| JSON-LD embedded in it. The campaigns table had an equivalent column that was
| ALREADY dropped by migration `2026_04_15_180000_remove_location_from_campaigns`
| (before M053); its dead view branches were retained and are removed here too.
|
| M053/S1 (this slice) retires the games path: the games `location` column is
| retained for backward-compat data (no destructive migration) but is
| render-dead — no view, partial, or structured-data surface reads it.
|
| These tests prove a game carrying the legacy JSON can never expose any
| fragment of it, whether the row has been backfilled to a normalized
| `location_id` (the post-migrate steady state produced by `location:migrate`)
| or still has `location_id` null with only the legacy JSON present (worst
| case). They guard every address-bearing surface:
|
|   - the `_game-header` partial (HIGH-2 source; included by BOTH the public
|     game detail and the dashboard game detail) — rendered directly so the
|     test is independent of the Vite layout pipeline (CI never builds assets;
|     per codebase convention no test renders a @vite layout);
|   - the campaign detail views and every other blade view — covered by a
|     view-source grep guard that asserts NO blade file reads the legacy JSON
|     (the render-dead enforcement, and the exact contract of the task's own
|     grep audit). This is the meaningful guard for campaign surfaces now that
|     the campaigns `location` column no longer exists;
|   - the schema.org JSON-LD `Place` — the Game model's `buildEventPlace()` no
|     longer falls back to the legacy free-text, so indexable structured data
|     cannot leak it;
|   - the discovery session card.
*/

const LEGACY_ADDRESS = '123 Secret St, Apartment 4, Springfield';

beforeEach(function () {
    $this->gameSystem = GameSystem::factory()->create();
    $this->owner = User::factory()->create();

    // Normalized Location used as the backfill target. Deliberately an
    // unverified, non-commercial (private) location — the realistic HIGH-2
    // scenario (a home game). Its city/address share no fragment with the
    // legacy address so leak assertions are unambiguous, and the distinct
    // city lets us positively assert the linkedLocation path still renders.
    $this->normalizedLocation = Location::factory()->create([
        'name' => 'A Private Home',
        'address' => '456 Public Ave',
        'city' => 'Metropolis',
        'country' => 'DEU',
        'is_verified' => false,
        'venue_type' => null,
    ]);
});

// Forbidden fragments — any one appearing in rendered output means the legacy
// JSON leaked. The full address is comma-chunked by the old heuristic, so we
// assert each distinctive fragment is absent.
function legacyFragments(): array
{
    return ['123 Secret St', 'Apartment 4', 'Secret St'];
}

function assertNoLegacyLeakIn(string $html): void
{
    foreach (legacyFragments() as $fragment) {
        // Single-value `not->toContain` (no message arg): `toContain` is variadic
        // on values, so a second arg would be treated as another expected value.
        expect($html)->not->toContain($fragment);
    }
}

// A public game carrying the legacy JSON address.
// $locationId null  => only legacy JSON present (worst case).
// $locationId set   => backfilled to a normalized Location (steady state).
function legacyGame(GameSystem $system, User $owner, ?string $locationId): Game
{
    return Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'visibility' => 'public',
        'status' => 'scheduled',
        'location_id' => $locationId,
        'location' => ['details' => LEGACY_ADDRESS],
    ]);
}

// Make $user an approved participant of $game so LocationDisclosureService
// grants them the Exact address rung for a private location.
function makeApprovedGameParticipant(Game $game): GameParticipant
{
    $user = User::factory()->create();

    return GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $user->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);
}

// Render the `_game-header` partial (the HIGH-2 source) directly. Rendered
// without a layout on purpose: this partial is `@include`d by both the public
// and dashboard game-detail views, so its output IS the address line on the
// game detail page. Avoiding the layout keeps the test CI-safe (no Vite
// manifest required — matching the codebase convention of not rendering
// @vite layouts in tests).
//
// M053/S1/T02: the partial now delegates to <x-location-display>, which
// resolves the viewer from the session (Auth::user()) via
// LocationDisclosureService — it ignores any boolean view variable. So we
// drive disclosure by authenticating $actingAs (null ⇒ guest).
function renderGameHeader(Game $game, ?User $actingAs = null): string
{
    $game->load(['gameSystem', 'campaign', 'linkedLocation']);

    if ($actingAs !== null) {
        Auth::login($actingAs);
    }

    return view('livewire.games.partials._game-header', [
        'game' => $game,
        'isOwner' => $actingAs !== null && (string) $actingAs->id === (string) $game->owner_id,
    ])->render();
}

// ── _game-header partial (HIGH-2 source; public + dashboard game detail) ────

describe('game detail address line (the HIGH-2 source)', function () {
    it('shows "In your area" to an authenticated stranger (no street, no city)', function () {
        // Graduated disclosure (D079): a stranger to a private (unverified,
        // non-commercial) location gets the Area rung — never the city or
        // street. This is stricter than the old binary gate, which showed
        // the city to everyone who was not an approved participant.
        $game = legacyGame($this->gameSystem, $this->owner, $this->normalizedLocation->id);
        $stranger = User::factory()->create();

        $html = renderGameHeader($game, actingAs: $stranger);

        assertNoLegacyLeakIn($html);
        expect($html)
            ->toContain(__('people.disclosure_level_area'))
            ->not->toContain('Metropolis')      // city withheld from strangers
            ->not->toContain('456 Public Ave'); // street withheld
    });

    it('shows "In your area" to a guest on the public game detail page', function () {
        // The public game detail is guest-only (authenticated users are
        // redirected to the dashboard detail). A guest at a private location
        // also gets the Area rung.
        $game = legacyGame($this->gameSystem, $this->owner, $this->normalizedLocation->id);

        $html = renderGameHeader($game); // guest

        assertNoLegacyLeakIn($html);
        expect($html)->toContain(__('people.disclosure_level_area'));
    });

    it('does not leak to a stranger when only legacy JSON is present (location_id null)', function () {
        // No normalized location ⇒ the component is never invoked
        // (linkedLocation is null), so no address line renders at all.
        $game = legacyGame($this->gameSystem, $this->owner, null);

        $html = renderGameHeader($game); // guest

        assertNoLegacyLeakIn($html);
    });

    it('does not leak to an approved participant when only legacy JSON is present', function () {
        // Even the most privileged viewer must never see the legacy free-text,
        // because the render path no longer reads the JSON column at all; and
        // with no linkedLocation the address line is absent entirely.
        $game = legacyGame($this->gameSystem, $this->owner, null);
        $participant = makeApprovedGameParticipant($game);

        $html = renderGameHeader($game, actingAs: $participant->user);

        assertNoLegacyLeakIn($html);
    });

    it('shows the full normalized address to an approved participant when backfilled', function () {
        // Guarantees the participant reveal still works via linkedLocation, so
        // the disclosure service did not over-restrict legitimate participants.
        $game = legacyGame($this->gameSystem, $this->owner, $this->normalizedLocation->id);
        $participant = makeApprovedGameParticipant($game);

        $html = renderGameHeader($game, actingAs: $participant->user);

        assertNoLegacyLeakIn($html);
        expect($html)->toContain('456 Public Ave');
    });
});

// ── View-source render-dead guard (all blade surfaces, incl. campaigns) ─────
//
// Scans every blade view and asserts none reads the legacy JSON address. This
// is the comprehensive guard for the campaign detail pages, public campaign
// detail page, session card, and any indexed route — it is the test form of
// the task's `grep ... resources/views/` audit. If any view re-introduces a
// read of `location['details']` or `location['address']`, this fails.

it('has no blade view that reads the legacy JSON address (render-dead)', function () {
    $pattern = "/location\['(details|address)'\]/";
    $offenders = [];

    foreach ((new Finder)->in(resource_path('views'))->name('*.blade.php')->files() as $file) {
        if (preg_match($pattern, $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    if ($offenders !== []) {
        throw new ExpectationFailedException(
            'Blade views still read the retired legacy JSON address: '.implode(', ', $offenders)
        );
    }
    expect($offenders)->toBeEmpty();
});

// ── Schema.org JSON-LD structured data (indexable) ─────────────────────────
//
// The Game model's `buildEventPlace()` previously fell back to the legacy
// free-text `location['details']` for the JSON-LD `Place`, which is embedded
// on the indexable public detail page. The fallback is retired; assert it is
// gone via the private method so the structured-data leak cannot regress.

it('does not embed the legacy address in the schema.org JSON-LD Place', function () {
    $method = (new ReflectionClass(Game::class))->getMethod('buildEventPlace');
    $method->setAccessible(true);

    // Worst case: only legacy JSON present -> no Place at all (fail-closed).
    $jsonOnlyGame = legacyGame($this->gameSystem, $this->owner, null);
    expect($method->invoke($jsonOnlyGame))
        ->toBeNull('A game with only legacy JSON must expose no JSON-LD Place');

    // M053 disclosure-aware JSON-LD: a PRIVATE normalized location (the realistic
    // HIGH-2 home-game scenario) emits NO Place at all, so neither the legacy
    // free-text nor the private street/city can reach an indexed route. This is
    // strictly stricter than the pre-fix behavior, which emitted the normalized
    // address unconditionally — a structured-data leak of a private home.
    $privateBackfilled = legacyGame($this->gameSystem, $this->owner, $this->normalizedLocation->id);
    expect($method->invoke($privateBackfilled))
        ->toBeNull('A game at a private/unverified location must expose no JSON-LD Place (fail-closed)');

    // A VERIFIED commercial venue still emits a Place from its linkedLocation
    // (positive proof the normalized path works for public venues), carrying
    // the venue's city — never the legacy free-text.
    $venue = Location::factory()->verifiedVenue()->create([
        'venue_type' => VenueType::Cafe,
        'name' => 'The Boardroom Café',
        'address' => '9 Guild Row',
        'city' => 'Metropolis',
        'country' => 'DEU',
        'is_verified' => true,
    ]);
    $venueGame = legacyGame($this->gameSystem, $this->owner, $venue->id);
    $place = $method->invoke($venueGame);
    expect($place)->not->toBeNull('A game at a verified commercial venue should expose a JSON-LD Place');

    $serialized = json_encode($place);
    foreach (legacyFragments() as $fragment) {
        expect($serialized)->not->toContain($fragment);
    }
    expect($serialized)->toContain('Metropolis');
});

// ── Discovery session card ─────────────────────────────────────────────────

it('does not leak the legacy address via the discovery session card', function () {
    $game = legacyGame($this->gameSystem, $this->owner, null);
    $game->load('gameSystem');

    // The card surfaces entity name / system / distance / roster only — it must
    // never display any address. Guards against future regressions that might
    // add address display to discovery cards.
    $rendered = view('livewire.components.partials.session-card', [
        'entity' => $game,
        'gameSystem' => $game->gameSystem,
        'distanceKm' => 3.2,
        'participantCount' => 0,
        'type' => 'session',
    ])->render();

    assertNoLegacyLeakIn($rendered);
});

// ── Residual guard: games JSON column retained, render-dead ─────────────────
//
// Documents the residual decision: the games `location` column stays for
// backward-compat data; only the render path is gone. The data persists,
// unreachable from any view. (The campaigns `location` column was already
// dropped by migration `2026_04_15_180000` prior to M053, so there is no
// campaign column to retain.)

it('retains the games legacy JSON column (render-dead, not destructively removed)', function () {
    $game = legacyGame($this->gameSystem, $this->owner, null);

    $stored = (string) DB::table('games')->where('id', $game->id)->value('location');

    // Both distinctive fragments are present in the retained column.
    expect($stored)->toContain('123 Secret St')->toContain('Apartment 4');
});
