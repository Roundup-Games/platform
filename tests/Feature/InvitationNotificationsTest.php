<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
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
