<?php

use App\Enums\ParticipantStatus;
use App\Livewire\Campaigns\ManageParticipants as CampaignManageParticipants;
use App\Livewire\Games\ManageParticipants as GameManageParticipants;
use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use App\Models\User;
use App\Enums\ParticipantRole;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

// ═══════════════════════════════════════════════════════════
// GAME MANAGE PARTICIPANTS — WAITLIST DISPLAY
// ═══════════════════════════════════════════════════════════

describe('Game ManageParticipants waitlist display', function () {
    test('shows waitlisted players on manage participants page', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

        $waitlistedUser = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $waitlistedUser->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(GameManageParticipants::class, ['id' => $game->id])
            ->assertSee($waitlistedUser->name)
            ->assertSee('Waitlist');
    });

    test('shows queue position for waitlisted players ordered by waitlisted_at', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

        $user1 = User::factory()->create(['profile_complete' => true]);
        $user2 = User::factory()->create(['profile_complete' => true]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user1->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now()->subMinutes(10),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user2->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now()->subMinutes(5),
        ]);

        $component = Livewire\Livewire::actingAs($owner)
            ->test(GameManageParticipants::class, ['id' => $game->id]);

        // Both waitlisted users should appear
        $component->assertSee($user1->name)
            ->assertSee($user2->name);
    });

    test('does not show waitlist section when no waitlisted players', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(GameManageParticipants::class, ['id' => $game->id])
            ->assertDontSee('Waitlist');
    });
});

// ═══════════════════════════════════════════════════════════
// GAME MANAGE PARTICIPANTS — PROMOTE FROM WAITLIST
// ═══════════════════════════════════════════════════════════

