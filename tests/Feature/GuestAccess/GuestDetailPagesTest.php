<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// ── Helpers ──────────────────────────────────────────────

function guestTestCreateGameSystem(): GameSystem
{
    return GameSystem::factory()->create();
}

function guestTestCreateOwner(array $overrides = []): User
{
    return User::factory()->create([
        'profile_complete' => true,
        ...$overrides,
    ]);
}

function guestTestCreatePublicGame(array $overrides = []): Game
{
    $system = guestTestCreateGameSystem();
    $owner = guestTestCreateOwner();

    return Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'visibility' => 'public',
        'status' => 'scheduled',
        'name' => ['en' => 'Public Test Game '.Str::random(8)],
        'location' => [
            'type' => 'in_person',
            'details' => '123 Main Street, Berlin, Germany',
        ],
        ...$overrides,
    ]);
}

function guestTestCreatePublicCampaign(array $overrides = []): Campaign
{
    $system = guestTestCreateGameSystem();
    $owner = guestTestCreateOwner();

    return Campaign::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'visibility' => 'public',
        'name' => ['en' => 'Public Test Campaign '.Str::random(8)],
        ...$overrides,
    ]);
}

function guestTestCreatePublicUser(): User
{
    return User::factory()->create([
        'profile_complete' => true,
        'name' => 'Test User '.Str::random(8),
    ]);
}

// ═══════════════════════════════════════════════════════════
// R019: GUEST REDIRECTS — /games and /campaigns → /dashboard/* (301)
// ═══════════════════════════════════════════════════════════

describe('R019 — Guest listing redirects', function () {
    // smoke: guest routing — /games 301s to the authed board
    it('permanently redirects guest from /games to /dashboard/games', function () {
        get('/en/games')
            ->assertStatus(301)->assertRedirect('/en/dashboard/games');
    })->group('smoke');

    it('permanently redirects guest from /campaigns to /dashboard/campaigns', function () {
        get('/en/campaigns')
            ->assertStatus(301)->assertRedirect('/en/dashboard/campaigns');
    });
});

// ═══════════════════════════════════════════════════════════
// R020: GUEST GAME DETAIL
// ═══════════════════════════════════════════════════════════

describe('R020 — Guest game detail page', function () {
    it('shows public game detail to guest with public layout', function () {
        $game = guestTestCreatePublicGame();

        get("/en/games/{$game->id}")
            ->assertOk()
            ->assertSee($game->name)
            ->assertSee(__('auth.content_sign_up_free', [], 'en'));
    })->group('smoke');

    it('denies guest from viewing protected game', function () {
        $game = guestTestCreatePublicGame(['visibility' => 'protected']);

        get("/en/games/{$game->id}")
            ->assertForbidden();
    });

    it('denies guest from viewing private game', function () {
        $game = guestTestCreatePublicGame(['visibility' => 'private']);

        get("/en/games/{$game->id}")
            ->assertForbidden();
    });

    it('does not leak the legacy address to a guest on game detail', function () {
        // M053/S1/T02: the games `location` JSON column is render-dead (HIGH-2).
        // A guest viewing the public detail of a game carrying only legacy
        // JSON (no normalized linkedLocation) must never see the street — the
        // <x-location-display> component is never invoked (linkedLocation is
        // null), so no address line renders at all.
        $game = guestTestCreatePublicGame([
            'location' => [
                'type' => 'in_person',
                'details' => '123 Main Street, Berlin, Germany',
            ],
        ]);

        get(route('games.detail', $game->id))
            ->assertOk()
            ->assertDontSee('123 Main Street');
    });

    it('shows discover back link to guest on game detail', function () {
        $game = guestTestCreatePublicGame();

        get("/en/games/{$game->id}")
            ->assertOk()
            ->assertSee(__('common.action_back_to_discover', [], 'en'))
            ->assertDontSee(__('profile.action_back_to_dashboard', [], 'en'));
    });
});

// ═══════════════════════════════════════════════════════════
// R021: GUEST CAMPAIGN DETAIL
// ═══════════════════════════════════════════════════════════

describe('R021 — Guest campaign detail page', function () {
    it('shows public campaign detail to guest with public layout', function () {
        $campaign = guestTestCreatePublicCampaign();

        get("/en/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertSee($campaign->name)
            ->assertSee(__('auth.content_sign_up_free', [], 'en'));
    });

    it('denies guest from viewing protected campaign', function () {
        $campaign = guestTestCreatePublicCampaign(['visibility' => 'protected']);

        get("/en/campaigns/{$campaign->id}")
            ->assertForbidden();
    });

    it('denies guest from viewing private campaign', function () {
        $campaign = guestTestCreatePublicCampaign(['visibility' => 'private']);

        get("/en/campaigns/{$campaign->id}")
            ->assertForbidden();
    });

    it('shows discover back link to guest on campaign detail', function () {
        $campaign = guestTestCreatePublicCampaign();

        get("/en/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertSee(__('common.action_back_to_discover', [], 'en'))
            ->assertDontSee(__('profile.action_back_to_dashboard', [], 'en'));
    });
});

// ═══════════════════════════════════════════════════════════
// R022: GUEST PUBLIC PROFILE
// ═══════════════════════════════════════════════════════════

describe('R022 — Guest public profile', function () {
    it('shows public profile to guest with public layout', function () {
        $user = guestTestCreatePublicUser();

        get(route('profile.public', $user))
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee(__('auth.content_sign_up_free', [], 'en'));
    });

    it('shows follow login prompt to guest on profile', function () {
        $user = guestTestCreatePublicUser();

        get(route('profile.public', $user))
            ->assertOk()
            ->assertSee(__('profile.action_log_in_to_follow', [], 'en'));
    });
});

// ═══════════════════════════════════════════════════════════
// AUTHENTICATED USER REGRESSION TESTS
// ═══════════════════════════════════════════════════════════

describe('Authenticated user layout regression', function () {
    it('shows app layout to authenticated user on game detail', function () {
        $user = guestTestCreateOwner();
        $game = guestTestCreatePublicGame(['owner_id' => $user->id]);

        actingAs($user);
        get(route('games.show', $game->id))
            ->assertOk()
            ->assertSee($game->name)
            ->assertSee(__('profile.action_back_to_dashboard'));
    });

    it('shows app layout to authenticated user on campaign detail', function () {
        $user = guestTestCreateOwner();
        $campaign = guestTestCreatePublicCampaign(['owner_id' => $user->id]);

        actingAs($user);
        get(route('campaigns.show', $campaign->id))
            ->assertOk()
            ->assertSee($campaign->name)
            ->assertSee(__('profile.action_back_to_dashboard'));
    });

    it('shows app layout to authenticated user on profile', function () {
        $viewer = guestTestCreateOwner();
        $profileUser = guestTestCreatePublicUser();

        actingAs($viewer);
        get(route('profile.public', $profileUser))
            ->assertOk()
            ->assertSee($profileUser->name)
            ->assertSee(__('common.action_follow', [], 'en'));
    });

    // Malformed uuid ids must resolve to the same 404 as a well-formed miss —
    // UuidBuilder guards them before Postgres can reject the bind (22P02).
    it('returns 404, not 500, for a malformed uuid id on game detail', function () {
        get(route('games.detail', "1' OR '1'='1"))->assertNotFound();
        get(route('games.detail', 'not-a-uuid'))->assertNotFound();
    });

    it('returns 404, not 500, for a malformed uuid id on campaign detail', function () {
        get(route('campaigns.detail', 'not-a-uuid'))->assertNotFound();
    });
});
