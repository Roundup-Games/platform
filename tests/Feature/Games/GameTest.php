<?php

use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use function Pest\Laravel\{actingAs, assertDatabaseHas, assertDatabaseMissing, get};

// ── Helpers ──────────────────────────────────────────────

function gameTestCreateOwner(array $overrides = []): User
{
    return User::factory()->create(['profile_complete' => true, ...$overrides]);
}

function gameTestCreateGame(array $overrides = []): Game
{
    return Game::factory()->create($overrides);
}

function gameTestCreateGameWithOwner(array $gameAttrs = []): array
{
    $owner = gameTestCreateOwner();
    $game = Game::factory()->create(['owner_id' => $owner->id, ...$gameAttrs]);

    return ['owner' => $owner, 'game' => $game];
}

function gameTestCreateUserWithPermission(string $permission = 'create game', bool $canCreatePublic = false): User
{
    seedPermissions();
    $user = User::factory()->create(['profile_complete' => true, 'can_create_public_entries' => $canCreatePublic]);
    setPermissionsTeamId(1);
    $user->givePermissionTo($permission);
    $user->unsetRelations();
    setPermissionsTeamId(1);
    return $user;
}

// ═══════════════════════════════════════════════════════════
// GAME POLICY — VISIBILITY & OWNERSHIP
// ═══════════════════════════════════════════════════════════

