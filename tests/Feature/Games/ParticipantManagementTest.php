<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\ApplyToGame;
use App\Livewire\Games\ManageParticipants;
use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Traits\CreatesGameInstances;
use Tests\Traits\CreatesRelationships;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\get;

uses(CreatesGameInstances::class, CreatesRelationships::class);

// ═══════════════════════════════════════════════════════════
// GAME MANAGE PARTICIPANTS
// ═══════════════════════════════════════════════════════════

describe('Game ManageParticipants Authorization', function () {
    test('owner can access manage participants page', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

        actingAs($owner)
            ->get(route('games.manage-participants', $game->id))
            ->assertOk()
            ->assertSeeLivewire('games.manage-participants');
    });

    test('non-owner cannot access manage participants page', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $stranger = User::factory()->create(['profile_complete' => true]);

        actingAs($stranger)
            ->get(route('games.manage-participants', $game->id))
            ->assertForbidden();
    });

    test('guest is redirected to login for manage participants', function () {
        $game = Game::factory()->create();

        get(route('games.manage-participants', $game->id))
            ->assertRedirect(route('login'));
    });
});

describe('Game Invite Participant', function () {
    test('owner can invite a friend by user ID', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        $this->makeMutualFriends($owner, $friend);

        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants')
            ->assertHasNoErrors()
            ->assertSee('friend invited');

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
    })->group('smoke');

    test('cannot invite with empty selection', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [])
            ->call('inviteParticipants')
            ->assertHasErrors(['selectedFriendIds']);
    });

    test('cannot invite yourself', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$owner->id])
            ->call('inviteParticipants');

        // Self should be skipped, not invited
        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Invited->value,
        ]);
    });

    test('cannot invite non-friend', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $stranger = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$stranger->id])
            ->call('inviteParticipants');

        // Non-friend should be skipped, not invited
        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $stranger->id,
            'role' => ParticipantRole::Invited->value,
        ]);
    });

    test('cannot invite user who is already a participant', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        $this->makeMutualFriends($owner, $friend);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        // Should not create a duplicate participant
        $this->assertEquals(1, GameParticipant::where('game_id', $game->id)
            ->where('user_id', $friend->id)
            ->count());
    });

    test('can invite multiple friends at once', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $friend1 = User::factory()->create(['profile_complete' => true]);
        $friend2 = User::factory()->create(['profile_complete' => true]);
        $this->makeMutualFriends($owner, $friend1);
        $this->makeMutualFriends($owner, $friend2);

        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend1->id, $friend2->id])
            ->call('inviteParticipants')
            ->assertHasNoErrors()
            ->assertSee('friends invited');

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend1->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend2->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
    });
});

describe('Game Approve/Reject Application', function () {
    test('owner can approve an application', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $applicant = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'status' => ParticipantStatus::Pending->value,
            'message' => 'I want to join!',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->call('approveApplication', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Application approved');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        assertDatabaseHas('game_applications', [
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'status' => ParticipantStatus::Approved->value,
        ]);
    })->group('smoke');

    test('owner can reject an application', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $applicant = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->call('rejectApplication', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Application rejected');

        assertDatabaseMissing('game_participants', [
            'id' => $participant->id,
        ]);

        assertDatabaseMissing('game_applications', [
            'game_id' => $game->id,
            'user_id' => $applicant->id,
        ]);
    });

    test('cannot approve non-applicant participant', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $user = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $component = Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->call('approveApplication', $participant->id);

        // Status should not have changed
        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    });
});

describe('Game Remove Participant', function () {
    test('owner can remove a player', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $player = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Participant removed');

        // Participant record should be status=removed (not hard-deleted)
        // to preserve roster history for host cancellation audit
        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => ParticipantStatus::Removed->value,
        ]);
    });

    test('cannot remove the game owner', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

        // Owner participant already created by createGameWithOwner
        $ownerParticipant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)
            ->where('role', 'owner')
            ->firstOrFail();

        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', $ownerParticipant->id)
            ->assertSee('Cannot remove the game owner');

        // Owner should still be approved
        assertDatabaseHas('game_participants', [
            'id' => $ownerParticipant->id,
            'status' => ParticipantStatus::Approved->value,
        ]);
    });
});

