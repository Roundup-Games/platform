<?php

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\CampaignInvitation;
use App\Notifications\GameInvitation;
use App\Notifications\ParticipantJoined;
use App\Notifications\ParticipantRemoved;
use App\Notifications\TeamMemberRemoved;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ── ManagesParticipants::acceptInvitation() ────────────

describe('Game invitation accept via ManageParticipants → ParticipantJoined + mark-read', function () {
    it('does not allow non-owner to accept invitation via ManageParticipants (policy blocked)', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        // Invited user cannot mount ManageParticipants (not authorized to update)
        // So no ParticipantJoined dispatched
        $notifications = $owner->notifications()->where('type', ParticipantJoined::class)->get();
        expect($notifications)->toHaveCount(0);
    });
});

describe('Campaign invitation accept via ManageParticipants → ParticipantJoined + mark-read', function () {
    it('does not allow non-owner to accept invitation via ManageParticipants (policy blocked)', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        // Invited user cannot mount ManageParticipants (not authorized to update)
        // So no ParticipantJoined dispatched
        $notifications = $owner->notifications()->where('type', ParticipantJoined::class)->get();
        expect($notifications)->toHaveCount(0);
    });
});

// ── ManagesParticipants::removeParticipant() ───────────

describe('Remove game participant → ParticipantRemoved', function () {
    it('dispatches ParticipantRemoved to removed user', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', $participant->id)
            ->assertHasNoErrors();

        $notifications = $player->notifications()->where('type', ParticipantRemoved::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('participant_removed')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['removed_user_id'])->toBe($player->id);
    });
});

describe('Remove campaign participant → ParticipantRemoved', function () {
    it('dispatches ParticipantRemoved to removed user', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('removeParticipant', $participant->id)
            ->assertHasNoErrors();

        $notifications = $player->notifications()->where('type', ParticipantRemoved::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('participant_removed')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['removed_user_id'])->toBe($player->id);
    });
});

describe('Remove owner → no notification', function () {
    it('does not dispatch ParticipantRemoved when trying to remove the owner', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('removeParticipant', $participant->id);

        $notifications = $owner->notifications()->where('type', ParticipantRemoved::class)->get();
        expect($notifications)->toHaveCount(0);
    });
});

// ── GamesPage::acceptInvitation() ──────────────────────

describe('GamesPage accept invitation → ParticipantJoined + mark-read', function () {
    it('dispatches ParticipantJoined to game owner when accepting invitation from GamesPage', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        \Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors();

        $notifications = $owner->notifications()->where('type', ParticipantJoined::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('participant_joined')
            ->and($data['participant_id'])->toBe($invitedUser->id)
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id);
    });

    it('marks GameInvitation notification as read when accepting from GamesPage', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        // Create an unread GameInvitation notification
        $invitedUser->notifyNow(new GameInvitation($game, $owner));
        expect($invitedUser->unreadNotifications()->where('type', GameInvitation::class)->count())->toBe(1);

        \Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors();

        expect($invitedUser->unreadNotifications()->where('type', GameInvitation::class)->count())->toBe(0);
    });
});

// ── CampaignsPage::acceptInvitation() ──────────────────

describe('CampaignsPage accept invitation → ParticipantJoined + mark-read', function () {
    it('dispatches ParticipantJoined to campaign owner when accepting invitation from CampaignsPage', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        \Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors();

        $notifications = $owner->notifications()->where('type', ParticipantJoined::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('participant_joined')
            ->and($data['participant_id'])->toBe($invitedUser->id)
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id);
    });

    it('marks CampaignInvitation notification as read when accepting from CampaignsPage', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        // Create an unread CampaignInvitation notification
        $invitedUser->notifyNow(new CampaignInvitation($campaign, $owner));
        expect($invitedUser->unreadNotifications()->where('type', CampaignInvitation::class)->count())->toBe(1);

        \Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors();

        expect($invitedUser->unreadNotifications()->where('type', CampaignInvitation::class)->count())->toBe(0);
    });
});

// ── ManageRoster::removeMember() ───────────────────────

describe('Team member removal → TeamMemberRemoved', function () {
    it('dispatches TeamMemberRemoved to removed member', function () {
        $captain = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['created_by' => $captain->id]);

        // Create captain membership
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $captain->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Create player membership
        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        \Livewire\Livewire::actingAs($captain)
            ->test(\App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('removeMember', $member->id)
            ->assertHasNoErrors();

        $notifications = $player->notifications()->where('type', TeamMemberRemoved::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('team_member_removed')
            ->and($data['entity_id'])->toBe($team->id)
            ->and($data['entity_name'])->toBe($team->name)
            ->and($data['remover_id'])->toBe($captain->id);
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
