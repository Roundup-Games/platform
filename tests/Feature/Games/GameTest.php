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

// ═══════════════════════════════════════════════════════════
// GAME POLICY — VISIBILITY & OWNERSHIP
// ═══════════════════════════════════════════════════════════

describe('GamePolicy — Ownership Actions', function () {
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
});

// ═══════════════════════════════════════════════════════════
// CREATE GAME — ROUTE & COMPONENT
// (Route tests + direct-set creation tests; type-selection flow
//  covered by tests/Feature/Livewire/Games/CreateGameTest.php)
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

describe('CreateGame Component — Direct Set + Save', function () {
    it('creates game with all optional fields filled', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_type', 'board_game')
            ->set('name', 'Epic One-Shot Adventure')
            ->set('game_system_id', $system->id)
            ->set('date_time', now()->addDays(3)->format('Y-m-d\TH:i'))
            ->set('description', 'A thrilling adventure awaits!')
            ->set('expected_duration', '4')
            ->set('price', '15.00')
            ->set('language', 'en')
            ->set('visibility', 'protected')
            ->set('max_players', 6)
            ->call('save')
            ->assertRedirect();

        $game = Game::where('name->en', 'Epic One-Shot Adventure')->first();
        expect($game)->not->toBeNull()
            ->and($game->owner_id)->toBe($user->id)
            ->and($game->game_system_id)->toBe($system->id)
            ->and($game->visibility->value)->toBe('protected')
            ->and($game->status->value)->toBe('scheduled');
    });

    it('creates game with minimum required fields only', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_type', 'board_game')
            ->set('name', 'Quick Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 6)
            ->call('save')
            ->assertRedirect();

        $game = Game::where('name->en', 'Quick Game')->first();
        expect($game)->not->toBeNull()
            ->and($game->owner_id)->toBe($user->id)
            ->and($game->status->value)->toBe('scheduled');

        expect($game->expected_duration)->toBe(2.0) // board game type default
            ->and($game->price)->toBe(0.0); // default
    });

    it('stores location_id from LocationPicker', function () {
        $user = gameTestCreateUserWithPermission();
        $location = \App\Models\Location::factory()->create();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_type', 'board_game')
            ->set('name', 'Location Test')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('location_id', $location->id)
            ->set('max_players', 6)
            ->call('save');

        $game = Game::where('name->en', 'Location Test')->first();
        expect($game->location_id)->toBe($location->id);
    });

});

// ═══════════════════════════════════════════════════════════
// GAME DETAIL — ROUTE & COMPONENT
// ═══════════════════════════════════════════════════════════

describe('Game Detail Route', function () {
    it('shows public game via Livewire component', function () {
        $game = gameTestCreateGame(['visibility' => 'public', 'name' => ['en' => 'Open Session']]);

        // Use Livewire directly since the layout has auth()-dependent code
        Livewire\Livewire::test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertOk()
            ->assertSee('Open Session');
    });

    it('shows public game to authenticated user via route', function () {
        $game = gameTestCreateGame(['visibility' => 'public', 'name' => ['en' => 'Open Session']]);
        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user)
            ->get(route('games.show', $game->id))
            ->assertOk()
            ->assertSee('Open Session');
    });

});

// ═══════════════════════════════════════════════════════════
// GAME PARTICIPANT WORKFLOWS
// ═══════════════════════════════════════════════════════════

