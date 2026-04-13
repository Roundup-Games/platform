<?php

use App\Models\Campaign;
use App\Models\CampaignApplication;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\User;
use function Pest\Laravel\{actingAs, assertDatabaseHas, get, post};

// ── Helpers ──────────────────────────────────────────────

function participantCreateGameWithOwner(array $gameAttrs = []): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        ...$gameAttrs,
    ]);

    return ['owner' => $owner, 'game' => $game];
}

function participantCreateCampaignWithOwner(array $campaignAttrs = []): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        ...$campaignAttrs,
    ]);

    return ['owner' => $owner, 'campaign' => $campaign];
}

// ═══════════════════════════════════════════════════════════
// GAME MANAGE PARTICIPANTS
// ═══════════════════════════════════════════════════════════

describe('Game ManageParticipants Authorization', function () {
    test('owner can access manage participants page', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();

        actingAs($owner)
            ->get(route('games.manage-participants', $game->id))
            ->assertOk()
            ->assertSeeLivewire('games.manage-participants');
    });

    test('non-owner cannot access manage participants page', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();
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
    test('owner can invite a user by email', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();
        $targetUser = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', $targetUser->email)
            ->call('inviteParticipant')
            ->assertHasNoErrors()
            ->assertSee('Invite sent to ' . $targetUser->email);

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $targetUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    });

    test('cannot invite non-existent email', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', 'nonexistent@example.com')
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail']);
    });

    test('cannot invite yourself', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', $owner->email)
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail']);
    });

    test('cannot invite user who is already a participant', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();
        $user = User::factory()->create(['profile_complete' => true]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', $user->email)
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail']);
    });

    test('invite email is required', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', '')
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail' => 'required']);
    });

    test('invite email must be valid email', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', 'not-an-email')
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail' => 'email']);
    });
});

describe('Game Approve/Reject Application', function () {
    test('owner can approve an application', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();
        $applicant = User::factory()->create(['profile_complete' => true]);

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
            'message' => 'I want to join!',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('approveApplication', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Application approved');

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

    test('owner can reject an application', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();
        $applicant = User::factory()->create(['profile_complete' => true]);

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
            ->call('rejectApplication', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Application rejected');

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

    test('cannot approve non-applicant participant', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();
        $user = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $component = Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('approveApplication', $participant->id);

        // Status should not have changed
        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    });
});

describe('Game Remove Participant', function () {
    test('owner can remove a player', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();
        $player = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Participant removed');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);
    });

    test('cannot remove the game owner', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();

        // Create an owner participant record
        $ownerParticipant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', $ownerParticipant->id)
            ->assertSee('Cannot remove the game owner');

        // Owner should still be approved
        assertDatabaseHas('game_participants', [
            'id' => $ownerParticipant->id,
            'status' => 'approved',
        ]);
    });
});

describe('Game Cancel Invite', function () {
    test('owner can cancel a pending invite', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('cancelInvite', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Invite cancelled');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);
    });

    test('cannot cancel non-invited participant', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();
        $player = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        // Canceling a non-invited participant should throw ModelNotFoundException
        try {
            Livewire\Livewire::actingAs($owner)
                ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
                ->call('cancelInvite', $participant->id);
            $this->fail('Expected exception was not thrown');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->assertTrue(true); // Expected
        }
    });
});

// ═══════════════════════════════════════════════════════════
// GAME APPLICATION
// ═══════════════════════════════════════════════════════════

describe('Game ApplyToGame', function () {
    test('authenticated user can view apply page for public game', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user)
            ->get(route('games.apply', $game->id))
            ->assertOk()
            ->assertSeeLivewire('games.apply-to-game');
    });

    test('authenticated user can view apply page for protected game', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner(['visibility' => 'protected']);
        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user)
            ->get(route('games.apply', $game->id))
            ->assertOk();
    });

    test('cannot apply to private game', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner(['visibility' => 'private']);
        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user)
            ->get(route('games.apply', $game->id))
            ->assertForbidden();
    });

    test('public game application auto-approves', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Excited to play!')
            ->call('submitApplication')
            ->assertHasNoErrors()
            ->assertRedirect(route('games.detail', $game->id));

        // Application should be approved
        assertDatabaseHas('game_applications', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'approved',
        ]);

        // Participant should be auto-approved as player
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    });

    test('protected game application requires approval', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        // Manually create protected game scenario by changing visibility after mount
        // (Protected games work the same for Livewire testing since view policy allows auth users)
        $game->update(['visibility' => 'protected']);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Please let me join')
            ->call('submitApplication')
            ->assertHasNoErrors()
            ->assertRedirect(route('games.detail', $game->id));

        // Application should be pending
        assertDatabaseHas('game_applications', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        // Participant should be pending applicant
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);
    });

    test('owner cannot apply to own game', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner(['visibility' => 'public']);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'My own game')
            ->call('submitApplication')
            ->assertHasErrors(['message']);
    });

    test('cannot apply twice to same game', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        // First application
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

        // Should show info message that user already has pending application
        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->assertSee('already a participant');
    });

    test('existing participant sees info message', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->assertSee('already a participant');
    });

    test('application without message is valid', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', '')
            ->call('submitApplication')
            ->assertHasNoErrors();

        assertDatabaseHas('game_applications', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'approved',
        ]);
    });

    test('message is limited to 1000 characters', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', str_repeat('a', 1001))
            ->call('submitApplication')
            ->assertHasErrors(['message' => 'max']);
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN MANAGE PARTICIPANTS
// ═══════════════════════════════════════════════════════════

describe('Campaign ManageParticipants Authorization', function () {
    test('owner can access manage participants page', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();

        actingAs($owner)
            ->get(route('campaigns.manage-participants', $campaign->id))
            ->assertOk()
            ->assertSeeLivewire('campaigns.manage-participants');
    });

    test('non-owner cannot access manage participants page', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();
        $stranger = User::factory()->create(['profile_complete' => true]);

        actingAs($stranger)
            ->get(route('campaigns.manage-participants', $campaign->id))
            ->assertForbidden();
    });

    test('guest is redirected to login for manage participants', function () {
        $campaign = Campaign::factory()->create();

        get(route('campaigns.manage-participants', $campaign->id))
            ->assertRedirect(route('login'));
    });
});