describe('Game Cancel Invite', function () {
    test('owner can cancel a pending invite', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->call('cancelInvite', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Invite cancelled');

        assertDatabaseMissing('game_participants', [
            'id' => $participant->id,
        ]);
    });

    test('cannot cancel non-invited participant', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $player = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Canceling a non-invited participant should throw ModelNotFoundException
        try {
            Livewire\Livewire::actingAs($owner)
                ->test(ManageParticipants::class, ['id' => $game->id])
                ->call('cancelInvite', $participant->id);
            $this->fail('Expected exception was not thrown');
        } catch (ModelNotFoundException $e) {
            $this->assertTrue(true); // Expected
        }
    });
});

// ═══════════════════════════════════════════════════════════
// GAME APPLICATION
// ═══════════════════════════════════════════════════════════

describe('Game ApplyToGame', function () {
    test('authenticated user can view apply page for public game', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user)
            ->get(route('games.apply', $game->id))
            ->assertOk()
            ->assertSeeLivewire('games.apply-to-game');
    });

    test('authenticated user can view apply page for protected game', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'protected']);
        $user = User::factory()->create(['profile_complete' => true]);

        // Protected games require friend/teammate relationship for view access
        UserRelationship::follow($user, $owner);
        UserRelationship::follow($owner, $user);

        actingAs($user)
            ->get(route('games.apply', $game->id))
            ->assertOk();
    });

    test('cannot apply to private game', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'private']);
        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user)
            ->get(route('games.apply', $game->id))
            ->assertForbidden();
    });

    test('public game application auto-approves', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($user)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Excited to play!')
            ->call('submitApplication')
            ->assertHasNoErrors()
            ->assertRedirect(route('games.show', $game->id));

        // Application and participant both reflect auto-approval for public games
        assertDatabaseHas('game_applications', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Participant should be auto-approved as player
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    });

    test('protected game application requires approval', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'protected']);
        $user = User::factory()->create(['profile_complete' => true]);

        // Protected games require friend/teammate relationship for view access
        UserRelationship::follow($user, $owner);
        UserRelationship::follow($owner, $user);

        Livewire\Livewire::actingAs($user)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Please let me join')
            ->call('submitApplication')
            ->assertHasNoErrors()
            ->assertRedirect(route('games.show', $game->id));

        // Application should be pending
        assertDatabaseHas('game_applications', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        // Participant should be pending applicant
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
    });

    test('owner cannot apply to own game', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'public']);

        Livewire\Livewire::actingAs($owner)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'My own game')
            ->call('submitApplication')
            ->assertHasErrors(['message']);
    });

    test('cannot apply twice to same game', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        // First application
        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        // Should show info message that user already has pending application
        Livewire\Livewire::actingAs($user)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->assertSee('already a participant');
    });

    test('existing participant sees info message', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->assertSee('already a participant');
    });

    test('application without message is valid', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($user)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', '')
            ->call('submitApplication')
            ->assertHasNoErrors();

        assertDatabaseHas('game_applications', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);
    });

    test('message is limited to 1000 characters', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($user)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', str_repeat('a', 1001))
            ->call('submitApplication')
            ->assertHasErrors(['message' => 'max']);
    });
});

// ═══════════════════════════════════════════════════════════
// GAME STATUS TRANSITIONS (End-to-End Flow)
// ═══════════════════════════════════════════════════════════

describe('Game Participant Status Transitions', function () {
    test('full application lifecycle: apply → approve → remove', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'protected']);
        $user = User::factory()->create(['profile_complete' => true]);

        // Step 1: User applies to a protected game
        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Pending->value,
            'message' => 'Pick me!',
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        // Verify pending state
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
        assertDatabaseHas('game_applications', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Pending->value,
            'message' => 'Pick me!',
        ]);

        // Step 2: Owner approves
        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->call('approveApplication', $participant->id);

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Step 3: Owner removes — record stays with status=removed
        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', $participant->id);

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Removed->value,
        ]);
    });

    test('full invite lifecycle: invite → cancel', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        $this->makeMutualFriends($owner, $friend);

        // Step 1: Owner invites via friend IDs
        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        // Step 2: Owner cancels invite
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $friend->id)
            ->first();

        Livewire\Livewire::actingAs($owner)
            ->test(ManageParticipants::class, ['id' => $game->id])
            ->call('cancelInvite', $participant->id);

        assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend->id,
        ]);
    });
});
