<?php

use App\Enums\ParticipantRole;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\EntityInvitation;
use App\Notifications\ParticipantJoined;
use App\Notifications\ParticipantRemoved;
use App\Notifications\TeamMemberRemoved;
use App\Enums\ParticipantStatus;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ══════════════════════════════════════════════════════
// Accept Invitation → ParticipantJoined (Game)
// ══════════════════════════════════════════════════════

describe('Accept game invitation → ParticipantJoined', function () {
    it('dispatches ParticipantJoined to game owner when invitation accepted via GamesPage', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $invitee = User::factory()->create(['profile_complete' => true]);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitee->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        \Livewire\Livewire::actingAs($invitee)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('acceptInvitation', (string) $participant->id);

        $notifications = $owner->notifications()->where('type', ParticipantJoined::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('participant_joined')
            ->and($data['participant_id'])->toBe($invitee->id)
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data)->toHaveKey('action_url');
    });

    it('marks GameInvitation notification as read when accepting', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $invitee = User::factory()->create(['profile_complete' => true]);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
        ]);

        // Create an unread GameInvitation notification for the invitee
        $invitee->notifyNow(new EntityInvitation($game, $owner));
        expect($invitee->unreadNotifications()->where('type', EntityInvitation::class)->count())->toBe(1);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitee->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        \Livewire\Livewire::actingAs($invitee)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('acceptInvitation', (string) $participant->id);

        // GameInvitation notification should now be marked as read
        expect($invitee->unreadNotifications()->where('type', EntityInvitation::class)->count())->toBe(0);
    });

    it('does not dispatch when owner has blocked the participant', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $invitee = User::factory()->create(['profile_complete' => true]);

        \App\Models\UserRelationship::block($owner, $invitee);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitee->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        \Livewire\Livewire::actingAs($invitee)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('acceptInvitation', (string) $participant->id);

        // Invitation accepted (status changed) but no notification to owner
        expect($owner->notifications()->where('type', ParticipantJoined::class)->count())->toBe(0);
    });


});

// ══════════════════════════════════════════════════════
// Accept Invitation → ParticipantJoined (Campaign)
// ══════════════════════════════════════════════════════

describe('Accept campaign invitation → ParticipantJoined', function () {
    it('dispatches ParticipantJoined to campaign owner via CampaignsPage', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $invitee = User::factory()->create(['profile_complete' => true]);

        $gameSystem = GameSystem::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'active',
        ]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $invitee->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        \Livewire\Livewire::actingAs($invitee)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('acceptInvitation', (string) $participant->id);

        $notifications = $owner->notifications()->where('type', ParticipantJoined::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('participant_joined')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['participant_id'])->toBe($invitee->id);
    });
});

// ══════════════════════════════════════════════════════
// Remove Participant
// ══════════════════════════════════════════════════════

describe('Remove participant → ParticipantRemoved', function () {
    it('dispatches ParticipantRemoved to the removed user', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => GameSystem::factory()->create()->id,
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', (string) $participant->id);

        $notifications = $player->notifications()->where('type', ParticipantRemoved::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('participant_removed')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['removed_user_id'])->toBe($player->id)
            ->and($data)->toHaveKey('action_url');
    });



    it('does not dispatch ParticipantRemoved when trying to remove the owner', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => GameSystem::factory()->create()->id,
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', (string) $participant->id);

        $notifications = $owner->notifications()->where('type', ParticipantRemoved::class)->get();
        expect($notifications)->toHaveCount(0);
    });
});

// ══════════════════════════════════════════════════════
// Remove Team Member
// ══════════════════════════════════════════════════════

describe('Remove team member → TeamMemberRemoved', function () {
    it('dispatches TeamMemberRemoved to the removed user', function () {
        $captain = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);

        $team = Team::factory()->create(['created_by' => $captain->id]);

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $captain->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        \Livewire\Livewire::actingAs($captain)
            ->test(\App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('removeMember', $member->id);

        $notifications = $player->notifications()->where('type', TeamMemberRemoved::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('team_member_removed')
            ->and($data['entity_id'])->toBe($team->id)
            ->and($data['entity_name'])->toBe($team->name)
            ->and($data['remover_id'])->toBe($captain->id)
            ->and($data)->toHaveKey('action_url');
    });



    it('does not dispatch TeamMemberRemoved for last captain removal', function () {
        $captain = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['created_by' => $captain->id]);

        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $captain->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        \Livewire\Livewire::actingAs($captain)
            ->test(\App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('removeMember', $member->id);

        // Should not remove (last captain), so no notification
        $notifications = $captain->notifications()->where('type', TeamMemberRemoved::class)->get();
        expect($notifications)->toHaveCount(0);
    });
});