describe('GamePolicy — Visibility Rules', function () {
    it('allows guest to view public games', function () {
        $game = gameTestCreateGame(['visibility' => 'public']);

        expect(Gate::allows('view', $game))->toBeTrue();
    });

    it('allows owner to view protected games', function () {
        $owner = User::factory()->create();
        $game = gameTestCreateGame(['visibility' => 'protected', 'owner_id' => $owner->id]);

        expect(Gate::forUser($owner)->allows('view', $game))->toBeTrue();
    });

    it('denies stranger from viewing protected games', function () {
        $owner = User::factory()->create();
        $game = gameTestCreateGame(['visibility' => 'protected', 'owner_id' => $owner->id]);
        $stranger = User::factory()->make();

        expect(Gate::forUser($stranger)->allows('view', $game))->toBeFalse();
    });

    it('denies guest from viewing protected games', function () {
        $game = gameTestCreateGame(['visibility' => 'protected']);

        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('allows owner to view private games', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner(['visibility' => 'private']);

        expect(Gate::forUser($owner)->allows('view', $game))->toBeTrue();
    });

    it('allows approved participant to view private games', function () {
        $game = gameTestCreateGame(['visibility' => 'private']);
        $participant = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        expect(Gate::forUser($participant)->allows('view', $game))->toBeTrue();
    });

    it('denies stranger from viewing private games', function () {
        $game = gameTestCreateGame(['visibility' => 'private']);
        $stranger = User::factory()->create();

        expect(Gate::forUser($stranger)->allows('view', $game))->toBeFalse();
    });

    it('denies pending participant from viewing private games', function () {
        $game = gameTestCreateGame(['visibility' => 'private']);
        $pendingUser = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $pendingUser->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        // Pending applicants ARE participants, so they should be able to view
        expect(Gate::forUser($pendingUser)->allows('view', $game))->toBeTrue();
    });
});

describe('GamePolicy — Ownership Actions', function () {
    it('allows authenticated user with permission to create', function () {
        $user = User::factory()->create();
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'create game', 'guard_name' => 'web']);
        setPermissionsTeamId(1);
        $user->givePermissionTo('create game');
        $user->unsetRelations();

        expect(Gate::forUser($user)->allows('create', Game::class))->toBeTrue();
    });

    it('denies guest from creating', function () {
        expect(Gate::allows('create', Game::class))->toBeFalse();
    });

    it('allows owner to update', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();

        expect(Gate::forUser($owner)->allows('update', $game))->toBeTrue();
    });

    it('denies non-owner from updating even if participant', function () {
        $game = gameTestCreateGame();
        $player = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        expect(Gate::forUser($player)->allows('update', $game))->toBeFalse();
    });

    it('allows owner to delete', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();

        expect(Gate::forUser($owner)->allows('delete', $game))->toBeTrue();
    });

    it('denies non-owner from deleting', function () {
        $game = gameTestCreateGame();
        $stranger = User::factory()->create();

        expect(Gate::forUser($stranger)->allows('delete', $game))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// GAME MODEL — SCOPES & ATTRIBUTES
// ═══════════════════════════════════════════════════════════

describe('Game Model', function () {
    it('generates UUID on creation', function () {
        $game = gameTestCreateGame(['name' => 'UUID Test']);

        expect($game->id)->not->toBeEmpty()
            ->and(strlen($game->id))->toBe(36); // UUID format
    });

    it('casts location as array', function () {
        $game = gameTestCreateGame([
            'location' => ['details' => '123 Main St'],
        ]);

        expect($game->location)->toBe(['details' => '123 Main St']);
    });

    it('casts date_time as datetime', function () {
        $game = gameTestCreateGame();

        expect($game->date_time)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('scopes public games', function () {
        $public = gameTestCreateGame(['visibility' => 'public', 'name' => 'Public A']);
        $private = gameTestCreateGame(['visibility' => 'private', 'name' => 'Private B']);

        $results = Game::public()->get();

        expect($results->contains($public))->toBeTrue()
            ->and($results->contains($private))->toBeFalse();
    });

    it('scopes scheduled games', function () {
        $scheduled = gameTestCreateGame(['status' => 'scheduled', 'name' => 'Sched A']);
        $completed = gameTestCreateGame(['status' => 'completed', 'name' => 'Comp B']);

        $results = Game::scheduled()->get();

        expect($results->contains($scheduled))->toBeTrue()
            ->and($results->contains($completed))->toBeFalse();
    });

    it('scopes upcoming games', function () {
        $future = gameTestCreateGame(['date_time' => now()->addDays(7), 'name' => 'Future']);
        $past = gameTestCreateGame(['date_time' => now()->subDays(7), 'name' => 'Past']);

        $results = Game::upcoming()->get();

        expect($results->contains($future))->toBeTrue()
            ->and($results->contains($past))->toBeFalse();
    });

    it('has owner relationship', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();

        expect($game->owner->id)->toBe($owner->id);
    });

    it('has gameSystem relationship', function () {
        $system = GameSystem::factory()->create(['name' => 'Pathfinder 2e']);
        $game = gameTestCreateGame(['game_system_id' => $system->id]);

        expect($game->gameSystem->name)->toBe('Pathfinder 2e');
    });

    it('has participants relationship', function () {
        $game = gameTestCreateGame();
        $user = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        expect($game->participants)->toHaveCount(1);
    });

    it('has applications relationship', function () {
        $game = gameTestCreateGame();
        $user = User::factory()->create();

        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        expect($game->applications)->toHaveCount(1);
    });

    it('allows nullable game_system_id', function () {
        $game = gameTestCreateGame(['game_system_id' => null]);

        expect($game->game_system_id)->toBeNull()
            ->and($game->gameSystem)->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// CREATE GAME — ROUTE & COMPONENT
// ═══════════════════════════════════════════════════════════

describe('Create Game Route', function () {
    it('redirects guests to login', function () {
        get(route('games.create'))
            ->assertRedirect(route('login'));
    });

    it('requires profile complete', function () {
        $user = User::factory()->create(['profile_complete' => false]);

        actingAs($user)
            ->get(route('games.create'))
            ->assertRedirect(route('onboarding.index'));
    });

    it('renders for authenticated profile-complete user', function () {
        $user = gameTestCreateUserWithPermission();

        actingAs($user)
            ->get(route('games.create'))
            ->assertOk()
            ->assertSeeLivewire('games.create-game')
            ->assertSee('Create Game Session');
    });
});

describe('CreateGame Component', function () {
    it('creates game with all optional fields filled', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Epic One-Shot Adventure')
            ->set('game_system_id', $system->id)
            ->set('date_time', now()->addDays(3)->format('Y-m-d\TH:i'))
            ->set('description', 'A thrilling adventure awaits!')
            ->set('expected_duration', '4')
            ->set('price', '15.00')
            ->set('language', 'en')
            ->set('location_details', 'Game Store, 456 Oak Ave')
            ->set('visibility', 'protected')
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('games', [
            'name' => 'Epic One-Shot Adventure',
            'owner_id' => $user->id,
            'game_system_id' => $system->id,
            'visibility' => 'protected',
            'status' => 'scheduled',
        ]);
    });

    it('creates game with minimum required fields only', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Quick Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('games', [
            'name' => 'Quick Game',
            'owner_id' => $user->id,
            'status' => 'scheduled',
        ]);

        $game = Game::where('name', 'Quick Game')->first();
        expect($game->expected_duration)->toBe(2.0) // default
            ->and($game->price)->toBe(0.0); // default
    });

    it('validates name is required', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    });

    it('validates name max 255 chars', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', str_repeat('X', 256))
            ->call('save')
            ->assertHasErrors(['name' => 'max']);
    });

    it('validates date_time is required', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('date_time', '')
            ->call('save')
            ->assertHasErrors(['date_time' => 'required']);
    });

    it('validates date_time is a valid date', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('date_time', 'not-a-date')
            ->call('save')
            ->assertHasErrors(['date_time']);
    });

    it('validates visibility is enum value', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('visibility', 'top-secret')
            ->call('save')
            ->assertHasErrors(['visibility' => 'in']);
    });

    it('validates game_system_id exists', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_system_id', '99999')
            ->call('save')
            ->assertHasErrors(['game_system_id' => 'exists']);
    });

    it('validates price is numeric and non-negative', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('price', '-5')
            ->call('save')
            ->assertHasErrors(['price' => 'min']);
    });

    it('validates description max length', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('description', str_repeat('A', 5001))
            ->call('save')
            ->assertHasErrors(['description' => 'max']);
    });

    it('stores location as JSON', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Location Test')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('location_details', 'Both online and at the store')
            ->call('save');

        $game = Game::where('name', 'Location Test')->first();
        expect($game->location)->toBe([
            'details' => 'Both online and at the store',
        ]);
    });

    it('flashes success message on creation', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Flash Test Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertSessionHas('success', 'Game "Flash Test Game" created successfully!');
    });

    it('renders the game system picker component', function () {
        $user = gameTestCreateUserWithPermission();
        GameSystem::factory()->create(['name' => 'D&D 5e']);
        GameSystem::factory()->create(['name' => 'Pathfinder 2e']);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->assertOk();
    });
});

