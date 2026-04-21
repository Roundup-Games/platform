<?php

use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\CampaignApplication;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserRelationship;
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

// ── Trigger integration tests ─────────────────────────

describe('Protected game apply → NewApplication trigger', function () {
    it('dispatches NewApplication to game owner when applying to a protected game', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'protected',
        ]);

        // Protected games require friend/teammate relationship for view access
        UserRelationship::create(['user_id' => $owner->id, 'related_user_id' => $applicant->id, 'type' => RelationshipType::Follow]);
        UserRelationship::create(['user_id' => $applicant->id, 'related_user_id' => $owner->id, 'type' => RelationshipType::Follow]);

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'I would love to join!')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $notifications = $owner->notifications()->where('type', NewApplication::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('new_application')
            ->and($data['applicant_id'])->toBe($applicant->id)
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id);
    });

    it('does not dispatch notification for public game (auto-approved)', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->call('submitApplication')
            ->assertHasNoErrors();

        $notifications = $owner->notifications()->where('type', NewApplication::class)->get();
        expect($notifications)->toHaveCount(0);
    });
});

describe('Protected campaign apply → NewApplication trigger', function () {
    it('dispatches NewApplication to campaign owner when applying to a protected campaign', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'protected',
        ]);

        // Protected campaigns require friend/teammate relationship for view access
        UserRelationship::create(['user_id' => $owner->id, 'related_user_id' => $applicant->id, 'type' => RelationshipType::Follow]);
        UserRelationship::create(['user_id' => $applicant->id, 'related_user_id' => $owner->id, 'type' => RelationshipType::Follow]);

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id])
            ->set('message', 'Please let me in!')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $notifications = $owner->notifications()->where('type', NewApplication::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('new_application')
            ->and($data['applicant_id'])->toBe($applicant->id)
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id);
    });

    it('does not dispatch notification for public campaign (auto-approved)', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id])
            ->call('submitApplication')
            ->assertHasNoErrors();

        $notifications = $owner->notifications()->where('type', NewApplication::class)->get();
        expect($notifications)->toHaveCount(0);
    });
});

describe('Approve application → ApplicationApproved trigger', function () {
    it('dispatches ApplicationApproved to applicant when game owner approves', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'protected']);

        // Create the application + pending participant manually
        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'status' => 'pending',
            'message' => null,
        ]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('approveApplication', $participant->id)
            ->assertHasNoErrors();

        $notifications = $applicant->notifications()->where('type', ApplicationApproved::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('application_approved')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['approver_id'])->toBe($owner->id);
    });

    it('dispatches ApplicationApproved to applicant when campaign owner approves', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id, 'visibility' => 'protected']);

        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => 'pending',
            'message' => null,
        ]);
        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('approveApplication', $participant->id)
            ->assertHasNoErrors();

        $notifications = $applicant->notifications()->where('type', ApplicationApproved::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('application_approved')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['approver_id'])->toBe($owner->id);
    });
});

describe('Reject application → ApplicationRejected trigger', function () {
    it('dispatches ApplicationRejected to applicant when game owner rejects', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'protected']);

        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'status' => 'pending',
            'message' => null,
        ]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->call('rejectApplication', $participant->id)
            ->assertHasNoErrors();

        $notifications = $applicant->notifications()->where('type', ApplicationRejected::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('application_rejected')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['rejector_id'])->toBe($owner->id);
    });

    it('dispatches ApplicationRejected to applicant when campaign owner rejects', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $applicant = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id, 'visibility' => 'protected']);

        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => 'pending',
            'message' => null,
        ]);
        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('rejectApplication', $participant->id)
            ->assertHasNoErrors();

        $notifications = $applicant->notifications()->where('type', ApplicationRejected::class)->get();
        expect($notifications)->toHaveCount(1);
        $data = $notifications->first()->data;
        expect($data['type'])->toBe('application_rejected')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['rejector_id'])->toBe($owner->id);
    });

    it('does not dispatch notification for non-applicant participant', function () {
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
            ->call('rejectApplication', $participant->id);

        // rejectApplication returns early if role !== 'applicant', so no notification
        $notifications = $player->notifications()->where('type', ApplicationRejected::class)->get();
        expect($notifications)->toHaveCount(0);
    });
});