describe('Game Manage Participants — Authorization', function () {
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
            ->assertRedirect(route('games.show', $game->id));

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

        // Records are deleted (so user can re-apply)
        assertDatabaseMissing('game_participants', [
            'id' => $participant->id,
        ]);

        assertDatabaseMissing('game_applications', [
            'game_id' => $game->id,
            'user_id' => $applicant->id,
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

        // Record is deleted (so user can re-apply)
        assertDatabaseMissing('game_participants', [
            'id' => $participant->id,
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

        // Record is deleted (so user can be re-invited)
        assertDatabaseMissing('game_participants', [
            'id' => $participant->id,
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// GAME SESSION CREATION — NEW FIELDS
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Duration', function () {
    it('defaults to 2.0 hours for board games', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_type', 'board_game')
            ->set('name', 'No Duration')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 6)
            ->call('save');

        $game = Game::where('name->en', 'No Duration')->first();
        expect($game)->not->toBeNull()
            ->and($game->expected_duration)->toBe(2.0);
    });

    it('rounds duration to nearest 0.5 on update', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_type', 'board_game')
            ->set('expected_duration', '2.3')
            ->assertSet('expected_duration', '2.5');
    });

    it('auto-fills from game system average_play_time', function () {
        $user = gameTestCreateUserWithPermission();
        $system = GameSystem::factory()->create(['average_play_time' => 120]); // 2 hours

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_type', 'board_game')
            ->set('name', 'Auto Duration')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('game_system_id', $system->id)
            ->assertSet('expected_duration', '2')
            ->set('max_players', 6)
            ->call('save');

        $game = Game::where('name->en', 'Auto Duration')->first();
        expect($game)->not->toBeNull()
            ->and($game->expected_duration)->toBe(2.0);
    });

});

describe('CreateGame — Player Counts', function () {
    it('rejects min_players exceeding max_players', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_type', 'board_game')
            ->set('name', 'Bad Range')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('min_players', 5)
            ->set('max_players', 3)
            ->call('save')
            ->assertHasErrors(['min_players']);
    });
});

describe('CreateGame — Vibe Flags', function () {
    it('stores favorite vibe flags from picker preferences', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_type', 'board_game')
            ->set('name', 'Vibey Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('onVibePreferencesChanged', [
                'atmospheric' => 'favorite',
                'roleplay-heavy' => 'favorite',
                'horror' => 'favorite',
                'cooperative' => 'avoid',
            ])
            ->set('max_players', 6)
            ->call('save');

        $game = Game::where('name->en', 'Vibey Game')->first();
        expect($game->vibe_flags)->toContain('atmospheric', 'roleplay-heavy', 'horror')
            ->and($game->vibe_flags)->not->toContain('cooperative');
    });

    it('filters invalid vibe flag values from preferences', function () {
        $user = gameTestCreateUserWithPermission();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_type', 'board_game')
            ->set('name', 'Test')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('onVibePreferencesChanged', [
                'atmospheric' => 'favorite',
                'not-a-real-flag' => 'favorite',
            ])
            ->set('max_players', 6)
            ->call('save');

        // Invalid flag silently filtered; valid one stored
        $game = Game::where('name->en', 'Test')->first();
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
            ->set('game_type', 'board_game')
            ->set('name', 'Full Auto Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('game_system_id', $system->id)
            ->assertSet('expected_duration', '3')
            ->assertSet('min_players', 3)
            ->assertSet('max_players', 6)
            ->assertSet('complexity', '2.5')
            ->call('save');

        $game = Game::where('name->en', 'Full Auto Game')->first();
        expect($game)->not->toBeNull()
            ->and($game->expected_duration)->toBe(3.0)
            ->and($game->min_players)->toBe(3)
            ->and($game->max_players)->toBe(6);
    });
});

// ═══════════════════════════════════════════════════════════
// VISIBILITY GATING — can_create_public_entries
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Visibility Gating', function () {
    it('allows public visibility when user has can_create_public_entries', function () {
        $user = gameTestCreateUserWithPermission(canCreatePublic: true);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_type', 'board_game')
            ->set('name', 'Public Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('visibility', 'public')
            ->set('max_players', 6)
            ->call('save')
            ->assertRedirect();

        $game = Game::where('name->en', 'Public Game')->first();
        expect($game)->not->toBeNull()
            ->and($game->visibility->value)->toBe('public');
    });

    it('demotes public to private when user lacks can_create_public_entries', function () {
        $user = gameTestCreateUserWithPermission(canCreatePublic: false);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_type', 'board_game')
            ->set('name', 'Attempted Public')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('visibility', 'public')
            ->set('max_players', 6)
            ->call('save')
            ->assertRedirect();

        $game = Game::where('name->en', 'Attempted Public')->first();
        expect($game)->not->toBeNull()
            ->and($game->visibility->value)->toBe('private');
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
        $campaign = \App\Models\Campaign::factory()->create(['name' => ['en' => 'The Grand Adventure']]);
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