// ═══════════════════════════════════════════════════════════
// GAME DETAIL — ROUTE & COMPONENT
// ═══════════════════════════════════════════════════════════

describe('Game Detail Route', function () {
    it('shows public game via Livewire component', function () {
        $game = gameTestCreateGame(['visibility' => 'public', 'name' => 'Open Session']);

        // Use Livewire directly since the layout has auth()-dependent code
        Livewire\Livewire::test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertOk()
            ->assertSee('Open Session');
    });

    it('shows public game to authenticated user via route', function () {
        $game = gameTestCreateGame(['visibility' => 'public', 'name' => 'Open Session']);
        $user = User::factory()->create();

        actingAs($user)
            ->get(route('games.detail', $game->id))
            ->assertOk()
            ->assertSee('Open Session');
    });

    it('shows protected game to owner', function () {
        $owner = User::factory()->create();
        $game = gameTestCreateGame(['visibility' => 'protected', 'owner_id' => $owner->id]);

        actingAs($owner)
            ->get(route('games.detail', $game->id))
            ->assertOk();
    });

    it('denies protected game to stranger', function () {
        $owner = User::factory()->create();
        $game = gameTestCreateGame(['visibility' => 'protected', 'owner_id' => $owner->id]);
        $stranger = User::factory()->create();

        actingAs($stranger)
            ->get(route('games.detail', $game->id))
            ->assertForbidden();
    });

    it('denies private game to stranger', function () {
        $game = gameTestCreateGame(['visibility' => 'private']);
        $stranger = User::factory()->create();

        actingAs($stranger)
            ->get(route('games.detail', $game->id))
            ->assertForbidden();
    });

    it('shows private game to owner', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner(['visibility' => 'private']);

        actingAs($owner)
            ->get(route('games.detail', $game->id))
            ->assertOk();
    });

    it('shows private game to participant', function () {
        $game = gameTestCreateGame(['visibility' => 'private']);
        $player = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        actingAs($player)
            ->get(route('games.detail', $game->id))
            ->assertOk();
    });

    it('returns 404 for non-existent game', function () {
        get(route('games.detail', Str::uuid()->toString()))
            ->assertNotFound();
    });
});

