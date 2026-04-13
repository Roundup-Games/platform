<?php

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use function Pest\Laravel\{actingAs, assertDatabaseHas, get};

// ── Helpers ──────────────────────────────────────────────

function gameCrudCreateGameWithOwner(array $gameAttrs = []): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        ...$gameAttrs,
    ]);

    return ['owner' => $owner, 'game' => $game];
}

function campaignCrudCreateCampaignWithOwner(array $campaignAttrs = []): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        ...$campaignAttrs,
    ]);

    return ['owner' => $owner, 'campaign' => $campaign];
}

function gameCrudCreateUserWithPermission(string $permission = 'create game'): User
{
    seedPermissions();
    $user = User::factory()->create(['profile_complete' => true]);
    setPermissionsTeamId(1);
    $user->givePermissionTo($permission);
    $user->unsetRelations();
    setPermissionsTeamId(1);
    return $user;
}

function campaignCrudCreateUserWithPermission(string $permission = 'create campaign'): User
{
    seedPermissions();
    $user = User::factory()->create(['profile_complete' => true]);
    setPermissionsTeamId(1);
    $user->givePermissionTo($permission);
    $user->unsetRelations();
    setPermissionsTeamId(1);
    return $user;
}

// ── GamePolicy ──────────────────────────────────────────

describe('GamePolicy', function () {
    it('allows anyone to view a public game', function () {
        $game = Game::factory()->create(['visibility' => 'public']);

        expect(Gate::allows('view', $game))->toBeTrue()
            ->and(Gate::forUser(User::factory()->make())->allows('view', $game))->toBeTrue();
    });

    it('allows owner to view a private game', function () {
        ['owner' => $owner, 'game' => $game] = gameCrudCreateGameWithOwner(['visibility' => 'private']);

        expect(Gate::forUser($owner)->allows('view', $game))->toBeTrue();
    });

    it('allows participant to view a private game', function () {
        $game = Game::factory()->create(['visibility' => 'private']);
        $participant = User::factory()->create();
        GameParticipant::create([
            
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        expect(Gate::forUser($participant)->allows('view', $game))->toBeTrue();
    });

    it('denies guest from viewing a private game', function () {
        $game = Game::factory()->create(['visibility' => 'private']);

        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('denies non-owner non-participant from viewing a private game', function () {
        $game = Game::factory()->create(['visibility' => 'private']);
        $stranger = User::factory()->create();

        expect(Gate::forUser($stranger)->allows('view', $game))->toBeFalse();
    });

    it('allows authenticated user with permission to create a game', function () {
        $user = User::factory()->create();
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'create game', 'guard_name' => 'web']);
        setPermissionsTeamId(1);
        $user->givePermissionTo('create game');
        $user->unsetRelations();

        expect(Gate::forUser($user)->allows('create', Game::class))->toBeTrue();
    });

    it('allows owner to update a game', function () {
        ['owner' => $owner, 'game' => $game] = gameCrudCreateGameWithOwner();

        expect(Gate::forUser($owner)->allows('update', $game))->toBeTrue();
    });

    it('denies non-owner from updating a game', function () {
        $game = Game::factory()->create();
        $stranger = User::factory()->create();

        expect(Gate::forUser($stranger)->allows('update', $game))->toBeFalse();
    });

    it('allows owner to delete a game', function () {
        ['owner' => $owner, 'game' => $game] = gameCrudCreateGameWithOwner();

        expect(Gate::forUser($owner)->allows('delete', $game))->toBeTrue();
    });

    it('denies non-owner from deleting a game', function () {
        $game = Game::factory()->create();
        $stranger = User::factory()->create();

        expect(Gate::forUser($stranger)->allows('delete', $game))->toBeFalse();
    });
});

// ── CampaignPolicy ──────────────────────────────────────

describe('CampaignPolicy', function () {
    it('allows anyone to view a public campaign', function () {
        $campaign = Campaign::factory()->create(['visibility' => 'public']);

        expect(Gate::allows('view', $campaign))->toBeTrue()
            ->and(Gate::forUser(User::factory()->make())->allows('view', $campaign))->toBeTrue();
    });

    it('allows owner to view a private campaign', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignCrudCreateCampaignWithOwner(['visibility' => 'private']);

        expect(Gate::forUser($owner)->allows('view', $campaign))->toBeTrue();
    });

    it('allows participant to view a private campaign', function () {
        $campaign = Campaign::factory()->create(['visibility' => 'private']);
        $participant = User::factory()->create();
        CampaignParticipant::create([
            
            'campaign_id' => $campaign->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        expect(Gate::forUser($participant)->allows('view', $campaign))->toBeTrue();
    });

    it('denies guest from viewing a private campaign', function () {
        $campaign = Campaign::factory()->create(['visibility' => 'private']);

        expect(Gate::allows('view', $campaign))->toBeFalse();
    });

    it('allows authenticated user with permission to create a campaign', function () {
        $user = User::factory()->create();
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'create campaign', 'guard_name' => 'web']);
        setPermissionsTeamId(1);
        $user->givePermissionTo('create campaign');
        $user->unsetRelations();

        expect(Gate::forUser($user)->allows('create', Campaign::class))->toBeTrue();
    });

    it('allows owner to update a campaign', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignCrudCreateCampaignWithOwner();

        expect(Gate::forUser($owner)->allows('update', $campaign))->toBeTrue();
    });

    it('denies non-owner from updating a campaign', function () {
        $campaign = Campaign::factory()->create();
        $stranger = User::factory()->create();

        expect(Gate::forUser($stranger)->allows('update', $campaign))->toBeFalse();
    });

    it('allows owner to delete a campaign', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignCrudCreateCampaignWithOwner();

        expect(Gate::forUser($owner)->allows('delete', $campaign))->toBeTrue();
    });

    it('denies non-owner from deleting a campaign', function () {
        $campaign = Campaign::factory()->create();
        $stranger = User::factory()->create();

        expect(Gate::forUser($stranger)->allows('delete', $campaign))->toBeFalse();
    });
});

