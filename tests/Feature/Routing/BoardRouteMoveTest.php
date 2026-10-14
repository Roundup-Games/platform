<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// ── Helpers ──────────────────────────────────────────────

function boardRouteMoveCreateUser(array $overrides = []): User
{
    return User::factory()->create(['profile_complete' => true, ...$overrides]);
}

// ═══════════════════════════════════════════════════════════
// M066/S06/T01 — AUTH BOARD MOVE /games → /dashboard/games,
// /campaigns → /dashboard/campaigns (301s, named routes kept)
// ═══════════════════════════════════════════════════════════

describe('BoardRouteMove — permanent redirects', function () {
    it('301s /games to /dashboard/games keeping the locale', function () {
        get('/en/games')
            ->assertStatus(301)->assertRedirect('/en/dashboard/games');

        get('/de/games')
            ->assertStatus(301)->assertRedirect('/de/dashboard/games');
    });

    it('301s /campaigns to /dashboard/campaigns keeping the locale', function () {
        get('/en/campaigns')
            ->assertStatus(301)->assertRedirect('/en/dashboard/campaigns');

        get('/de/campaigns')
            ->assertStatus(301)->assertRedirect('/de/dashboard/campaigns');
    });

    it('keeps the games.index and campaigns.index route names on the new paths', function () {
        expect(route('games.index', ['locale' => 'en']))->toBe(url('/en/dashboard/games'))
            ->and(route('campaigns.index', ['locale' => 'en']))->toBe(url('/en/dashboard/campaigns'));
    });
});

describe('BoardRouteMove — auth wall', function () {
    it('redirects guests away from /dashboard/games instead of rendering', function () {
        get('/en/dashboard/games')->assertRedirect();
    });

    it('redirects guests away from /dashboard/campaigns instead of rendering', function () {
        get('/en/dashboard/campaigns')->assertRedirect();
    });
});

describe('BoardRouteMove — authed boards render', function () {
    it('shows the My Games board at /dashboard/games', function () {
        actingAs(boardRouteMoveCreateUser())
            ->get('/en/dashboard/games')
            ->assertOk();
    });

    it('shows the campaigns board at /dashboard/campaigns', function () {
        actingAs(boardRouteMoveCreateUser())
            ->get('/en/dashboard/campaigns')
            ->assertOk();
    });
});