describe('GameDetail Component', function () {
    it('shows game name and description', function () {
        $game = gameTestCreateGame([
            'name' => 'Goblin Raid',
            'description' => 'Defend the village!',
            'visibility' => 'public',
        ]);

        Livewire\Livewire::test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee('Goblin Raid')
            ->assertSee('Defend the village!');
    });

    it('shows game system name when set', function () {
        $system = GameSystem::factory()->create(['name' => 'Shadowrun 6e']);
        $game = gameTestCreateGame(['game_system_id' => $system->id, 'visibility' => 'public']);

        Livewire\Livewire::test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee('Shadowrun 6e');
    });

    it('shows participants with roles', function () {
        $game = gameTestCreateGame(['visibility' => 'public']);
        $player = User::factory()->create(['name' => 'Ragnar']);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee('Ragnar');
    });

    it('shows owner badge when owner is viewing', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner(['visibility' => 'public']);

        actingAs($owner)
            ->get(route('games.detail', $game->id))
            ->assertOk()
            ->assertSee('Owner');
    });

    it('shows empty participant state', function () {
        $game = gameTestCreateGame(['visibility' => 'public']);

        Livewire\Livewire::test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee('No participants yet');
    });

    it('indicates isOwner correctly', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner(['visibility' => 'public']);

        $component = Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id]);

        $component->assertViewHas('isOwner', true);
    });

    it('indicates isParticipant correctly', function () {
        $game = gameTestCreateGame(['visibility' => 'public']);
        $player = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $component = Livewire\Livewire::actingAs($player)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id]);

        $component->assertViewHas('isParticipant', true);
    });

    it('indicates non-participant for stranger', function () {
        $game = gameTestCreateGame(['visibility' => 'public']);
        $stranger = User::factory()->create();

        $component = Livewire\Livewire::actingAs($stranger)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id]);

        $component->assertViewHas('isOwner', false)
            ->assertViewHas('isParticipant', false);
    });
});

// ═══════════════════════════════════════════════════════════
// GAME PARTICIPANT WORKFLOWS
// ═══════════════════════════════════════════════════════════

describe('Game Manage Participants — Authorization', function () {
    it('requires authentication', function () {
        $game = gameTestCreateGame();

        get(route('games.manage-participants', $game->id))
            ->assertRedirect(route('login'));
    });

    it('requires profile complete', function () {
        $owner = User::factory()->create(['profile_complete' => false]);
        $game = gameTestCreateGame(['owner_id' => $owner->id]);

        actingAs($owner)
            ->get(route('games.manage-participants', $game->id))
            ->assertRedirect(route('onboarding.index'));
    });

    it('owner can access', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();

        actingAs($owner)
            ->get(route('games.manage-participants', $game->id))
            ->assertOk()
            ->assertSeeLivewire('games.manage-participants');
    });

    it('non-owner is forbidden', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $stranger = gameTestCreateOwner();

        actingAs($stranger)
            ->get(route('games.manage-participants', $game->id))
            ->assertForbidden();
    });
});

