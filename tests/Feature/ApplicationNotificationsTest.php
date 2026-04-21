<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\ApplicationApproved;
use App\Notifications\ApplicationRejected;
use App\Notifications\NewApplication;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

describe('NewApplication Notification', function () {
    it('stores correct data to database for game entity', function () {
        $applicant = User::factory()->create(['name' => 'Merry']);
        $game = Game::factory()->create(['name' => 'Hobbit Adventure']);
        $notifiable = User::factory()->create();

        $notification = new NewApplication($applicant, $game, 'game');
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('new_application')
            ->and($data['applicant_id'])->toBe($applicant->id)
            ->and($data['applicant_name'])->toBe('Merry')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_name'])->toBe('Hobbit Adventure')
            ->and($data['action_url'])->toContain('/games/', '/manage-participants');
    });

    it('stores correct data to database for campaign entity', function () {
        $applicant = User::factory()->create(['name' => 'Pippin']);
        $campaign = Campaign::factory()->create(['name' => 'Fellowship']);
        $notifiable = User::factory()->create();

        $notification = new NewApplication($applicant, $campaign, 'campaign');
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('new_application')
            ->and($data['applicant_id'])->toBe($applicant->id)
            ->and($data['applicant_name'])->toBe('Pippin')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_name'])->toBe('Fellowship')
            ->and($data['action_url'])->toContain('/campaigns/', '/manage-participants');
    });

    it('renders correct email content with manage-participants link for game', function () {
        $applicant = User::factory()->create(['name' => 'Merry']);
        $game = Game::factory()->create(['name' => 'Hobbit Adventure']);
        $notifiable = User::factory()->create(['name' => 'Bilbo']);

        $notification = new NewApplication($applicant, $game, 'game');
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Merry applied to join your Hobbit Adventure')
            ->and($mail->actionUrl)->toContain('/games/')
            ->and($mail->actionUrl)->toContain('/manage-participants')
            ->and($mail->actionText)->toBe('Review Application');
    });

    it('renders correct email content with manage-participants link for campaign', function () {
        $applicant = User::factory()->create(['name' => 'Pippin']);
        $campaign = Campaign::factory()->create(['name' => 'Fellowship']);
        $notifiable = User::factory()->create(['name' => 'Aragorn']);

        $notification = new NewApplication($applicant, $campaign, 'campaign');
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Pippin applied to join your Fellowship')
            ->and($mail->actionUrl)->toContain('/campaigns/')
            ->and($mail->actionUrl)->toContain('/manage-participants')
            ->and($mail->actionText)->toBe('Review Application');
    });

    it('returns applicant as actor for block-list checking', function () {
        $applicant = User::factory()->create();
        $game = Game::factory()->create();
        $notification = new NewApplication($applicant, $game, 'game');

        expect($notification->getActor())->toBe($applicant);
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $notification = new NewApplication(
            User::factory()->create(),
            Game::factory()->create(),
            'game',
        );

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});

describe('ApplicationApproved Notification', function () {
    it('stores correct data to database for game entity', function () {
        $approver = User::factory()->create(['name' => 'Gandalf']);
        $game = Game::factory()->create(['name' => 'Epic Quest']);
        $notifiable = User::factory()->create();

        $notification = new ApplicationApproved($game, 'game', $approver);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('application_approved')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_name'])->toBe('Epic Quest')
            ->and($data['approver_id'])->toBe($approver->id)
            ->and($data['approver_name'])->toBe('Gandalf')
            ->and($data['action_url'])->toContain('/games/');
    });

    it('stores correct data to database for campaign entity', function () {
        $approver = User::factory()->create(['name' => 'Elrond']);
        $campaign = Campaign::factory()->create(['name' => 'Council of Elrond']);
        $notifiable = User::factory()->create();

        $notification = new ApplicationApproved($campaign, 'campaign', $approver);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('application_approved')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_name'])->toBe('Council of Elrond')
            ->and($data['approver_id'])->toBe($approver->id)
            ->and($data['approver_name'])->toBe('Elrond')
            ->and($data['action_url'])->toContain('/campaigns/');
    });

    it('renders correct email content for game approval', function () {
        $approver = User::factory()->create(['name' => 'Gandalf']);
        $game = Game::factory()->create(['name' => 'Epic Quest']);
        $notifiable = User::factory()->create(['name' => 'Frodo']);

        $notification = new ApplicationApproved($game, 'game', $approver);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Your application to Epic Quest was approved')
            ->and($mail->actionUrl)->toContain('/games/')
            ->and($mail->actionText)->toBe('View Game');
    });

    it('renders correct email content for campaign approval', function () {
        $approver = User::factory()->create(['name' => 'Elrond']);
        $campaign = Campaign::factory()->create(['name' => 'Council of Elrond']);
        $notifiable = User::factory()->create(['name' => 'Frodo']);

        $notification = new ApplicationApproved($campaign, 'campaign', $approver);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Your application to Council of Elrond was approved')
            ->and($mail->actionUrl)->toContain('/campaigns/')
            ->and($mail->actionText)->toBe('View Campaign');
    });

    it('returns approver as actor for block-list checking', function () {
        $approver = User::factory()->create();
        $game = Game::factory()->create();
        $notification = new ApplicationApproved($game, 'game', $approver);

        expect($notification->getActor())->toBe($approver);
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $notification = new ApplicationApproved(
            Game::factory()->create(),
            'game',
            User::factory()->create(),
        );

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});

describe('ApplicationRejected Notification', function () {
    it('stores correct data to database for game entity', function () {
        $rejector = User::factory()->create(['name' => 'Sauron']);
        $game = Game::factory()->create(['name' => 'Mount Doom']);
        $notifiable = User::factory()->create();

        $notification = new ApplicationRejected($game, 'game', $rejector);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('application_rejected')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_name'])->toBe('Mount Doom')
            ->and($data['rejector_id'])->toBe($rejector->id)
            ->and($data['rejector_name'])->toBe('Sauron')
            ->and($data['action_url'])->toContain('/games');
    });

    it('stores correct data to database for campaign entity', function () {
        $rejector = User::factory()->create(['name' => 'Saruman']);
        $campaign = Campaign::factory()->create(['name' => 'Isengard Alliance']);
        $notifiable = User::factory()->create();

        $notification = new ApplicationRejected($campaign, 'campaign', $rejector);
        $data = $notification->toDatabase($notifiable);

        expect($data)->toBeArray()
            ->and($data['type'])->toBe('application_rejected')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_name'])->toBe('Isengard Alliance')
            ->and($data['rejector_id'])->toBe($rejector->id)
            ->and($data['rejector_name'])->toBe('Saruman')
            ->and($data['action_url'])->toContain('/games');
    });

    it('renders correct email content for game rejection', function () {
        $rejector = User::factory()->create(['name' => 'Sauron']);
        $game = Game::factory()->create(['name' => 'Mount Doom']);
        $notifiable = User::factory()->create(['name' => 'Frodo']);

        $notification = new ApplicationRejected($game, 'game', $rejector);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Your application to Mount Doom was not accepted')
            ->and($mail->actionUrl)->toContain('/games')
            ->and($mail->actionText)->toBe('Browse Games');
    });

    it('renders correct email content for campaign rejection', function () {
        $rejector = User::factory()->create(['name' => 'Saruman']);
        $campaign = Campaign::factory()->create(['name' => 'Isengard Alliance']);
        $notifiable = User::factory()->create(['name' => 'Grima']);

        $notification = new ApplicationRejected($campaign, 'campaign', $rejector);
        $mail = $notification->toMail($notifiable);

        expect($mail->subject)->toBe('Your application to Isengard Alliance was not accepted')
            ->and($mail->actionUrl)->toContain('/games')
            ->and($mail->actionText)->toBe('Browse Games');
    });

    it('returns rejector as actor for block-list checking', function () {
        $rejector = User::factory()->create();
        $game = Game::factory()->create();
        $notification = new ApplicationRejected($game, 'game', $rejector);

        expect($notification->getActor())->toBe($rejector);
    });

    it('resolves via channels to database and mail', function () {
        $notifiable = User::factory()->create();
        $notification = new ApplicationRejected(
            Game::factory()->create(),
            'game',
            User::factory()->create(),
        );

        expect($notification->via($notifiable))->toContain(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \Illuminate\Notifications\Channels\MailChannel::class,
        );
    });
});
