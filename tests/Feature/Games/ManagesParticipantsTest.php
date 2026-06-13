<?php

use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Campaigns\ApplyToCampaign;
use App\Livewire\Campaigns\ManageParticipants as CampaignManageParticipants;
use App\Livewire\Games\ApplyToGame;
use App\Livewire\Games\ManageParticipants as GameManageParticipants;
use App\Models\CampaignApplication;
use App\Models\CampaignParticipant;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserRelationship;
use Tests\Traits\CreatesGameInstances;
use Tests\Traits\CreatesRelationships;

uses(CreatesGameInstances::class, CreatesRelationships::class);

// ═══════════════════════════════════════════════════════════
// FRIEND INVITE JOIN SOURCE
// ═══════════════════════════════════════════════════════════

describe('Friend Invite join_source attribution', function () {
    test('game invite sets join_source to friend_invite', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        $this->makeMutualFriends($owner, $friend);

        Livewire\Livewire::actingAs($owner)
            ->test(GameManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
            'join_source' => JoinSource::FriendInvite->value,
        ]);
    });

    test('campaign invite sets join_source to friend_invite', function () {
        ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        $this->makeMutualFriends($owner, $friend);

        Livewire\Livewire::actingAs($owner)
            ->test(CampaignManageParticipants::class, ['id' => $campaign->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $friend->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
            'join_source' => JoinSource::FriendInvite->value,
        ]);
    });

    test('inviting multiple friends all get friend_invite join_source', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $friend1 = User::factory()->create(['profile_complete' => true]);
        $friend2 = User::factory()->create(['profile_complete' => true]);
        $this->makeMutualFriends($owner, $friend1);
        $this->makeMutualFriends($owner, $friend2);

        Livewire\Livewire::actingAs($owner)
            ->test(GameManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend1->id, $friend2->id])
            ->call('inviteParticipants')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend1->id,
            'join_source' => JoinSource::FriendInvite->value,
        ]);
        $this->assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend2->id,
            'join_source' => JoinSource::FriendInvite->value,
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// APPLICATION SUBMISSION JOIN SOURCE
// ═══════════════════════════════════════════════════════════

describe('Application submission join_source attribution', function () {
    test('public game application sets join_source to application', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($user)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->call('submitApplication')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'join_source' => JoinSource::Application->value,
        ]);
    });

    test('protected game application sets join_source to application', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'protected']);
        $user = User::factory()->create(['profile_complete' => true]);
        UserRelationship::follow($user, $owner);
        UserRelationship::follow($owner, $user);

        Livewire\Livewire::actingAs($user)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->call('submitApplication')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'join_source' => JoinSource::Application->value,
        ]);
    });

    test('public campaign application sets join_source to application', function () {
        ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner(['visibility' => 'public']);
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($user)
            ->test(ApplyToCampaign::class, ['id' => $campaign->id])
            ->call('submitApplication')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'join_source' => JoinSource::Application->value,
        ]);
    });

    test('protected campaign application sets join_source to application', function () {
        ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner(['visibility' => 'protected']);
        $user = User::factory()->create(['profile_complete' => true]);
        UserRelationship::follow($user, $owner);
        UserRelationship::follow($owner, $user);

        Livewire\Livewire::actingAs($user)
            ->test(ApplyToCampaign::class, ['id' => $campaign->id])
            ->call('submitApplication')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'join_source' => JoinSource::Application->value,
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// APPLICATION APPROVAL JOIN SOURCE
// ═══════════════════════════════════════════════════════════

describe('Application approval join_source attribution', function () {
    test('approving a game application sets join_source to application', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner(['visibility' => 'protected']);
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
            'message' => null,
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(GameManageParticipants::class, ['id' => $game->id])
            ->call('approveApplication', $participant->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'join_source' => JoinSource::Application->value,
        ]);
    });

    test('approving a campaign application sets join_source to application', function () {
        ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner(['visibility' => 'protected']);
        $applicant = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => ParticipantStatus::Pending->value,
            'message' => null,
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(CampaignManageParticipants::class, ['id' => $campaign->id])
            ->call('approveApplication', $participant->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'join_source' => JoinSource::Application->value,
        ]);
    });
});