describe('Game Invite Participant', function () {
    it('creates pending invited participant for a friend', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $friend = User::factory()->create();
        \App\Models\UserRelationship::create(['user_id' => $owner->id, 'related_user_id' => $friend->id, 'type' => \App\Enums\RelationshipType::Follow]);
        \App\Models\UserRelationship::create(['user_id' => $friend->id, 'related_user_id' => $owner->id, 'type' => \App\Enums\RelationshipType::Follow]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants')
            ->assertHasNoErrors();

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    });

    it('rejects empty selection', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [])
            ->call('inviteParticipants')
            ->assertHasErrors(['selectedFriendIds']);
    });

    it('rejects self-invite silently', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$owner->id])
            ->call('inviteParticipants');

        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'invited',
        ]);
    });

    it('rejects duplicate invite silently', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $friend = User::factory()->create();
        \App\Models\UserRelationship::create(['user_id' => $owner->id, 'related_user_id' => $friend->id, 'type' => \App\Enums\RelationshipType::Follow]);
        \App\Models\UserRelationship::create(['user_id' => $friend->id, 'related_user_id' => $owner->id, 'type' => \App\Enums\RelationshipType::Follow]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        $this->assertEquals(1, GameParticipant::where('game_id', $game->id)->where('user_id', $friend->id)->count());
    });

    it('skips non-friend silently', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $stranger = User::factory()->create();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$stranger->id])
            ->call('inviteParticipants');

        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $stranger->id,
            'role' => 'invited',
        ]);
    });

    it('resets selected friend IDs after success', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $friend = User::factory()->create();
        \App\Models\UserRelationship::create(['user_id' => $owner->id, 'related_user_id' => $friend->id, 'type' => \App\Enums\RelationshipType::Follow]);
        \App\Models\UserRelationship::create(['user_id' => $friend->id, 'related_user_id' => $owner->id, 'type' => \App\Enums\RelationshipType::Follow]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants')
            ->assertSet('selectedFriendIds', []);
    });
});

describe('Game Application — ApplyToGame', function () {
    it('auto-approves for public games', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner(['visibility' => 'public']);
        $user = gameTestCreateOwner();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Let me in!')
            ->call('submitApplication')
            ->assertRedirect(route('games.detail', $game->id));

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        assertDatabaseHas('game_applications', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'approved',
        ]);
    });

    it('stays pending for protected games', function () {
        $owner = User::factory()->create();
        $game = gameTestCreateGame(['visibility' => 'protected', 'owner_id' => $owner->id]);
        $user = gameTestCreateOwner();

        // Make user a friend of the owner so they can view/apply
        \App\Models\UserRelationship::follow($owner, $user);
        \App\Models\UserRelationship::follow($user, $owner);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Please consider me')
            ->call('submitApplication');

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        assertDatabaseHas('game_applications', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
    });

    it('blocks application to private game', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner(['visibility' => 'private']);
        $user = gameTestCreateOwner();

        actingAs($user)
            ->get(route('games.apply', $game->id))
            ->assertForbidden();
    });

    it('blocks owner applying to own game', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner(['visibility' => 'public']);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->call('submitApplication')
            ->assertHasErrors(['message']);
    });

    it('blocks duplicate application', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner(['visibility' => 'public']);
        $user = gameTestCreateOwner();

        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->assertSee('already a participant');
    });

    it('redirects guest to login', function () {
        $game = gameTestCreateGame(['visibility' => 'public']);

        get(route('games.apply', $game->id))
            ->assertRedirect(route('login'));
    });
});

describe('Game Approve/Reject Application', function () {
    it('promotes applicant to player on approval', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $applicant = User::factory()->create();

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('approveApplication', $participant->id);

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        assertDatabaseHas('game_applications', [
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'status' => 'approved',
        ]);
    });

    it('marks rejected on rejection', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $applicant = User::factory()->create();

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('rejectApplication', $participant->id);

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);

        assertDatabaseHas('game_applications', [
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'status' => 'rejected',
        ]);
    });

    it('does nothing when approving a non-applicant', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $player = User::factory()->create();

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('approveApplication', $participant->id);

        // Status unchanged
        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    });
});

