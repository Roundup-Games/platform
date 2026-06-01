<?php

use App\Enums\NotificationCategory;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use App\Notifications\EntityInvitation;
use App\Notifications\SessionAddedToCampaign;
use App\Notifications\TeamInvitation;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

/**
 * Helper: set up mutual friendship between two users.
 */
function makeFriendsInvitation(User $a, User $b): void
{
    UserRelationship::follow($a, $b);
    UserRelationship::follow($b, $a);
}

// ══════════════════════════════════════════════════════
// Game Invitation — Trigger Tests
// ══════════════════════════════════════════════════════

describe('Game Invitation → GameInvitation', function () {
    it('dispatches GameInvitation when inviting a friend to a game', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $friend = User::factory()->create(['profile_complete' => true]);
        makeFriendsInvitation($owner, $friend);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'visibility' => 'public',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        $notifications = $friend->notifications()->where('type', EntityInvitation::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('game_invitation')
            ->and($data['game_id'])->toBe($game->id)
            ->and($data['game_name'])->toBe($game->name)
            ->and($data['inviter_id'])->toBe($owner->id)
            ->and($data)->toHaveKey('action_url');
    });

    it('does not dispatch when target has blocked the inviter', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $friend = User::factory()->create(['profile_complete' => true]);
        makeFriendsInvitation($owner, $friend);

        // Friend blocks owner after becoming friends
        UserRelationship::block($friend, $owner);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        // No longer friends (block removed follow), so invite is skipped entirely
        expect($friend->notifications()->where('type', EntityInvitation::class)->count())->toBe(0);
    });

    it('does not dispatch when preferences are off', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $friend = User::factory()->create([
            'profile_complete' => true,
            'notification_settings' => array_merge(
                NotificationCategory::defaultSettings(),
                ['game_invitation' => ['database' => false, 'mail' => false]]
            ),
        ]);
        makeFriendsInvitation($owner, $friend);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        expect($friend->notifications()->where('type', EntityInvitation::class)->count())->toBe(0);
    });



    it('does not dispatch notification for non-friend invite attempt', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $stranger = User::factory()->create(['profile_complete' => true]);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$stranger->id])
            ->call('inviteParticipants');

        expect($stranger->notifications()->where('type', EntityInvitation::class)->count())->toBe(0);
    });

    it('dispatches to multiple friends at once', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $friend1 = User::factory()->create(['profile_complete' => true]);
        $friend2 = User::factory()->create(['profile_complete' => true]);
        makeFriendsInvitation($owner, $friend1);
        makeFriendsInvitation($owner, $friend2);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend1->id, $friend2->id])
            ->call('inviteParticipants');

        expect($friend1->notifications()->where('type', EntityInvitation::class)->count())->toBe(1);
        expect($friend2->notifications()->where('type', EntityInvitation::class)->count())->toBe(1);
    });
});

// ══════════════════════════════════════════════════════
// Campaign Invitation
// ══════════════════════════════════════════════════════

describe('Campaign Invitation → CampaignInvitation', function () {
    it('dispatches CampaignInvitation when inviting a friend to a campaign', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $friend = User::factory()->create(['profile_complete' => true]);
        makeFriendsInvitation($owner, $friend);

        $gameSystem = GameSystem::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'visibility' => 'public',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        $notifications = $friend->notifications()->where('type', EntityInvitation::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('campaign_invitation')
            ->and($data['campaign_id'])->toBe($campaign->id)
            ->and($data['inviter_id'])->toBe($owner->id)
            ->and($data)->toHaveKey('action_url');
    });


});

// ══════════════════════════════════════════════════════
// Session Added to Campaign
// ══════════════════════════════════════════════════════

describe('Session Added to Campaign → SessionAddedToCampaign', function () {
    it('dispatches SessionAddedToCampaign to approved campaign participants', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        seedPermissions(); setPermissionsTeamId(1); $owner->givePermissionTo('create game');
        $participant = User::factory()->create(['profile_complete' => true]);

        $gameSystem = GameSystem::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'active',
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Test Session')
            ->set('date_time', now()->addDays(7)->format('Y-m-d H:i:s'))
            ->call('save')
            ->assertHasNoErrors();

        // Verify the game was actually created
        $game = Game::where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($game, 'Session game should have been created');

        $notifications = $participant->fresh()->notifications()->where('type', SessionAddedToCampaign::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('session_added_to_campaign')
            ->and($data['campaign_id'])->toBe($campaign->id)
            ->and($data['campaign_name'])->toBe($campaign->name)
            ->and($data)->toHaveKey('session_id')
            ->and($data)->toHaveKey('action_url');
    });

    it('does not dispatch to campaign owner', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        seedPermissions(); setPermissionsTeamId(1); $owner->givePermissionTo('create game');

        $gameSystem = GameSystem::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'active',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Test Session 2')
            ->set('date_time', now()->addDays(7)->format('Y-m-d H:i:s'))
            ->call('save')
            ->assertHasNoErrors();

        expect($owner->fresh()->notifications()->where('type', SessionAddedToCampaign::class)->count())->toBe(0);
    });



    it('does not dispatch to pending or rejected participants', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        seedPermissions(); setPermissionsTeamId(1); $owner->givePermissionTo('create game');
        $owner->unsetRelations();

        $pendingUser = User::factory()->create(['profile_complete' => true]);

        $gameSystem = GameSystem::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'active',
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $pendingUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Session 1')
            ->set('date_time', now()->addDays(7)->format('Y-m-d H:i:s'))
            ->call('save')
            ->assertHasNoErrors();

        expect($pendingUser->fresh()->notifications()->where('type', SessionAddedToCampaign::class)->count())->toBe(0);
    });
});

// ══════════════════════════════════════════════════════
// Team Invitation
// ══════════════════════════════════════════════════════

describe('Team Invitation → TeamInvitation', function () {
    it('dispatches TeamInvitation when inviting a user to a team', function () {
        $captain = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true, 'email' => 'player@example.com']);

        $team = Team::factory()->create(['created_by' => $captain->id]);

        // Make captain an active member so they can manage
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $captain->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        \Livewire\Livewire::actingAs($captain)
            ->test(\App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'player@example.com')
            ->call('inviteMember');

        $notifications = $player->notifications()->where('type', TeamInvitation::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('team_invitation')
            ->and($data['team_id'])->toBe($team->id)
            ->and($data['team_name'])->toBe($team->name)
            ->and($data['inviter_id'])->toBe($captain->id)
            ->and($data)->toHaveKey('action_url');
    });



    it('does not dispatch notification when user already has active team membership', function () {
        $captain = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true, 'email' => 'busy@example.com']);
        $otherTeam = Team::factory()->create();
        $team = Team::factory()->create(['created_by' => $captain->id]);

        // Player already has an active team membership
        TeamMember::create([
            'team_id' => $otherTeam->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $captain->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        \Livewire\Livewire::actingAs($captain)
            ->test(\App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'busy@example.com')
            ->call('inviteMember');

        // Invite should fail, so no notification
        expect($player->notifications()->where('type', TeamInvitation::class)->count())->toBe(0);
    });
});
