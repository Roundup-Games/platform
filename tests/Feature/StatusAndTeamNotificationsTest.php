<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use App\Notifications\CampaignCancelled;
use App\Notifications\CampaignCompleted;
use App\Notifications\GameCancelled;
use App\Notifications\GameCompleted;
use App\Notifications\TeamMemberRemoved;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

describe('GameCancelled Notification', function () {
    it('stores correct data to database', function () {
        $game = Game::factory()->create(['name' => 'Cancelled Quest']);
        $notifiable = User::factory()->create();

        $notification = new GameCancelled($game);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('game_cancelled')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_name'])->toBe('Cancelled Quest')
            ->and($data['date_time'])->not->toBeNull()
            ->and($data['action_url'])->toContain('/games');
    });

    it('renders correct email content', function () {
        $game = Game::factory()->create(['name' => 'Cancelled Quest']);
        $notifiable = User::factory()->create(['name' => 'Frodo']);

        $notification = new GameCancelled($game);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Cancelled Quest has been cancelled')
            ->and($mail->actionUrl)->toContain('/games')
            ->and($mail->actionText)->toBe('Browse Games');
    });

    it('returns game owner as actor for block-list checking', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);
        $notification = new GameCancelled($game);

        expect($notification->getActor()->id)->toBe($owner->id);
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $notification = new GameCancelled(Game::factory()->create());

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});

describe('GameCompleted Notification', function () {
    it('stores correct data to database', function () {
        $game = Game::factory()->create(['name' => 'Finished Quest']);
        $notifiable = User::factory()->create();

        $notification = new GameCompleted($game);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('game_completed')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_name'])->toBe('Finished Quest')
            ->and($data['action_url'])->toContain('/games/');
    });

    it('renders correct email content', function () {
        $game = Game::factory()->create(['name' => 'Finished Quest']);
        $notifiable = User::factory()->create(['name' => 'Sam']);

        $notification = new GameCompleted($game);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Finished Quest has been completed')
            ->and($mail->actionUrl)->toContain('/games/')
            ->and($mail->actionText)->toBe('View Game');
    });

    it('returns game owner as actor for block-list checking', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);
        $notification = new GameCompleted($game);

        expect($notification->getActor()->id)->toBe($owner->id);
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $notification = new GameCompleted(Game::factory()->create());

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});

describe('CampaignCancelled Notification', function () {
    it('stores correct data to database', function () {
        $campaign = Campaign::factory()->create(['name' => 'Cancelled Campaign']);
        $notifiable = User::factory()->create();

        $notification = new CampaignCancelled($campaign);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('campaign_cancelled')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_name'])->toBe('Cancelled Campaign')
            ->and($data['action_url'])->toContain('/campaigns');
    });

    it('renders correct email content', function () {
        $campaign = Campaign::factory()->create(['name' => 'Cancelled Campaign']);
        $notifiable = User::factory()->create(['name' => 'Boromir']);

        $notification = new CampaignCancelled($campaign);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Cancelled Campaign has been cancelled')
            ->and($mail->actionUrl)->toContain('/campaigns')
            ->and($mail->actionText)->toBe('Browse Campaigns');
    });

    it('returns campaign owner as actor for block-list checking', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);
        $notification = new CampaignCancelled($campaign);

        expect($notification->getActor()->id)->toBe($owner->id);
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $notification = new CampaignCancelled(Campaign::factory()->create());

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});

describe('CampaignCompleted Notification', function () {
    it('stores correct data to database', function () {
        $campaign = Campaign::factory()->create(['name' => 'Finished Campaign']);
        $notifiable = User::factory()->create();

        $notification = new CampaignCompleted($campaign);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('campaign_completed')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_name'])->toBe('Finished Campaign')
            ->and($data['action_url'])->toContain('/campaigns/');
    });

    it('renders correct email content', function () {
        $campaign = Campaign::factory()->create(['name' => 'Finished Campaign']);
        $notifiable = User::factory()->create(['name' => 'Gimli']);

        $notification = new CampaignCompleted($campaign);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Finished Campaign has been completed')
            ->and($mail->actionUrl)->toContain('/campaigns/')
            ->and($mail->actionText)->toBe('View Campaign');
    });

    it('returns campaign owner as actor for block-list checking', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);
        $notification = new CampaignCompleted($campaign);

        expect($notification->getActor()->id)->toBe($owner->id);
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $notification = new CampaignCompleted(Campaign::factory()->create());

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});

describe('TeamMemberRemoved Notification', function () {
    it('stores correct data to database', function () {
        $team = Team::factory()->create(['name' => 'Rangers']);
        $remover = User::factory()->create(['name' => 'Aragorn']);
        $notifiable = User::factory()->create();

        $notification = new TeamMemberRemoved($team, $remover);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('team_member_removed')
            ->and($data['entity_type'])->toBe('team')
            ->and($data['entity_id'])->toBe($team->id)
            ->and($data['entity_name'])->toBe('Rangers')
            ->and($data['remover_id'])->toBe($remover->id)
            ->and($data['remover_name'])->toBe('Aragorn')
            ->and($data['action_url'])->toContain('/teams');
    });

    it('renders correct email content', function () {
        $team = Team::factory()->create(['name' => 'Rangers']);
        $remover = User::factory()->create(['name' => 'Aragorn']);
        $notifiable = User::factory()->create(['name' => 'Legolas']);

        $notification = new TeamMemberRemoved($team, $remover);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('You were removed from Rangers')
            ->and($mail->actionUrl)->toContain('/teams')
            ->and($mail->actionText)->toBe('Browse Teams');
    });

    it('returns remover as actor for block-list checking', function () {
        $remover = User::factory()->create();
        $team = Team::factory()->create();
        $notification = new TeamMemberRemoved($team, $remover);

        expect($notification->getActor())->toBe($remover);
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $notification = new TeamMemberRemoved(
            Team::factory()->create(),
            User::factory()->create(),
        );

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});
