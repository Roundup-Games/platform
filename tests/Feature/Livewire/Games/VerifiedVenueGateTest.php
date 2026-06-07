<?php

use App\Enums\Visibility;
use App\Livewire\Campaigns\CreateCampaign;
use App\Livewire\Games\CreateGame;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\{actingAs, assertDatabaseHas};

// ── Helpers ──────────────────────────────────────────────

function venueGateGmUser(): User
{
    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);

    seedPermissions();

    $user = User::factory()->create([
        'profile_complete' => true,
        'can_create_public_entries' => true,
    ]);

    $user->assignRole('Game Master');

    return $user;
}

function venueGateNonGmUser(): User
{
    seedPermissions();

    return User::factory()->create([
        'profile_complete' => true,
        'can_create_public_entries' => false,
    ]);
}

function verifiedLocation(): Location
{
    return Location::factory()->verifiedVenue()->create();
}

function nonVerifiedLocation(): Location
{
    return Location::factory()->create(['is_verified' => false]);
}

// ═══════════════════════════════════════════════════════════
// CREATE GAME — canCreatePublic COMPUTED
// ═══════════════════════════════════════════════════════════

describe('CreateGame — canCreatePublic gate', function () {
    it('returns true for GM user regardless of location', function () {
        $gm = venueGateGmUser();

        Livewire\Livewire::actingAs($gm)
            ->test(CreateGame::class)
            ->assertSet('canCreatePublic', true);
    });

    it('returns true for GM user even without a location', function () {
        $gm = venueGateGmUser();

        Livewire\Livewire::actingAs($gm)
            ->test(CreateGame::class)
            ->set('location_id', null)
            ->assertSet('canCreatePublic', true);
    });

    it('returns true for non-GM user at a verified venue', function () {
        $user = venueGateNonGmUser();
        $venue = verifiedLocation();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->set('location_id', $venue->id)
            ->assertSet('canCreatePublic', true);
    });

    it('returns false for non-GM user at a non-verified location', function () {
        $user = venueGateNonGmUser();
        $loc = nonVerifiedLocation();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->set('location_id', $loc->id)
            ->assertSet('canCreatePublic', false);
    });

    it('returns false for non-GM user with no location', function () {
        $user = venueGateNonGmUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->set('location_id', null)
            ->assertSet('canCreatePublic', false);
    });
});

// ═══════════════════════════════════════════════════════════
// CREATE GAME — SAVE WITH VERIFIED VENUE BYPASS
// ═══════════════════════════════════════════════════════════

describe('CreateGame — save with venue bypass', function () {
    it('saves with visibility=public when non-GM user creates at verified venue', function () {
        $user = venueGateNonGmUser();
        $venue = verifiedLocation();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'Public Game at Venue')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('location_id', $venue->id)
            ->set('visibility', 'public')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('name->en', 'Public Game at Venue')->first();
        expect($game)->not->toBeNull()
            ->and($game->visibility->value)->toBe('public')
            ->and($game->location_id)->toBe($venue->id);
    });

    it('downgrades visibility when non-GM user changes from verified to non-verified location', function () {
        $user = venueGateNonGmUser();
        $venue = verifiedLocation();
        $nonVerified = nonVerifiedLocation();

        // Start at verified venue, set public, then switch to non-verified
        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'Downgraded Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('location_id', $venue->id)
            ->set('visibility', 'public')
            // Switch location to non-verified — canCreatePublic becomes false
            ->set('location_id', $nonVerified->id)
            ->call('save')
            ->assertRedirect();

        $game = Game::where('name->en', 'Downgraded Game')->first();
        expect($game)->not->toBeNull()
            ->and($game->visibility->value)->toBe('private');
    });

    it('forces visibility to private when non-GM user creates at non-verified venue with public visibility', function () {
        $user = venueGateNonGmUser();
        $loc = nonVerifiedLocation();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'Forced Private Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('location_id', $loc->id)
            ->set('visibility', 'public')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('name->en', 'Forced Private Game')->first();
        expect($game)->not->toBeNull()
            ->and($game->visibility->value)->toBe('private');
    });
});

// ═══════════════════════════════════════════════════════════
// CREATE CAMPAIGN — canCreatePublic COMPUTED
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign — canCreatePublic gate', function () {
    it('returns true for GM user regardless of location', function () {
        $gm = venueGateGmUser();

        Livewire\Livewire::actingAs($gm)
            ->test(CreateCampaign::class)
            ->assertSet('canCreatePublic', true);
    });

    it('returns true for GM user even without a location', function () {
        $gm = venueGateGmUser();

        Livewire\Livewire::actingAs($gm)
            ->test(CreateCampaign::class)
            ->set('location_id', null)
            ->assertSet('canCreatePublic', true);
    });

    it('returns true for non-GM user at a verified venue', function () {
        $user = venueGateNonGmUser();
        $venue = verifiedLocation();

        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->set('location_id', $venue->id)
            ->assertSet('canCreatePublic', true);
    });

    it('returns false for non-GM user at a non-verified location', function () {
        $user = venueGateNonGmUser();
        $loc = nonVerifiedLocation();

        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->set('location_id', $loc->id)
            ->assertSet('canCreatePublic', false);
    });

    it('returns false for non-GM user with no location', function () {
        $user = venueGateNonGmUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->set('location_id', null)
            ->assertSet('canCreatePublic', false);
    });
});

// ═══════════════════════════════════════════════════════════
// CREATE CAMPAIGN — SAVE WITH VERIFIED VENUE BYPASS
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign — save with venue bypass', function () {
    it('saves with visibility=public when non-GM user creates at verified venue', function () {
        $user = venueGateNonGmUser();
        $venue = verifiedLocation();

        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->set('name', 'Public Campaign at Venue')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('max_players', 5)
            ->set('location_id', $venue->id)
            ->set('visibility', 'public')
            ->call('save')
            ->assertRedirect();

        $campaign = Campaign::where('name->en', 'Public Campaign at Venue')->first();
        expect($campaign)->not->toBeNull()
            ->and($campaign->visibility->value)->toBe('public')
            ->and($campaign->location_id)->toBe($venue->id);
    });

    it('downgrades visibility when non-GM user changes from verified to non-verified location', function () {
        $user = venueGateNonGmUser();
        $venue = verifiedLocation();
        $nonVerified = nonVerifiedLocation();

        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->set('name', 'Downgraded Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('max_players', 5)
            ->set('location_id', $venue->id)
            ->set('visibility', 'public')
            // Switch to non-verified location
            ->set('location_id', $nonVerified->id)
            ->call('save')
            ->assertRedirect();

        $campaign = Campaign::where('name->en', 'Downgraded Campaign')->first();
        expect($campaign)->not->toBeNull()
            ->and($campaign->visibility->value)->toBe('protected');
    });

    it('forces visibility to protected when non-GM user creates at non-verified venue with public visibility', function () {
        $user = venueGateNonGmUser();
        $loc = nonVerifiedLocation();

        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->set('name', 'Forced Protected Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('max_players', 5)
            ->set('location_id', $loc->id)
            ->set('visibility', 'public')
            ->call('save')
            ->assertRedirect();

        $campaign = Campaign::where('name->en', 'Forced Protected Campaign')->first();
        expect($campaign)->not->toBeNull()
            ->and($campaign->visibility->value)->toBe('protected');
    });
});
