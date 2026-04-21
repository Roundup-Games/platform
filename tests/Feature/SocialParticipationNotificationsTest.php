<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\NewFollower;
use App\Notifications\ParticipantJoined;
use App\Notifications\ParticipantRemoved;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

describe('NewFollower Notification', function () {
    it('stores correct data to database', function () {
        $follower = User::factory()->create(['name' => 'Jane Doe']);
        $notifiable = User::factory()->create();

        $notification = new NewFollower($follower);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('new_follower')
            ->and($data['follower_id'])->toBe($follower->id)
            ->and($data['follower_name'])->toBe('Jane Doe')
            ->and($data['action_url'])->toContain('/u/');
    });

    it('renders correct email content with entity link', function () {
        $follower = User::factory()->create(['name' => 'Jane Doe']);
        $notifiable = User::factory()->create(['name' => 'Bob']);

        $notification = new NewFollower($follower);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Jane Doe started following you')
            ->and($mail->actionUrl)->toContain('/u/')
            ->and($mail->actionText)->toBe('View Profile');
    });

    it('returns follower as actor for block-list checking', function () {
        $follower = User::factory()->create();
        $notification = new NewFollower($follower);

        expect($notification->getActor())->toBe($follower);
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $notification = new NewFollower(User::factory()->create());

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});

describe('ParticipantJoined Notification', function () {
    it('stores correct data to database for a game', function () {
        $participant = User::factory()->create(['name' => 'Alice']);
        $game = Game::factory()->create(['name' => 'Epic Quest']);
        $notifiable = User::factory()->create();

        $notification = new ParticipantJoined($participant, $game, 'game');
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('participant_joined')
            ->and($data['participant_id'])->toBe($participant->id)
            ->and($data['participant_name'])->toBe('Alice')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_name'])->toBe('Epic Quest')
            ->and($data['action_url'])->toContain('/games/');
    });

    it('stores correct data to database for a campaign', function () {
        $participant = User::factory()->create(['name' => 'Alice']);
        $campaign = Campaign::factory()->create(['name' => 'Long Campaign']);
        $notifiable = User::factory()->create();

        $notification = new ParticipantJoined($participant, $campaign, 'campaign');
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('participant_joined')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_name'])->toBe('Long Campaign')
            ->and($data['action_url'])->toContain('/campaigns/');
    });

    it('renders correct email content for a game', function () {
        $participant = User::factory()->create(['name' => 'Alice']);
        $game = Game::factory()->create(['name' => 'Epic Quest']);
        $notifiable = User::factory()->create(['name' => 'Bob']);

        $notification = new ParticipantJoined($participant, $game, 'game');
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toContain('Alice')
            ->and($mail->subject)->toContain('Epic Quest')
            ->and($mail->actionUrl)->toContain('/games/')
            ->and($mail->actionText)->toBe('View game');
    });

    it('renders correct email content for a campaign', function () {
        $participant = User::factory()->create(['name' => 'Alice']);
        $campaign = Campaign::factory()->create(['name' => 'Long Campaign']);
        $notifiable = User::factory()->create(['name' => 'Bob']);

        $notification = new ParticipantJoined($participant, $campaign, 'campaign');
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toContain('Alice')
            ->and($mail->actionUrl)->toContain('/campaigns/');
    });

    it('returns participant as actor for block-list checking', function () {
        $participant = User::factory()->create();
        $game = Game::factory()->create();
        $notification = new ParticipantJoined($participant, $game, 'game');

        expect($notification->getActor())->toBe($participant);
    });
});

describe('ParticipantRemoved Notification', function () {
    it('stores correct data to database for a game', function () {
        $removedUser = User::factory()->create(['name' => 'Charlie']);
        $game = Game::factory()->create(['name' => 'Board Game Night']);
        $notifiable = User::factory()->create();

        $notification = new ParticipantRemoved($removedUser, $game, 'game');
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('participant_removed')
            ->and($data['removed_user_id'])->toBe($removedUser->id)
            ->and($data['removed_user_name'])->toBe('Charlie')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_name'])->toBe('Board Game Night')
            ->and($data['action_url'])->toContain('/games');
    });

    it('stores correct data to database for a campaign', function () {
        $removedUser = User::factory()->create(['name' => 'Charlie']);
        $campaign = Campaign::factory()->create(['name' => 'D&D Campaign']);
        $notifiable = User::factory()->create();

        $notification = new ParticipantRemoved($removedUser, $campaign, 'campaign');
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('participant_removed')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_name'])->toBe('D&D Campaign');
    });

    it('renders correct email content with unsubscribe URL', function () {
        $removedUser = User::factory()->create(['name' => 'Charlie']);
        $game = Game::factory()->create(['name' => 'Board Game Night']);
        $notifiable = User::factory()->create(['name' => 'Bob']);

        $notification = new ParticipantRemoved($removedUser, $game, 'game');
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toContain('Board Game Night')
            ->and($mail->actionText)->toBe('Browse Games');
    });

    it('returns null actor since recipient is the removed user', function () {
        $removedUser = User::factory()->create();
        $game = Game::factory()->create();
        $notification = new ParticipantRemoved($removedUser, $game, 'game');

        expect($notification->getActor())->toBeNull();
    });
});