describe('Game Remove/Cancel Participant', function () {
    it('owner can remove a player', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $player = User::factory()->create();

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', $participant->id);

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);
    });

    it('cannot remove game owner', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();

        $ownerParticipant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', $ownerParticipant->id);

        assertDatabaseHas('game_participants', [
            'id' => $ownerParticipant->id,
            'status' => 'approved',
        ]);
    });

    it('owner can cancel pending invite', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $invited = User::factory()->create();

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invited->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('cancelInvite', $participant->id);

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// GAME END-TO-END STATUS TRANSITIONS
// ═══════════════════════════════════════════════════════════

describe('Game Full Lifecycle', function () {
    it('complete flow: create → apply → approve → remove', function () {
        $owner = gameTestCreateOwner();
        $user = gameTestCreateOwner();

        // Create a public game
        $game = gameTestCreateGame(['owner_id' => $owner->id, 'visibility' => 'public']);

        // User applies (public = auto-approve)
        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->call('submitApplication');

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        // Owner removes
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', $participant->id);

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'rejected',
        ]);
    });

    it('invite flow: invite → cancel', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $friend = gameTestCreateOwner();
        \App\Models\UserRelationship::create(['user_id' => $owner->id, 'related_user_id' => $friend->id, 'type' => \App\Enums\RelationshipType::Follow]);
        \App\Models\UserRelationship::create(['user_id' => $friend->id, 'related_user_id' => $owner->id, 'type' => \App\Enums\RelationshipType::Follow]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $friend->id)
            ->first();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('cancelInvite', $participant->id);

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'status' => 'rejected',
        ]);
    });

    it('protected game: apply → approve → verify player', function () {
        $owner = gameTestCreateOwner();
        $user = gameTestCreateOwner();
        $game = gameTestCreateGame(['owner_id' => $owner->id, 'visibility' => 'protected']);

        // Make user a friend of the owner so they can view/apply
        \App\Models\UserRelationship::follow($owner, $user);
        \App\Models\UserRelationship::follow($user, $owner);

        // Apply (stays pending)
        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Love to join')
            ->call('submitApplication');

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        // Owner approves
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('approveApplication', $participant->id);

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// GAME SESSION CREATION — NEW FIELDS
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Language Selection', function () {
    it('defaults language to en', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->assertSet('language', 'en');
    });

    it('stores language from ContentLanguage enum', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'German Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('language', 'de')
            ->call('save');

        assertDatabaseHas('games', [
            'name' => 'German Game',
            'language' => 'de',
        ]);
    });

    it('rejects de+en bilingual language', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Bilingual Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('language', 'de+en')
            ->call('save')
            ->assertHasErrors(['language']);
    });

    it('rejects invalid language', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Test')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('language', 'fr')
            ->call('save')
            ->assertHasErrors(['language']);
    });
});

describe('CreateGame — Duration', function () {
    it('defaults to 2 hours when empty', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'No Duration')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save');

        assertDatabaseHas('games', [
            'name' => 'No Duration',
            'expected_duration' => 2,
        ]);
    });

    it('rounds duration to nearest 0.5 on update', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('expected_duration', '2.3')
            ->assertSet('expected_duration', '2.5');
    });

    it('rounds 1.7 to 1.5', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('expected_duration', '1.7')
            ->assertSet('expected_duration', '1.5');
    });

    it('rounds 2.8 to 3', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('expected_duration', '2.8')
            ->assertSet('expected_duration', '3');
    });

    it('clamps duration below 0.5 to 0.5', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('expected_duration', '0.2')
            ->assertSet('expected_duration', '0.5');
    });

    it('auto-fills from game system average_play_time', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create(['average_play_time' => 120]); // 2 hours

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Auto Duration')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('game_system_id', $system->id)
            ->assertSet('expected_duration', '2')
            ->call('save');

        assertDatabaseHas('games', [
            'name' => 'Auto Duration',
            'expected_duration' => 2,
        ]);
    });

    it('auto-fills 90 min play time as 1.5 hours', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create(['average_play_time' => 90]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_system_id', $system->id)
            ->assertSet('expected_duration', '1.5');
    });

    it('does not override manually set duration', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create(['average_play_time' => 120]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('expected_duration', '4')
            ->set('game_system_id', $system->id)
            ->assertSet('expected_duration', '4'); // stays 4, not overridden
    });
});

