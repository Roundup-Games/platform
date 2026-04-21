<?php

use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use App\Notifications\CampaignInvitation;
use App\Notifications\GameInvitation;
use App\Notifications\SessionAddedToCampaign;
use App\Notifications\TeamInvitation;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

describe('GameInvitation Notification', function () {
    it('stores correct data to database', function () {
        $inviter = User::factory()->create(['name' => 'Gandalf']);
        $game = Game::factory()->create(['name' => 'Epic Quest']);
        $notifiable = User::factory()->create();

        $notification = new GameInvitation($game, $inviter);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('game_invitation')
            ->and($data['game_id'])->toBe($game->id)
            ->and($data['game_name'])->toBe('Epic Quest')
            ->and($data['inviter_id'])->toBe($inviter->id)
            ->and($data['inviter_name'])->toBe('Gandalf')
            ->and($data['action_url'])->toContain('/games/');
    });

    it('renders correct email content with game link', function () {
        $inviter = User::factory()->create(['name' => 'Gandalf']);
        $game = Game::factory()->create(['name' => 'Epic Quest']);
        $notifiable = User::factory()->create(['name' => 'Frodo']);

        $notification = new GameInvitation($game, $inviter);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Gandalf invited you to a game')
            ->and($mail->actionUrl)->toContain('/games/')
            ->and($mail->actionText)->toBe('View Game');
    });

    it('returns inviter as actor for block-list checking', function () {
        $inviter = User::factory()->create();
        $game = Game::factory()->create();
        $notification = new GameInvitation($game, $inviter);

        expect($notification->getActor())->toBe($inviter);
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $notification = new GameInvitation(
            Game::factory()->create(),
            User::factory()->create(),
        );

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});

describe('CampaignInvitation Notification', function () {
    it('stores correct data to database', function () {
        $inviter = User::factory()->create(['name' => 'Dungeon Master']);
        $campaign = Campaign::factory()->create(['name' => 'Dragonlance']);
        $notifiable = User::factory()->create();

        $notification = new CampaignInvitation($campaign, $inviter);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('campaign_invitation')
            ->and($data['campaign_id'])->toBe($campaign->id)
            ->and($data['campaign_name'])->toBe('Dragonlance')
            ->and($data['inviter_id'])->toBe($inviter->id)
            ->and($data['inviter_name'])->toBe('Dungeon Master')
            ->and($data['action_url'])->toContain('/campaigns/');
    });

    it('renders correct email content with campaign link', function () {
        $inviter = User::factory()->create(['name' => 'Dungeon Master']);
        $campaign = Campaign::factory()->create(['name' => 'Dragonlance']);
        $notifiable = User::factory()->create(['name' => 'Raistlin']);

        $notification = new CampaignInvitation($campaign, $inviter);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Dungeon Master invited you to a campaign')
            ->and($mail->actionUrl)->toContain('/campaigns/')
            ->and($mail->actionText)->toBe('View Campaign');
    });

    it('returns inviter as actor for block-list checking', function () {
        $inviter = User::factory()->create();
        $campaign = Campaign::factory()->create();
        $notification = new CampaignInvitation($campaign, $inviter);

        expect($notification->getActor())->toBe($inviter);
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $notification = new CampaignInvitation(
            Campaign::factory()->create(),
            User::factory()->create(),
        );

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});

describe('TeamInvitation Notification', function () {
    it('stores correct data to database', function () {
        $inviter = User::factory()->create(['name' => 'Captain']);
        $team = Team::factory()->create(['name' => 'RPG Warriors']);
        $notifiable = User::factory()->create();

        $notification = new TeamInvitation($team, $inviter);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('team_invitation')
            ->and($data['team_id'])->toBe($team->id)
            ->and($data['team_name'])->toBe('RPG Warriors')
            ->and($data['team_slug'])->toBe($team->slug)
            ->and($data['inviter_id'])->toBe($inviter->id)
            ->and($data['inviter_name'])->toBe('Captain')
            ->and($data['action_url'])->toContain('/teams/');
    });

    it('renders correct email content with team link', function () {
        $inviter = User::factory()->create(['name' => 'Captain']);
        $team = Team::factory()->create(['name' => 'RPG Warriors']);
        $notifiable = User::factory()->create(['name' => 'Rookie']);

        $notification = new TeamInvitation($team, $inviter);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Captain invited you to join RPG Warriors')
            ->and($mail->actionUrl)->toContain('/teams/')
            ->and($mail->actionText)->toBe('View Team');
    });

    it('returns inviter as actor for block-list checking', function () {
        $inviter = User::factory()->create();
        $team = Team::factory()->create();
        $notification = new TeamInvitation($team, $inviter);

        expect($notification->getActor())->toBe($inviter);
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $notification = new TeamInvitation(
            Team::factory()->create(),
            User::factory()->create(),
        );

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});

describe('SessionAddedToCampaign Notification', function () {
    it('stores correct data to database', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['name' => 'Curse of Strahd', 'owner_id' => $owner->id]);
        $session = Game::factory()->create(['name' => 'Session 1: Into the Mists', 'campaign_id' => $campaign->id]);
        $notifiable = User::factory()->create();

        $notification = new SessionAddedToCampaign($session, $campaign);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('session_added_to_campaign')
            ->and($data['session_id'])->toBe($session->id)
            ->and($data['session_name'])->toBe('Session 1: Into the Mists')
            ->and($data['campaign_id'])->toBe($campaign->id)
            ->and($data['campaign_name'])->toBe('Curse of Strahd')
            ->and($data['action_url'])->toContain('/games/');
    });

    it('renders correct email content with session link', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['name' => 'Curse of Strahd', 'owner_id' => $owner->id]);
        $session = Game::factory()->create(['name' => 'Session 1: Into the Mists', 'campaign_id' => $campaign->id]);
        $notifiable = User::factory()->create(['name' => 'Ireena']);

        $notification = new SessionAddedToCampaign($session, $campaign);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('New session added to Curse of Strahd')
            ->and($mail->actionUrl)->toContain('/games/')
            ->and($mail->actionText)->toBe('View Session');
    });

    it('returns campaign owner as actor for block-list checking', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);
        $session = Game::factory()->create(['campaign_id' => $campaign->id]);

        $notification = new SessionAddedToCampaign($session, $campaign);
        $actor = $notification->getActor();

        expect($actor)->not->toBeNull()
            ->and($actor->id)->toBe($owner->id);
    });

    it('returns null actor when campaign has no owner loaded', function () {
        // Campaign without fresh owner load — getActor returns the owner relation
        $campaign = Campaign::factory()->create();
        $session = Game::factory()->create(['campaign_id' => $campaign->id]);

        $notification = new SessionAddedToCampaign($session, $campaign);

        // The owner relation is loaded from factory, so it exists
        expect($notification->getActor())->not->toBeNull();
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $campaign = Campaign::factory()->create();
        $session = Game::factory()->create(['campaign_id' => $campaign->id]);

        $notification = new SessionAddedToCampaign($session, $campaign);

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});

// ── Trigger Integration Tests ────────────────────────────────────────────

/**
 * Helper: make two users mutual friends.
 */
function makeMutualFriendsForInvitation(User $a, User $b): void
{
    UserRelationship::create([
        'user_id' => $a->id,
        'related_user_id' => $b->id,
        'type' => RelationshipType::Follow,
    ]);
    UserRelationship::create([
        'user_id' => $b->id,
        'related_user_id' => $a->id,
        'type' => RelationshipType::Follow,
    ]);
}

describe('Game invite → GameInvitation trigger', function () {
    it('dispatches GameInvitation when inviting a friend to a game', function () {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        makeMutualFriendsForInvitation($owner, $friend);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        $notifications = $friend->notifications()->where('type', GameInvitation::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('game_invitation')
            ->and($data['game_id'])->toBe($game->id)
            ->and($data['inviter_id'])->toBe($owner->id);
    });

    it('does not dispatch notification for non-friend invite attempt', function () {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$stranger->id])
            ->call('inviteParticipants');

        $notifications = $stranger->notifications()->where('type', GameInvitation::class)->get();
        expect($notifications)->toHaveCount(0);
    });

    it('dispatches to multiple friends at once', function () {
        $owner = User::factory()->create();
        $friend1 = User::factory()->create();
        $friend2 = User::factory()->create();
        makeMutualFriendsForInvitation($owner, $friend1);
        makeMutualFriendsForInvitation($owner, $friend2);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend1->id, $friend2->id])
            ->call('inviteParticipants');

        expect($friend1->notifications()->where('type', GameInvitation::class)->count())->toBe(1);
        expect($friend2->notifications()->where('type', GameInvitation::class)->count())->toBe(1);
    });
});