// ── CreateGame ──────────────────────────────────────────

describe('CreateGame', function () {
    it('redirects unauthenticated users', function () {
        get(route('games.create'))
            ->assertRedirect(route('login'));
    });

    it('shows the create game form', function () {
        $user = gameCrudCreateUserWithPermission();

        actingAs($user)
            ->get(route('games.create'))
            ->assertOk()
            ->assertSee('Create Game Session');
    });

    it('creates a game with valid data', function () {
        $user = gameCrudCreateUserWithPermission();
        $system = GameSystem::factory()->create();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Games\CreateGame::class)
            ->set('name', 'Dungeon Crawl Night')
            ->set('game_system_id', $system->id)
            ->set('date_time', now()->addDays(7)->format('Y-m-d\TH:i'))
            ->set('description', 'A fun dungeon crawl')
            ->set('expected_duration', '3')
            ->set('price', '5.00')
            ->set('visibility', 'public')
            ->set('location_type', 'online')
            ->set('location_details', 'https://roll20.net/join/123')
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('games', [
            'name' => 'Dungeon Crawl Night',
            'owner_id' => $user->id,
            'game_system_id' => $system->id,
            'visibility' => 'public',
            'status' => 'scheduled',
        ]);
    });

    it('validates required fields', function () {
        $user = gameCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Games\CreateGame::class)
            ->set('name', '')
            ->set('date_time', '')
            ->call('save')
            ->assertHasErrors(['name', 'date_time']);
    });

    it('validates name max length', function () {
        $user = gameCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Games\CreateGame::class)
            ->set('name', str_repeat('A', 256))
            ->call('save')
            ->assertHasErrors(['name']);
    });

    it('validates visibility must be valid option', function () {
        $user = gameCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Games\CreateGame::class)
            ->set('name', 'Test Game')
            ->set('date_time', now()->addDays(7)->format('Y-m-d\TH:i'))
            ->set('visibility', 'invalid')
            ->call('save')
            ->assertHasErrors(['visibility']);
    });

    it('validates game_system_id exists in database', function () {
        $user = gameCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Games\CreateGame::class)
            ->set('name', 'Test Game')
            ->set('date_time', now()->addDays(7)->format('Y-m-d\TH:i'))
            ->set('game_system_id', 'nonexistent-id')
            ->call('save')
            ->assertHasErrors(['game_system_id']);
    });

    it('sets owner_id to authenticated user', function () {
        $user = gameCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Games\CreateGame::class)
            ->set('name', 'Owned Game')
            ->set('date_time', now()->addDays(7)->format('Y-m-d\TH:i'))
            ->call('save');

        assertDatabaseHas('games', [
            'name' => 'Owned Game',
            'owner_id' => $user->id,
        ]);
    });

    it('sets status to scheduled by default', function () {
        $user = gameCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Games\CreateGame::class)
            ->set('name', 'Scheduled Game')
            ->set('date_time', now()->addDays(7)->format('Y-m-d\TH:i'))
            ->call('save');

        assertDatabaseHas('games', [
            'name' => 'Scheduled Game',
            'status' => 'scheduled',
        ]);
    });

    it('stores location as json with type and details', function () {
        $user = gameCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Games\CreateGame::class)
            ->set('name', 'Located Game')
            ->set('date_time', now()->addDays(7)->format('Y-m-d\TH:i'))
            ->set('location_type', 'online')
            ->set('location_details', 'https://example.com/vtt')
            ->call('save');

        $game = Game::where('name', 'Located Game')->first();
        expect($game->location)->toBe(['type' => 'online', 'details' => 'https://example.com/vtt']);
    });
});