describe('Campaign Invite Participant', function () {
    test('owner can invite a user by email', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();
        $targetUser = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('inviteEmail', $targetUser->email)
            ->call('inviteParticipant')
            ->assertHasNoErrors()
            ->assertSee('Invite sent to ' . $targetUser->email);

        assertDatabaseHas('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $targetUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    });

    test('cannot invite non-existent email', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('inviteEmail', 'ghost@example.com')
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail']);
    });

    test('cannot invite yourself', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('inviteEmail', $owner->email)
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail']);
    });

    test('cannot invite user who is already a participant', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();
        $user = User::factory()->create(['profile_complete' => true]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('inviteEmail', $user->email)
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail']);
    });
});

describe('Campaign Approve/Reject Application', function () {
    test('owner can approve an application', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();
        $applicant = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => 'pending',
            'message' => 'I love this campaign!',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('approveApplication', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Application approved');

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        assertDatabaseHas('campaign_applications', [
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => 'approved',
        ]);
    });

    test('owner can reject an application', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();
        $applicant = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('rejectApplication', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Application rejected');

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);

        assertDatabaseHas('campaign_applications', [
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => 'rejected',
        ]);
    });

    test('cannot approve non-applicant participant', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();
        $user = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('approveApplication', $participant->id);

        // Status should not have changed
        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    });
});

describe('Campaign Remove Participant', function () {
    test('owner can remove a player', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();
        $player = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('removeParticipant', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Participant removed');

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);
    });

    test('cannot remove the campaign owner', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();

        $ownerParticipant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('removeParticipant', $ownerParticipant->id)
            ->assertSee('Cannot remove the campaign owner');

        assertDatabaseHas('campaign_participants', [
            'id' => $ownerParticipant->id,
            'status' => 'approved',
        ]);
    });
});

describe('Campaign Cancel Invite', function () {
    test('owner can cancel a pending invite', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('cancelInvite', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Invite cancelled');

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);
    });

    test('cannot cancel non-invited participant', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner();
        $player = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        // Canceling a non-invited participant should throw ModelNotFoundException
        try {
            Livewire\Livewire::actingAs($owner)
                ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
                ->call('cancelInvite', $participant->id);
            $this->fail('Expected exception was not thrown');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->assertTrue(true); // Expected
        }
    });
});

// ═══════════════════════════════════════════════════════════
// GAME STATUS TRANSITIONS (End-to-End Flow)
// ═══════════════════════════════════════════════════════════

describe('Game Participant Status Transitions', function () {
    test('full application lifecycle: apply → approve → remove', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner(['visibility' => 'protected']);
        $user = User::factory()->create(['profile_complete' => true]);

        // Step 1: User applies to a protected game
        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'message' => 'Pick me!',
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        // Verify pending state
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
            'message' => 'Pick me!',
        ]);

        // Step 2: Owner approves
        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('approveApplication', $participant->id);

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        // Step 3: Owner removes
        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', $participant->id);

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'rejected',
        ]);
    });

    test('full invite lifecycle: invite → cancel', function () {
        ['owner' => $owner, 'game' => $game] = participantCreateGameWithOwner();
        $user = User::factory()->create(['profile_complete' => true]);

        // Step 1: Owner invites
        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('inviteEmail', $user->email)
            ->call('inviteParticipant');

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        // Step 2: Owner cancels invite
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
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN STATUS TRANSITIONS (End-to-End Flow)
// ═══════════════════════════════════════════════════════════

describe('Campaign Participant Status Transitions', function () {
    test('full campaign application lifecycle: apply → approve → remove', function () {
        ['owner' => $owner, 'campaign' => $campaign] = participantCreateCampaignWithOwner(['visibility' => 'protected']);
        $user = User::factory()->create(['profile_complete' => true]);

        // Step 1: Apply via participant + application record (simulating what ApplyToGame would do for campaigns)
        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'message' => 'Please add me!',
        ]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        // Step 2: Owner approves
        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('approveApplication', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        // Step 3: Owner removes
        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('removeParticipant', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'status' => 'rejected',
        ]);
    });
});
