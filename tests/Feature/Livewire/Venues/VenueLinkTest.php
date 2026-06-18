<?php

use App\Enums\VenueType;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;

/*
 * M053 / S02 / T03 — Venue-name link affordance.
 *
 * VenueLinkTest pins the <x-venue-link> contract: it renders a venue's name as
 * a link to its public page ONLY for verified commercial venues with a
 * non-empty name and a non-null slug. Every other location renders NOTHING —
 * no orphan chip, no name leak — preserving S01's "stranger sees only the
 * disclosure-governed address" semantics for private homes.
 *
 * The surfaces tested:
 *   - the component directly (via Blade::render) — the unit contract;
 *   - the _game-header partial — the public game detail hero (guest-rendered,
 *     matching the codebase convention of rendering partials layout-free in CI);
 *   - the session-card partial — the demo entry point ("a visitor clicks a
 *     verified venue name from a session card").
 */

// ── Helpers ──────────────────────────────────────────────

/**
 * A verified commercial venue with a deterministic slug + name (the linkable
 * case). Mirrors VenueDetailTest::createVerifiedVenue() but local to avoid
 * cross-file coupling; forces a commercial VenueType (Other is excluded by the
 * authority) and sets slug/name explicitly (no auto-slug hook on Location).
 */
function venueLinkCreateVenue(array $overrides = []): Location
{
    return Location::factory()->verifiedVenue()->create(array_merge([
        'venue_type' => VenueType::Cafe,
        'slug' => 'link-venue-'.uniqid(),
        'name' => 'Linkable Venue '.uniqid(),
        'address' => '12 Venue Rd',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'country' => 'DEU',
    ], $overrides));
}

/**
 * A private (unverified) home location — the non-linkable case. Its name is
 * never rendered as a link anywhere.
 */
function venueLinkCreatePrivateLocation(array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'name' => 'Someones Home '.uniqid(),
        'slug' => 'home-'.uniqid(),
        'address' => '7 Hidden Lane',
        'city' => 'Berlin',
        'country' => 'DEU',
        'is_verified' => false,
        'venue_type' => null,
    ], $overrides));
}

function venueLinkCreateGame(Location $location): Game
{
    $system = GameSystem::factory()->create();
    $owner = User::factory()->create(['profile_complete' => true]);

    return Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'location_id' => $location->id,
        'visibility' => 'public',
        'status' => 'scheduled',
        'date_time' => now()->addDays(3),
    ]);
}

// ═══════════════════════════════════════════════════════════
// COMPONENT CONTRACT (rendered directly)
// ═══════════════════════════════════════════════════════════

describe('VenueLink component', function () {
    it('renders an anchor to the venue page for a verified commercial venue with name + slug', function () {
        $venue = venueLinkCreateVenue(['name' => 'The Dice Cup']);
        $expectedUrl = route('venues.detail', ['locale' => 'en', 'slug' => $venue->slug]);

        $rendered = Blade::render('<x-venue-link :location="$location" />', ['location' => $venue]);

        expect($rendered)
            ->toContain('href="'.$expectedUrl.'"')
            ->toContain('The Dice Cup')
            ->toContain('wire:navigate');
    });

    it('renders nothing for a private (unverified) location (no anchor, no name)', function () {
        $location = venueLinkCreatePrivateLocation(['name' => 'Secret Hideaway']);

        $rendered = Blade::render('<x-venue-link :location="$location" />', ['location' => $location]);

        expect($rendered)
            ->not->toContain('Secret Hideaway')
            ->not->toContain('href=')
            ->not->toContain('/venue/');
    });

    it('renders nothing for a verified-but-Other venue type (excluded by the authority)', function () {
        $location = Location::factory()->verifiedVenue()->create([
            'venue_type' => VenueType::Other,
            'slug' => 'other-'.uniqid(),
            'name' => 'Misc Venue',
        ]);

        $rendered = Blade::render('<x-venue-link :location="$location" />', ['location' => $location]);

        expect($rendered)->not->toContain('Misc Venue')->not->toContain('href=');
    });

    it('renders nothing when the venue name is null (graceful)', function () {
        $venue = venueLinkCreateVenue(['name' => null]);

        $rendered = Blade::render('<x-venue-link :location="$location" />', ['location' => $venue]);

        expect($rendered)->not->toContain('href=')->not->toContain('/venue/');
    });

    it('renders nothing when the slug is null (no reachable URL)', function () {
        // The Location saving hook now auto-slugs every eligible venue, so a
        // null slug can no longer be produced through normal creation. Force
        // the legacy / data-corruption null-slug state at the DB level
        // (bypassing the hook) — the component must still defend against it.
        $venue = venueLinkCreateVenue();
        Location::where('id', $venue->id)->update(['slug' => null]);
        $venue->refresh();

        $rendered = Blade::render('<x-venue-link :location="$location" />', ['location' => $venue]);

        expect($rendered)->not->toContain('href=')->not->toContain('/venue/');
    });

    it('renders nothing when the location is null', function () {
        $rendered = Blade::render('<x-venue-link :location="$location" />', ['location' => null]);

        expect($rendered)->not->toContain('href=')->not->toContain('/venue/');
    });
});