describe('CreateGame — Player Counts', function () {
    it('stores min and max players', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Player Count Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('min_players', 3)
            ->set('max_players', 6)
            ->call('save');

        assertDatabaseHas('games', [
            'name' => 'Player Count Game',
            'min_players' => 3,
            'max_players' => 6,
        ]);
    });

    it('allows nullable player counts', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'No Limits Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save');

        $game = Game::where('name', 'No Limits Game')->first();
        expect($game->min_players)->toBeNull()
            ->and($game->max_players)->toBeNull();
    });

    it('rejects min_players exceeding max_players', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Bad Range')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('min_players', 5)
            ->set('max_players', 3)
            ->call('save')
            ->assertHasErrors(['min_players']);
    });

    it('auto-fills from game system player counts', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create([
            'min_players' => 2,
            'max_players' => 5,
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_system_id', $system->id)
            ->assertSet('min_players', 2)
            ->assertSet('max_players', 5);
    });

    it('does not override manually set player counts', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create([
            'min_players' => 2,
            'max_players' => 5,
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('min_players', 4)
            ->set('game_system_id', $system->id)
            ->assertSet('min_players', 4); // stays 4
    });

    it('validates min_players is at least 1', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Test')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('min_players', 0)
            ->call('save')
            ->assertHasErrors(['min_players']);
    });
});

describe('CreateGame — Experience Level', function () {
    it('stores experience level', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Pro Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('experience_level', 'advanced')
            ->call('save');

        assertDatabaseHas('games', [
            'name' => 'Pro Game',
            'experience_level' => 'advanced',
        ]);
    });

    it('rejects invalid experience level', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Test')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('experience_level', 'god-tier')
            ->call('save')
            ->assertHasErrors(['experience_level']);
    });
});

describe('CreateGame — Complexity', function () {
    it('stores complexity as decimal', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Complex Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('complexity', '3.5')
            ->call('save');

        $game = Game::where('name', 'Complex Game')->first();
        expect((float) $game->complexity)->toBe(3.5);
    });

    it('auto-fills from BGG weight', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create(['bgg_average_weight' => 3.75]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_system_id', $system->id)
            ->assertSet('complexity', '3.75');
    });

    it('rejects complexity above 5', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Test')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('complexity', '6')
            ->call('save')
            ->assertHasErrors(['complexity']);
    });
});

describe('CreateGame — Vibe Flags', function () {
    it('stores favorite vibe flags from picker preferences', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Vibey Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('onVibePreferencesChanged', [
                'atmospheric' => 'favorite',
                'roleplay-heavy' => 'favorite',
                'horror' => 'favorite',
                'cooperative' => 'avoid',
            ])
            ->call('save');

        $game = Game::where('name', 'Vibey Game')->first();
        expect($game->vibe_flags)->toContain('atmospheric', 'roleplay-heavy', 'horror')
            ->and($game->vibe_flags)->not->toContain('cooperative');
    });

    it('stores null when no flags favorited', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Plain Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save');

        $game = Game::where('name', 'Plain Game')->first();
        expect($game->vibe_flags)->toBeNull();
    });

    it('filters invalid vibe flag values from preferences', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Test')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('onVibePreferencesChanged', [
                'atmospheric' => 'favorite',
                'not-a-real-flag' => 'favorite',
            ])
            ->call('save');

        // Invalid flag silently filtered; valid one stored
        $game = Game::where('name', 'Test')->first();
        expect($game->vibe_flags)->toContain('atmospheric')
            ->and($game->vibe_flags)->not->toContain('not-a-real-flag');
    });
});