// ── GameDetail ──────────────────────────────────────────

describe('GameDetail', function () {
    it('renders game detail for public game', function () {
        $game = Game::factory()->create([
            'name' => 'Public Game Session',
            'visibility' => 'public',
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertOk()
            ->assertSee('Public Game Session');
    });

    it('shows participants list', function () {
        $game = Game::factory()->create(['visibility' => 'public']);
        $player = User::factory()->create(['name' => 'Alice Player']);
        GameParticipant::create([
            
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee('Alice Player')
            ->assertSee('PLAYER');
    });

    it('shows owner badge for the game owner', function () {
        ['owner' => $owner, 'game' => $game] = gameCrudCreateGameWithOwner(['visibility' => 'public']);

        actingAs($owner)
            ->get(route('games.detail', $game->id))
            ->assertOk()
            ->assertSee('Owner');
    });

    it('shows game system name', function () {
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $game = Game::factory()->create([
            'game_system_id' => $system->id,
            'visibility' => 'public',
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee('D&D 5e');
    });

    it('returns 404 for nonexistent game', function () {
        get(route('games.detail', 'nonexistent-id'))
            ->assertNotFound();
    });

    it('denies access to private game for non-owner non-participant', function () {
        $game = Game::factory()->create(['visibility' => 'private']);
        $stranger = User::factory()->create(['profile_complete' => true]);

        actingAs($stranger)
            ->get(route('games.detail', $game->id))
            ->assertForbidden();
    });

    it('allows owner to view private game', function () {
        ['owner' => $owner, 'game' => $game] = gameCrudCreateGameWithOwner(['visibility' => 'private']);

        actingAs($owner)
            ->get(route('games.detail', $game->id))
            ->assertOk()
            ->assertSee($game->name);
    });

    it('shows empty state when no participants', function () {
        $game = Game::factory()->create(['visibility' => 'public']);

        Livewire\Livewire::test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee('No participants yet');
    });
});

// ── CreateCampaign ──────────────────────────────────────

describe('CreateCampaign', function () {
    it('redirects unauthenticated users', function () {
        get(route('campaigns.create'))
            ->assertRedirect(route('login'));
    });

    it('shows the create campaign form', function () {
        $user = campaignCrudCreateUserWithPermission();

        actingAs($user)
            ->get(route('campaigns.create'))
            ->assertOk()
            ->assertSee('Create Campaign');
    });

    it('creates a campaign with valid data', function () {
        $user = campaignCrudCreateUserWithPermission();
        $system = GameSystem::factory()->create();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Shadows of Waterdeep')
            ->set('game_system_id', $system->id)
            ->set('description', 'An epic campaign set in the Forgotten Realms')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('session_duration', '3')
            ->set('price_per_session', '10.00')
            ->set('visibility', 'public')
            ->set('location_type', 'online')
            ->set('location_details', 'https://roll20.net/join/456')
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('campaigns', [
            'name' => 'Shadows of Waterdeep',
            'owner_id' => $user->id,
            'game_system_id' => $system->id,
            'recurrence' => 'weekly',
            'visibility' => 'public',
            'status' => 'active',
        ]);
    });

    it('validates required fields', function () {
        $user = campaignCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', '')
            ->set('recurrence', '')
            ->set('time_of_day', '')
            ->call('save')
            ->assertHasErrors(['name', 'recurrence', 'time_of_day']);
    });

    it('validates name max length', function () {
        $user = campaignCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', str_repeat('A', 256))
            ->call('save')
            ->assertHasErrors(['name']);
    });

    it('validates recurrence must be valid option', function () {
        $user = campaignCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Test Campaign')
            ->set('recurrence', 'invalid')
            ->call('save')
            ->assertHasErrors(['recurrence']);
    });

    it('validates time_of_day format', function () {
        $user = campaignCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Test Campaign')
            ->set('time_of_day', 'not-a-time')
            ->call('save')
            ->assertHasErrors(['time_of_day']);
    });

    it('sets owner_id to authenticated user', function () {
        $user = campaignCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Owned Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->call('save');

        assertDatabaseHas('campaigns', [
            'name' => 'Owned Campaign',
            'owner_id' => $user->id,
        ]);
    });

    it('sets status to active by default', function () {
        $user = campaignCrudCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Active Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->call('save');

        assertDatabaseHas('campaigns', [
            'name' => 'Active Campaign',
            'status' => 'active',
        ]);
    });
});

// ── CampaignDetail ──────────────────────────────────────

describe('CampaignDetail', function () {
    it('renders campaign detail for public campaign', function () {
        $campaign = Campaign::factory()->create([
            'name' => 'Public Campaign',
            'visibility' => 'public',
        ]);

        Livewire\Livewire::test(App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertOk()
            ->assertSee('Public Campaign');
    });

    it('shows participants list', function () {
        $campaign = Campaign::factory()->create(['visibility' => 'public']);
        $player = User::factory()->create(['name' => 'Bob Player']);
        CampaignParticipant::create([
            
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::test(App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Bob Player');
    });

    it('shows owner badge for the campaign owner', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignCrudCreateCampaignWithOwner(['visibility' => 'public']);

        actingAs($owner)
            ->get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertSee('Owner');
    });

    it('shows linked sessions', function () {
        $campaign = Campaign::factory()->create(['visibility' => 'public']);
        $session = Game::factory()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Session 1: The Beginning',
            'visibility' => 'public',
        ]);

        Livewire\Livewire::test(App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Session 1: The Beginning');
    });

    it('shows recurrence info', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => 'public',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
        ]);

        Livewire\Livewire::test(App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Weekly')
            ->assertSee('19:00');
    });

    it('returns 404 for nonexistent campaign', function () {
        get(route('campaigns.detail', 'nonexistent-id'))
            ->assertNotFound();
    });

    it('denies access to private campaign for non-owner non-participant', function () {
        $campaign = Campaign::factory()->create(['visibility' => 'private']);
        $stranger = User::factory()->create(['profile_complete' => true]);

        actingAs($stranger)
            ->get(route('campaigns.detail', $campaign->id))
            ->assertForbidden();
    });

    it('allows owner to view private campaign', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignCrudCreateCampaignWithOwner(['visibility' => 'private']);

        actingAs($owner)
            ->get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertSee($campaign->name);
    });

    it('shows empty state when no participants', function () {
        $campaign = Campaign::factory()->create(['visibility' => 'public']);

        Livewire\Livewire::test(App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('No participants yet');
    });

    it('shows empty state when no sessions', function () {
        $campaign = Campaign::factory()->create(['visibility' => 'public']);

        Livewire\Livewire::test(App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('No sessions scheduled yet');
    });
});