// ═══════════════════════════════════════════════════════════
// WIRE-IN: GAME HEADER (public game detail hero)
// ═══════════════════════════════════════════════════════════

describe('game header venue link', function () {
    it('shows the venue link for a public game at a verified venue', function () {
        $venue = venueLinkCreateVenue(['name' => 'Cafe Meeple']);
        $game = venueLinkCreateGame($venue);
        $game->load(['gameSystem', 'linkedLocation']);

        $rendered = view('livewire.games.partials._game-header', [
            'game' => $game,
            'isOwner' => false,
        ])->render();

        $expectedUrl = route('venues.detail', ['locale' => 'en', 'slug' => $venue->slug]);
        expect($rendered)->toContain($expectedUrl)->toContain('Cafe Meeple');
    });

    it('shows NO venue link for a public game at a private home', function () {
        $location = venueLinkCreatePrivateLocation(['name' => 'Quiet Apartment']);
        $game = venueLinkCreateGame($location);
        $game->load(['gameSystem', 'linkedLocation']);

        $rendered = view('livewire.games.partials._game-header', [
            'game' => $game,
            'isOwner' => false,
        ])->render();

        // The private location's name must never surface as a link.
        expect($rendered)->not->toContain('Quiet Apartment')->not->toContain('/venue/');
    });
});

// ═══════════════════════════════════════════════════════════
// WIRE-IN: SESSION CARD (the demo entry point)
// ═══════════════════════════════════════════════════════════

describe('session card venue link', function () {
    it('shows the venue link for a verified-venue session', function () {
        $venue = venueLinkCreateVenue(['name' => 'Board Game Bar']);
        $game = venueLinkCreateGame($venue);
        $game->load(['gameSystem', 'linkedLocation']);

        $rendered = view('livewire.components.partials.session-card', [
            'entity' => $game,
            'type' => 'session',
        ])->render();

        $expectedUrl = route('venues.detail', ['locale' => 'en', 'slug' => $venue->slug]);
        expect($rendered)->toContain($expectedUrl)->toContain('Board Game Bar');
    });

    it('omits the venue link for a private-home session (no name chip)', function () {
        $location = venueLinkCreatePrivateLocation(['name' => 'Living Room Host']);
        $game = venueLinkCreateGame($location);
        $game->load(['gameSystem', 'linkedLocation']);

        $rendered = view('livewire.components.partials.session-card', [
            'entity' => $game,
            'type' => 'session',
        ])->render();

        // No venue anchor and no leak of the private location's name as a link.
        expect($rendered)->not->toContain('/venue/')->not->toContain('Living Room Host');
    });
});