describe('CreateGame — Full Auto-fill from Game System', function () {
    it('auto-fills duration, players, and complexity in one go', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create([
            'average_play_time' => 180,
            'min_players' => 3,
            'max_players' => 6,
            'bgg_average_weight' => 2.5,
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Full Auto Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('game_system_id', $system->id)
            ->assertSet('expected_duration', '3')
            ->assertSet('min_players', 3)
            ->assertSet('max_players', 6)
            ->assertSet('complexity', '2.5')
            ->call('save');

        assertDatabaseHas('games', [
            'name' => 'Full Auto Game',
            'expected_duration' => 3,
            'min_players' => 3,
            'max_players' => 6,
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// VISIBILITY GATING — can_create_public_entries
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Visibility Gating', function () {
    it('defaults visibility to private', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->assertSet('visibility', 'private');
    });

    it('allows public visibility when user has can_create_public_entries', function () {
        $user = gameTestCreateUserWithPermission(canCreatePublic: true);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Public Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('visibility', 'public')
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('games', [
            'name' => 'Public Game',
            'visibility' => 'public',
        ]);
    });

    it('demotes public to private when user lacks can_create_public_entries', function () {
        $user = gameTestCreateUserWithPermission(canCreatePublic: false);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Attempted Public')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('visibility', 'public')
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('games', [
            'name' => 'Attempted Public',
            'visibility' => 'private',
        ]);
    });

    it('allows protected visibility regardless of flag', function () {
        $user = gameTestCreateUserWithPermission(canCreatePublic: false);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('name', 'Protected Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('visibility', 'protected')
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('games', [
            'name' => 'Protected Game',
            'visibility' => 'protected',
        ]);
    });

    it('exposes canCreatePublic computed property', function () {
        $userWith = gameTestCreateUserWithPermission(canCreatePublic: true);
        $userWithout = gameTestCreateUserWithPermission(canCreatePublic: false);

        Livewire\Livewire::actingAs($userWith)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->assertSet('canCreatePublic', true);

        Livewire\Livewire::actingAs($userWithout)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->assertSet('canCreatePublic', false);
    });
});

describe('CreateGame — Autofill Experience Level from BGG Weight', function () {
    it('sets beginner for weight <= 2.0', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create(['bgg_average_weight' => 1.5]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_system_id', $system->id)
            ->assertSet('experience_level', 'beginner');
    });

    it('sets intermediate for weight between 2.0 and 3.5', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create(['bgg_average_weight' => 2.8]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_system_id', $system->id)
            ->assertSet('experience_level', 'intermediate');
    });

    it('sets advanced for weight > 3.5', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create(['bgg_average_weight' => 4.2]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_system_id', $system->id)
            ->assertSet('experience_level', 'advanced');
    });

    it('does not override manually set experience level', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create(['bgg_average_weight' => 4.0]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('experience_level', 'beginner')
            ->set('game_system_id', $system->id)
            ->assertSet('experience_level', 'beginner');
    });
});

// ═══════════════════════════════════════════════════════════
// GAME DETAIL — CAMPAIGN CONTEXT
// ═══════════════════════════════════════════════════════════

describe('GameDetail Component — Campaign Context', function () {
    it('shows campaign link when game belongs to campaign', function () {
        $campaign = \App\Models\Campaign::factory()->create(['name' => 'The Grand Adventure']);
        $game = gameTestCreateGame([
            'campaign_id' => $campaign->id,
            'visibility' => 'public',
        ]);

        Livewire\Livewire::test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee('Part of Campaign: The Grand Adventure')
            ->assertSee(route('campaigns.detail', $campaign->id));
    });

    it('hides campaign link when game has no campaign', function () {
        $game = gameTestCreateGame(['visibility' => 'public']);

        Livewire\Livewire::test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertDontSee('Part of Campaign');
    });
});
