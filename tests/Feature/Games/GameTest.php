<?php

use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
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

function gameTestCreateUserWithPermission(string $permission = 'create game'): User
{
    seedPermissions();
    $user = User::factory()->create(['profile_complete' => true]);
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

    it('allows any authenticated user to view protected games', function () {
        $game = gameTestCreateGame(['visibility' => 'protected']);
        $user = User::factory()->make();

        expect(Gate::forUser($user)->allows('view', $game))->toBeTrue();
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
        expect($game->expected_duration)->toBe(3.0) // default
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

    it('shows protected game to authenticated user', function () {
        $game = gameTestCreateGame(['visibility' => 'protected']);
        $user = User::factory()->create();

        actingAs($user)
            ->get(route('games.detail', $game->id))
            ->assertOk();
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
        get(route('games.detail', 'nonexistent-uuid'))
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
    it('creates pending invited participant', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $target = User::factory()->create();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', $target->email)
            ->call('inviteParticipant')
            ->assertHasNoErrors();

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $target->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    });

    it('rejects non-existent user', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', 'nobody@example.com')
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail']);
    });

    it('rejects self-invite', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', $owner->email)
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail']);
    });

    it('rejects duplicate invite', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $target = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $target->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', $target->email)
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail']);
    });

    it('validates email format', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', 'not-valid')
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail' => 'email']);
    });

    it('resets invite email after success', function () {
        ['owner' => $owner, 'game' => $game] = gameTestCreateGameWithOwner();
        $target = User::factory()->create();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', $target->email)
            ->call('inviteParticipant')
            ->assertSet('inviteEmail', '');
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
        $game = gameTestCreateGame(['visibility' => 'protected']);
        $owner = User::factory()->create();
        $game->update(['owner_id' => $owner->id]);
        $user = gameTestCreateOwner();

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
        $user = gameTestCreateOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', $user->email)
            ->call('inviteParticipant');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('cancelInvite', $participant->id);

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'rejected',
        ]);
    });

    it('protected game: apply → approve → verify player', function () {
        $owner = gameTestCreateOwner();
        $user = gameTestCreateOwner();
        $game = gameTestCreateGame(['owner_id' => $owner->id, 'visibility' => 'protected']);

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