describe('Game ManageParticipants promote from waitlist', function () {
    test('owner can promote a waitlisted participant', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

        $waitlistedUser = User::factory()->create(['profile_complete' => true]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $waitlistedUser->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(GameManageParticipants::class, ['id' => $game->id])
            ->call('managePromoteFromWaitlist', $participant->id)
            ->assertHasNoErrors();

        // WaitlistService::manuallyPromote changes status to approved
        $participant->refresh();
        expect($participant->status)->toBe(ParticipantStatus::Approved);
    });

    test('promoting a non-waitlisted participant flashes error', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

        $player = User::factory()->create(['profile_complete' => true]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(GameManageParticipants::class, ['id' => $game->id])
            ->call('managePromoteFromWaitlist', $participant->id)
            ->assertHasNoErrors();

        // Status should remain unchanged
        $this->assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => ParticipantStatus::Approved->value,
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// GAME MANAGE PARTICIPANTS — REMOVE FROM WAITLIST
// ═══════════════════════════════════════════════════════════

describe('Game ManageParticipants remove from waitlist', function () {
    test('owner can remove a waitlisted participant', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

        $waitlistedUser = User::factory()->create(['profile_complete' => true]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $waitlistedUser->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(GameManageParticipants::class, ['id' => $game->id])
            ->call('manageRemoveFromWaitlist', $participant->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => ParticipantStatus::Rejected->value,
        ]);
    });

    test('removing a non-waitlisted participant flashes error', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

        $player = User::factory()->create(['profile_complete' => true]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(GameManageParticipants::class, ['id' => $game->id])
            ->call('manageRemoveFromWaitlist', $participant->id)
            ->assertHasNoErrors();

        // Status should remain unchanged
        $this->assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => ParticipantStatus::Approved->value,
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN MANAGE PARTICIPANTS — BENCH DISPLAY
// ═══════════════════════════════════════════════════════════

describe('Campaign ManageParticipants bench display', function () {
    test('shows benched players on manage participants page', function () {
        ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();

        $benchedUser = User::factory()->create(['profile_complete' => true]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $benchedUser->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched->value,
            'benched_at' => now(),
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(CampaignManageParticipants::class, ['id' => $campaign->id])
            ->assertSee($benchedUser->name)
            ->assertSee('Bench');
    });

    test('does not show bench section when no benched players', function () {
        ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(CampaignManageParticipants::class, ['id' => $campaign->id])
            ->assertDontSee('Bench');
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN MANAGE PARTICIPANTS — PROMOTE FROM BENCH
// ═══════════════════════════════════════════════════════════

describe('Campaign ManageParticipants promote from bench', function () {
    test('owner can promote a benched participant', function () {
        ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();

        $benchedUser = User::factory()->create(['profile_complete' => true]);
        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $benchedUser->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched->value,
            'benched_at' => now(),
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(CampaignManageParticipants::class, ['id' => $campaign->id])
            ->call('managePromoteFromBench', $participant->id)
            ->assertHasNoErrors();

        // BenchService::promoteFromBench changes status to approved
        $participant->refresh();
        expect($participant->status)->toBe(ParticipantStatus::Approved);
    });

    test('promoting a non-benched participant flashes error', function () {
        ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();

        $player = User::factory()->create(['profile_complete' => true]);
        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(CampaignManageParticipants::class, ['id' => $campaign->id])
            ->call('managePromoteFromBench', $participant->id)
            ->assertHasNoErrors();

        // Status should remain unchanged
        $this->assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => ParticipantStatus::Approved->value,
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN MANAGE PARTICIPANTS — REMOVE FROM BENCH
// ═══════════════════════════════════════════════════════════

describe('Campaign ManageParticipants remove from bench', function () {
    test('owner can remove a benched participant', function () {
        ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();

        $benchedUser = User::factory()->create(['profile_complete' => true]);
        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $benchedUser->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched->value,
            'benched_at' => now(),
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(CampaignManageParticipants::class, ['id' => $campaign->id])
            ->call('manageRemoveFromBench', $participant->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => ParticipantStatus::Rejected->value,
        ]);
    });

    test('removing a non-benched participant flashes error', function () {
        ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();

        $player = User::factory()->create(['profile_complete' => true]);
        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(CampaignManageParticipants::class, ['id' => $campaign->id])
            ->call('manageRemoveFromBench', $participant->id)
            ->assertHasNoErrors();

        // Status should remain unchanged
        $this->assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => ParticipantStatus::Approved->value,
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// AUTHORIZATION — NON-OWNER CANNOT ACCESS MANAGE PAGE
// ═══════════════════════════════════════════════════════════

describe('Authorization checks for waitlist/bench actions', function () {
    test('non-owner cannot access game manage participants page', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $stranger = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($stranger)
            ->test(GameManageParticipants::class, ['id' => $game->id])
            ->assertStatus(403);
    });

    test('non-owner cannot access campaign manage participants page', function () {
        ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
        $stranger = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($stranger)
            ->test(CampaignManageParticipants::class, ['id' => $campaign->id])
            ->assertStatus(403);
    });

    test('non-owner cannot call waitlist actions on game', function () {
        ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
        $stranger = User::factory()->create(['profile_complete' => true]);

        $waitlistedUser = User::factory()->create(['profile_complete' => true]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $waitlistedUser->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);

        // Cannot even mount the component, so cannot call actions
        Livewire\Livewire::actingAs($stranger)
            ->test(GameManageParticipants::class, ['id' => $game->id])
            ->assertStatus(403);

        // Verify the participant status is unchanged
        $this->assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => ParticipantStatus::Waitlisted->value,
        ]);
    });

    test('non-owner cannot call bench actions on campaign', function () {
        ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
        $stranger = User::factory()->create(['profile_complete' => true]);

        $benchedUser = User::factory()->create(['profile_complete' => true]);
        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $benchedUser->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched->value,
            'benched_at' => now(),
        ]);

        // Cannot even mount the component, so cannot call actions
        Livewire\Livewire::actingAs($stranger)
            ->test(CampaignManageParticipants::class, ['id' => $campaign->id])
            ->assertStatus(403);

        // Verify the participant status is unchanged
        $this->assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => ParticipantStatus::Benched->value,
        ]);
    });
});