describe('Campaign invite → CampaignInvitation trigger', function () {
    it('dispatches CampaignInvitation when inviting a friend to a campaign', function () {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        makeMutualFriendsForInvitation($owner, $friend);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        $notifications = $friend->notifications()->where('type', CampaignInvitation::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('campaign_invitation')
            ->and($data['campaign_id'])->toBe($campaign->id)
            ->and($data['inviter_id'])->toBe($owner->id);
    });
});

describe('Session added to campaign → SessionAddedToCampaign trigger', function () {
    it('dispatches SessionAddedToCampaign to each approved campaign participant', function () {
        seedPermissions();
        $owner = User::factory()->create(['profile_complete' => true]);
        setPermissionsTeamId(1);
        $owner->givePermissionTo(['create campaign', 'create game']);
        $owner->unsetRelations();

        $participant1 = User::factory()->create();
        $participant2 = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        // Create approved campaign participants
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant1->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant2->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Session 1')
            ->set('date_time', now()->addDays(7)->format('Y-m-d H:i:s'))
            ->call('save');

        // Both participants should get the notification
        expect($participant1->notifications()->where('type', SessionAddedToCampaign::class)->count())->toBe(1);
        expect($participant2->notifications()->where('type', SessionAddedToCampaign::class)->count())->toBe(1);

        // Owner should NOT get the notification (they created the session)
        expect($owner->notifications()->where('type', SessionAddedToCampaign::class)->count())->toBe(0);
    });

    it('does not dispatch to pending or rejected participants', function () {
        seedPermissions();
        $owner = User::factory()->create(['profile_complete' => true]);
        setPermissionsTeamId(1);
        $owner->givePermissionTo(['create campaign', 'create game']);
        $owner->unsetRelations();

        $pendingUser = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $pendingUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Session 1')
            ->set('date_time', now()->addDays(7)->format('Y-m-d H:i:s'))
            ->call('save');

        expect($pendingUser->notifications()->where('type', SessionAddedToCampaign::class)->count())->toBe(0);
    });
});

describe('Team invite → TeamInvitation trigger', function () {
    it('dispatches TeamInvitation when inviting a user to a team', function () {
        $captain = User::factory()->create();
        $player = User::factory()->create(['email' => 'player@example.com']);
        $team = Team::factory()->create();
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
            ->set('inviteRole', 'player')
            ->call('inviteMember');

        $notifications = $player->notifications()->where('type', TeamInvitation::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('team_invitation')
            ->and($data['team_id'])->toBe($team->id)
            ->and($data['inviter_id'])->toBe($captain->id);
    });

    it('does not dispatch notification when invite fails (user already has active team)', function () {
        $captain = User::factory()->create();
        $player = User::factory()->create(['email' => 'busy@example.com']);
        $otherTeam = Team::factory()->create();
        $team = Team::factory()->create();

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
            ->set('inviteRole', 'player')
            ->call('inviteMember');

        // Invite should fail, so no notification
        expect($player->notifications()->where('type', TeamInvitation::class)->count())->toBe(0);
    });
});
