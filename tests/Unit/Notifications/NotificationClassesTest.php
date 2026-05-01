<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApplicationApproved;
use App\Notifications\ApplicationRejected;
use App\Notifications\CampaignCancelled;
use App\Notifications\CampaignCompleted;
use App\Notifications\CampaignInvitation;
use App\Notifications\GameCancelled;
use App\Notifications\GameCompleted;
use App\Notifications\GameInvitation;
use App\Notifications\NewApplication;
use App\Notifications\NewFollower;
use App\Notifications\ParticipantJoined;
use App\Notifications\ParticipantRemoved;
use App\Notifications\SessionAddedToCampaign;
use App\Notifications\TeamInvitation;
use App\Notifications\TeamMemberRemoved;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ---------------------------------------------------------------------------
// NewFollower
// ---------------------------------------------------------------------------
describe('NewFollower', function () {
    it('stores correct data to database', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $notifiable = User::factory()->create();

        $data = (new NewFollower($follower))->toDatabase($notifiable);

        expect($data['type'])->toBe('new_follower')
            ->and($data['follower_id'])->toBe($follower->id)
            ->and($data['follower_name'])->toBe('Alice')
            ->and($data['action_url'])->toContain('/u/');
    });

    it('renders correct email content', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $notifiable = User::factory()->create(['name' => 'Bob']);

        $mail = (new NewFollower($follower))->toMail($notifiable);

        expect($mail->subject)->toContain('Alice')
            ->and($mail->actionUrl)->toContain('/u/')
            ->and($mail->actionText)->toBe('View Profile');
    });

    it('returns follower as actor', function () {
        $follower = User::factory()->create();
        expect((new NewFollower($follower))->getActor())->toBe($follower);
    });

    it('returns correct push payload', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $notifiable = User::factory()->create();

        $payload = (new NewFollower($follower))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(\App\Dto\PushPayload::class)
            ->and($payload->title)->toBe('New Follower')
            ->and($payload->body)->toBe('Alice started following you')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/u/')
            ->and($payload->tag)->toBe("new-follower-{$follower->id}");
    });
});

// ---------------------------------------------------------------------------
// ParticipantJoined
// ---------------------------------------------------------------------------
describe('ParticipantJoined', function () {
    it('stores correct data for a game', function () {
        $participant = User::factory()->create(['name' => 'P1']);
        $game = Game::factory()->create(['name' => 'G1']);
        $notifiable = User::factory()->create();

        $data = (new ParticipantJoined($participant, $game, 'game'))->toDatabase($notifiable);

        expect($data['type'])->toBe('participant_joined')
            ->and($data['participant_id'])->toBe($participant->id)
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_name'])->toBe('G1')
            ->and($data['action_url'])->toContain('/games/');
    });

    it('renders correct email content for a game', function () {
        $participant = User::factory()->create(['name' => 'P1']);
        $game = Game::factory()->create(['name' => 'G1']);
        $notifiable = User::factory()->create(['name' => 'Owner']);

        $mail = (new ParticipantJoined($participant, $game, 'game'))->toMail($notifiable);

        expect($mail->subject)->toContain('P1')
            ->and($mail->actionUrl)->toContain('/games/')
            ->and($mail->actionText)->toBe('View game');
    });

    it('stores correct data for a campaign', function () {
        $participant = User::factory()->create(['name' => 'P1']);
        $campaign = Campaign::factory()->create(['name' => 'C1']);
        $notifiable = User::factory()->create();

        $data = (new ParticipantJoined($participant, $campaign, 'campaign'))->toDatabase($notifiable);

        expect($data['type'])->toBe('participant_joined')
            ->and($data['participant_id'])->toBe($participant->id)
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_name'])->toBe('C1')
            ->and($data['action_url'])->toContain('/campaigns/');
    });

    it('renders correct email content for a campaign', function () {
        $participant = User::factory()->create(['name' => 'P1']);
        $campaign = Campaign::factory()->create(['name' => 'C1']);
        $notifiable = User::factory()->create(['name' => 'Owner']);

        $mail = (new ParticipantJoined($participant, $campaign, 'campaign'))->toMail($notifiable);

        expect($mail->subject)->toContain('P1')
            ->and($mail->actionUrl)->toContain('/campaigns/');
    });

    it('returns participant as actor', function () {
        $participant = User::factory()->create();
        $game = Game::factory()->create();
        expect((new ParticipantJoined($participant, $game, 'game'))->getActor())->toBe($participant);
    });
});

// ---------------------------------------------------------------------------
// ParticipantRemoved
// ---------------------------------------------------------------------------
describe('ParticipantRemoved', function () {
    it('stores correct data for a game', function () {
        $removed = User::factory()->create(['name' => 'R1']);
        $game = Game::factory()->create(['name' => 'G1']);
        $notifiable = User::factory()->create();

        $data = (new ParticipantRemoved($removed, $game, 'game'))->toDatabase($notifiable);

        expect($data['type'])->toBe('participant_removed')
            ->and($data['removed_user_id'])->toBe($removed->id)
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_name'])->toBe('G1');
    });

    it('stores correct data for a campaign', function () {
        $removed = User::factory()->create(['name' => 'R1']);
        $campaign = Campaign::factory()->create(['name' => 'C1']);
        $notifiable = User::factory()->create();

        $data = (new ParticipantRemoved($removed, $campaign, 'campaign'))->toDatabase($notifiable);

        expect($data['type'])->toBe('participant_removed')
            ->and($data['removed_user_id'])->toBe($removed->id)
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_name'])->toBe('C1');
    });

    it('renders correct email content for a game', function () {
        $removed = User::factory()->create(['name' => 'R1']);
        $game = Game::factory()->create(['name' => 'G1']);
        $notifiable = User::factory()->create(['name' => 'Owner']);

        $mail = (new ParticipantRemoved($removed, $game, 'game'))->toMail($notifiable);

        expect($mail->subject)->toContain('G1')
            ->and($mail->actionText)->toBe('Browse Games');
    });

    it('returns null actor', function () {
        $removed = User::factory()->create();
        $game = Game::factory()->create();
        expect((new ParticipantRemoved($removed, $game, 'game'))->getActor())->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// GameInvitation
// ---------------------------------------------------------------------------
describe('GameInvitation', function () {
    it('stores correct data to database', function () {
        $game = Game::factory()->create(['name' => 'Epic Quest']);
        $inviter = User::factory()->create(['name' => 'Host']);
        $notifiable = User::factory()->create();

        $data = (new GameInvitation($game, $inviter))->toDatabase($notifiable);

        expect($data['type'])->toBe('game_invitation')
            ->and($data['game_id'])->toBe($game->id)
            ->and($data['game_name'])->toBe('Epic Quest')
            ->and($data['inviter_id'])->toBe($inviter->id)
            ->and($data['action_url'])->toContain('/games/');
    });

    it('renders correct email content', function () {
        $game = Game::factory()->create(['name' => 'Epic Quest']);
        $inviter = User::factory()->create(['name' => 'Host']);
        $notifiable = User::factory()->create(['name' => 'Guest']);

        $mail = (new GameInvitation($game, $inviter))->toMail($notifiable);

        expect($mail->subject)->toContain('Host')
            ->and($mail->actionUrl)->toContain('/games/');
    });

    it('returns inviter as actor', function () {
        $game = Game::factory()->create();
        $inviter = User::factory()->create();
        expect((new GameInvitation($game, $inviter))->getActor())->toBe($inviter);
    });

    it('returns correct push payload', function () {
        $game = Game::factory()->create(['name' => 'Epic Quest']);
        $inviter = User::factory()->create(['name' => 'Host']);
        $notifiable = User::factory()->create();

        $payload = (new GameInvitation($game, $inviter))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(\App\Dto\PushPayload::class)
            ->and($payload->title)->toBe('Game Invitation')
            ->and($payload->body)->toBe('Host invited you to Epic Quest')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/games/')
            ->and($payload->tag)->toBe("game-invitation-{$game->id}");
    });
});

// ---------------------------------------------------------------------------
// CampaignInvitation
// ---------------------------------------------------------------------------
describe('CampaignInvitation', function () {
    it('stores correct data to database', function () {
        $campaign = Campaign::factory()->create(['name' => 'Long Campaign']);
        $inviter = User::factory()->create(['name' => 'DM']);
        $notifiable = User::factory()->create();

        $data = (new CampaignInvitation($campaign, $inviter))->toDatabase($notifiable);

        expect($data['type'])->toBe('campaign_invitation')
            ->and($data['campaign_id'])->toBe($campaign->id)
            ->and($data['campaign_name'])->toBe('Long Campaign')
            ->and($data['inviter_name'])->toBe('DM')
            ->and($data['action_url'])->toContain('/campaigns/');
    });

    it('renders correct email content', function () {
        $campaign = Campaign::factory()->create(['name' => 'Long Campaign']);
        $inviter = User::factory()->create(['name' => 'DM']);
        $notifiable = User::factory()->create(['name' => 'Player']);

        $mail = (new CampaignInvitation($campaign, $inviter))->toMail($notifiable);

        expect($mail->subject)->toContain('DM')
            ->and($mail->actionUrl)->toContain('/campaigns/');
    });

    it('returns inviter as actor', function () {
        $campaign = Campaign::factory()->create();
        $inviter = User::factory()->create();
        expect((new CampaignInvitation($campaign, $inviter))->getActor())->toBe($inviter);
    });

    it('returns correct push payload', function () {
        $campaign = Campaign::factory()->create(['name' => 'Long Campaign']);
        $inviter = User::factory()->create(['name' => 'DM']);
        $notifiable = User::factory()->create();

        $payload = (new CampaignInvitation($campaign, $inviter))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(\App\Dto\PushPayload::class)
            ->and($payload->title)->toBe('Campaign Invitation')
            ->and($payload->body)->toBe('DM invited you to Long Campaign')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/campaigns/')
            ->and($payload->tag)->toBe("campaign-invitation-{$campaign->id}");
    });
});

// ---------------------------------------------------------------------------
// TeamInvitation
// ---------------------------------------------------------------------------
describe('TeamInvitation', function () {
    it('stores correct data to database', function () {
        $team = Team::factory()->create(['name' => 'Rangers']);
        $inviter = User::factory()->create(['name' => 'Captain']);
        $notifiable = User::factory()->create();

        $data = (new TeamInvitation($team, $inviter))->toDatabase($notifiable);

        expect($data['type'])->toBe('team_invitation')
            ->and($data['team_id'])->toBe($team->id)
            ->and($data['team_name'])->toBe('Rangers')
            ->and($data['inviter_name'])->toBe('Captain')
            ->and($data['action_url'])->toContain('/teams/');
    });

    it('renders correct email content', function () {
        $team = Team::factory()->create(['name' => 'Rangers']);
        $inviter = User::factory()->create(['name' => 'Captain']);
        $notifiable = User::factory()->create(['name' => 'Rookie']);

        $mail = (new TeamInvitation($team, $inviter))->toMail($notifiable);

        expect($mail->subject)->toContain('Captain')
            ->and($mail->actionUrl)->toContain('/teams/');
    });

    it('returns inviter as actor', function () {
        $team = Team::factory()->create();
        $inviter = User::factory()->create();
        expect((new TeamInvitation($team, $inviter))->getActor())->toBe($inviter);
    });
});

// ---------------------------------------------------------------------------
// SessionAddedToCampaign
// ---------------------------------------------------------------------------
describe('SessionAddedToCampaign', function () {
    it('stores correct data to database', function () {
        $session = Game::factory()->create(['name' => 'Session 1']);
        $campaign = Campaign::factory()->create(['name' => 'Big Campaign']);
        $notifiable = User::factory()->create();

        $data = (new SessionAddedToCampaign($session, $campaign))->toDatabase($notifiable);

        expect($data['type'])->toBe('session_added_to_campaign')
            ->and($data['session_id'])->toBe($session->id)
            ->and($data['campaign_name'])->toBe('Big Campaign')
            ->and($data['action_url'])->toContain('/games/');
    });

    it('renders correct email content', function () {
        $session = Game::factory()->create(['name' => 'Session 1']);
        $campaign = Campaign::factory()->create(['name' => 'Big Campaign']);
        $notifiable = User::factory()->create(['name' => 'Player']);

        $mail = (new SessionAddedToCampaign($session, $campaign))->toMail($notifiable);

        expect($mail->subject)->toContain('Big Campaign')
            ->and($mail->actionUrl)->toContain('/games/');
    });

    it('returns campaign owner as actor', function () {
        $session = Game::factory()->create();
        $campaign = Campaign::factory()->create();
        expect((new SessionAddedToCampaign($session, $campaign))->getActor())->toBe($campaign->owner);
    });
});

// ---------------------------------------------------------------------------
// NewApplication
// ---------------------------------------------------------------------------
describe('NewApplication', function () {
    it('stores correct data to database', function () {
        $applicant = User::factory()->create(['name' => 'Applicant']);
        $game = Game::factory()->create(['name' => 'Open Game']);
        $notifiable = User::factory()->create();

        $data = (new NewApplication($applicant, $game, 'game'))->toDatabase($notifiable);

        expect($data['type'])->toBe('new_application')
            ->and($data['applicant_id'])->toBe($applicant->id)
            ->and($data['applicant_name'])->toBe('Applicant')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_name'])->toBe('Open Game')
            ->and($data['action_url'])->toContain('/games/');
    });

    it('renders correct email content', function () {
        $applicant = User::factory()->create(['name' => 'Applicant']);
        $game = Game::factory()->create(['name' => 'Open Game']);
        $notifiable = User::factory()->create(['name' => 'Owner']);

        $mail = (new NewApplication($applicant, $game, 'game'))->toMail($notifiable);

        expect($mail->subject)->toContain('Applicant')
            ->and($mail->actionUrl)->toContain('/games/');
    });

    it('returns applicant as actor', function () {
        $applicant = User::factory()->create();
        $game = Game::factory()->create();
        expect((new NewApplication($applicant, $game, 'game'))->getActor())->toBe($applicant);
    });
});

// ---------------------------------------------------------------------------
// ApplicationApproved
// ---------------------------------------------------------------------------
describe('ApplicationApproved', function () {
    it('stores correct data to database', function () {
        $game = Game::factory()->create(['name' => 'Approved Game']);
        $approver = User::factory()->create(['name' => 'Approver']);
        $notifiable = User::factory()->create();

        $data = (new ApplicationApproved($game, 'game', $approver))->toDatabase($notifiable);

        expect($data['type'])->toBe('application_approved')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_name'])->toBe('Approved Game')
            ->and($data['action_url'])->toContain('/games/');
    });

    it('renders correct email content', function () {
        $game = Game::factory()->create(['name' => 'Approved Game']);
        $approver = User::factory()->create();
        $notifiable = User::factory()->create(['name' => 'Applicant']);

        $mail = (new ApplicationApproved($game, 'game', $approver))->toMail($notifiable);

        expect($mail->subject)->toContain('Approved Game')
            ->and($mail->actionUrl)->toContain('/games/');
    });

    it('returns approver as actor', function () {
        $game = Game::factory()->create();
        $approver = User::factory()->create();
        expect((new ApplicationApproved($game, 'game', $approver))->getActor())->toBe($approver);
    });
});

// ---------------------------------------------------------------------------
// ApplicationRejected
// ---------------------------------------------------------------------------
describe('ApplicationRejected', function () {
    it('stores correct data to database', function () {
        $game = Game::factory()->create(['name' => 'Rejected Game']);
        $rejector = User::factory()->create();
        $notifiable = User::factory()->create();

        $data = (new ApplicationRejected($game, 'game', $rejector))->toDatabase($notifiable);

        expect($data['type'])->toBe('application_rejected')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_name'])->toBe('Rejected Game')
            ->and($data['action_url'])->toContain('/games');
    });

    it('renders correct email content', function () {
        $game = Game::factory()->create(['name' => 'Rejected Game']);
        $rejector = User::factory()->create();
        $notifiable = User::factory()->create(['name' => 'Applicant']);

        $mail = (new ApplicationRejected($game, 'game', $rejector))->toMail($notifiable);

        expect($mail->subject)->toContain('Rejected Game')
            ->and($mail->actionUrl)->toContain('/games');
    });

    it('returns rejector as actor', function () {
        $game = Game::factory()->create();
        $rejector = User::factory()->create();
        expect((new ApplicationRejected($game, 'game', $rejector))->getActor())->toBe($rejector);
    });
});

// ---------------------------------------------------------------------------
// GameCancelled
// ---------------------------------------------------------------------------
describe('GameCancelled', function () {
    it('stores correct data to database', function () {
        $game = Game::factory()->create(['name' => 'Cancelled Game']);
        $notifiable = User::factory()->create();

        $data = (new GameCancelled($game))->toDatabase($notifiable);

        expect($data['type'])->toBe('game_cancelled')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_name'])->toBe('Cancelled Game')
            ->and($data['action_url'])->toContain('/games');
    });

    it('renders correct email content', function () {
        $game = Game::factory()->create(['name' => 'Cancelled Game']);
        $notifiable = User::factory()->create(['name' => 'Player']);

        $mail = (new GameCancelled($game))->toMail($notifiable);

        expect($mail->subject)->toContain('Cancelled Game')
            ->and($mail->actionUrl)->toContain('/games');
    });

    it('returns game owner as actor', function () {
        $game = Game::factory()->create();
        expect((new GameCancelled($game))->getActor())->toBe($game->owner);
    });

    it('returns correct push payload', function () {
        $game = Game::factory()->create(['name' => 'Cancelled Game']);
        $notifiable = User::factory()->create();

        $payload = (new GameCancelled($game))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(\App\Dto\PushPayload::class)
            ->and($payload->title)->toBe('Game Cancelled')
            ->and($payload->body)->toBe('Cancelled Game has been cancelled')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/games/')
            ->and($payload->tag)->toBe("game-cancelled-{$game->id}");
    });
});

// ---------------------------------------------------------------------------
// GameCompleted
// ---------------------------------------------------------------------------
describe('GameCompleted', function () {
    it('stores correct data to database', function () {
        $game = Game::factory()->create(['name' => 'Completed Game']);
        $notifiable = User::factory()->create();

        $data = (new GameCompleted($game))->toDatabase($notifiable);

        expect($data['type'])->toBe('game_completed')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_name'])->toBe('Completed Game');
    });

    it('renders correct email content', function () {
        $game = Game::factory()->create(['name' => 'Completed Game']);
        $notifiable = User::factory()->create(['name' => 'Player']);

        $mail = (new GameCompleted($game))->toMail($notifiable);

        expect($mail->subject)->toContain('Completed Game')
            ->and($mail->actionUrl)->toContain('/games');
    });

    it('returns game owner as actor', function () {
        $game = Game::factory()->create();
        expect((new GameCompleted($game))->getActor())->toBe($game->owner);
    });
});

// ---------------------------------------------------------------------------
// CampaignCancelled
// ---------------------------------------------------------------------------
describe('CampaignCancelled', function () {
    it('stores correct data to database', function () {
        $campaign = Campaign::factory()->create(['name' => 'Cancelled Camp']);
        $notifiable = User::factory()->create();

        $data = (new CampaignCancelled($campaign))->toDatabase($notifiable);

        expect($data['type'])->toBe('campaign_cancelled')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_name'])->toBe('Cancelled Camp')
            ->and($data['action_url'])->toContain('/campaigns');
    });

    it('renders correct email content', function () {
        $campaign = Campaign::factory()->create(['name' => 'Cancelled Camp']);
        $notifiable = User::factory()->create(['name' => 'Player']);

        $mail = (new CampaignCancelled($campaign))->toMail($notifiable);

        expect($mail->subject)->toContain('Cancelled Camp')
            ->and($mail->actionUrl)->toContain('/campaigns');
    });

    it('returns campaign owner as actor', function () {
        $campaign = Campaign::factory()->create();
        expect((new CampaignCancelled($campaign))->getActor())->toBe($campaign->owner);
    });

    it('returns correct push payload', function () {
        $campaign = Campaign::factory()->create(['name' => 'Cancelled Camp']);
        $notifiable = User::factory()->create();

        $payload = (new CampaignCancelled($campaign))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(\App\Dto\PushPayload::class)
            ->and($payload->title)->toBe('Campaign Cancelled')
            ->and($payload->body)->toBe('Cancelled Camp has been cancelled')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/campaigns/')
            ->and($payload->tag)->toBe("campaign-cancelled-{$campaign->id}");
    });
});

// ---------------------------------------------------------------------------
// CampaignCompleted
// ---------------------------------------------------------------------------
describe('CampaignCompleted', function () {
    it('stores correct data to database', function () {
        $campaign = Campaign::factory()->create(['name' => 'Completed Camp']);
        $notifiable = User::factory()->create();

        $data = (new CampaignCompleted($campaign))->toDatabase($notifiable);

        expect($data['type'])->toBe('campaign_completed')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_name'])->toBe('Completed Camp');
    });

    it('renders correct email content', function () {
        $campaign = Campaign::factory()->create(['name' => 'Completed Camp']);
        $notifiable = User::factory()->create(['name' => 'Player']);

        $mail = (new CampaignCompleted($campaign))->toMail($notifiable);

        expect($mail->subject)->toContain('Completed Camp')
            ->and($mail->actionUrl)->toContain('/campaigns');
    });

    it('returns campaign owner as actor', function () {
        $campaign = Campaign::factory()->create();
        expect((new CampaignCompleted($campaign))->getActor())->toBe($campaign->owner);
    });
});

// ---------------------------------------------------------------------------
// TeamMemberRemoved
// ---------------------------------------------------------------------------
describe('TeamMemberRemoved', function () {
    it('stores correct data to database', function () {
        $team = Team::factory()->create(['name' => 'The Team']);
        $remover = User::factory()->create(['name' => 'Admin']);
        $notifiable = User::factory()->create();

        $data = (new TeamMemberRemoved($team, $remover))->toDatabase($notifiable);

        expect($data['type'])->toBe('team_member_removed')
            ->and($data['entity_id'])->toBe($team->id)
            ->and($data['entity_type'])->toBe('team')
            ->and($data['entity_name'])->toBe('The Team')
            ->and($data['remover_name'])->toBe('Admin')
            ->and($data['action_url'])->toContain('/teams');
    });

    it('renders correct email content', function () {
        $team = Team::factory()->create(['name' => 'The Team']);
        $remover = User::factory()->create(['name' => 'Admin']);
        $notifiable = User::factory()->create(['name' => 'Member']);

        $mail = (new TeamMemberRemoved($team, $remover))->toMail($notifiable);

        expect($mail->subject)->toContain('The Team')
            ->and($mail->actionUrl)->toContain('/teams');
    });

    it('returns remover as actor', function () {
        $team = Team::factory()->create();
        $remover = User::factory()->create();
        expect((new TeamMemberRemoved($team, $remover))->getActor())->toBe($remover);
    });
});

// ---------------------------------------------------------------------------
// All notifications return correct via() channels
// ---------------------------------------------------------------------------
describe('via() channel resolution', function () {
    it('all notification classes return database and mail as fallback channels', function () {
        $user = User::factory()->create();
        $game = Game::factory()->create();
        $campaign = Campaign::factory()->create();
        $team = Team::factory()->create();

        $notifications = [
            new NewFollower($user),
            new ParticipantJoined($user, $game, 'game'),
            new ParticipantRemoved($user, $game, 'game'),
            new GameInvitation($game, $user),
            new CampaignInvitation($campaign, $user),
            new TeamInvitation($team, $user),
            new SessionAddedToCampaign($game, $campaign),
            new NewApplication($user, $game, 'game'),
            new ApplicationApproved($game, 'game', $user),
            new ApplicationRejected($game, 'game', $user),
            new GameCancelled($game),
            new GameCompleted($game),
            new CampaignCancelled($campaign),
            new CampaignCompleted($campaign),
            new TeamMemberRemoved($team, $user),
        ];

        foreach ($notifications as $notification) {
            $channels = $notification->via($user);
            expect($channels)->toContain(DatabaseChannel::class, MailChannel::class);
        }
    });
});

// ---------------------------------------------------------------------------
// Non-push notifications return null from toPush()
// ---------------------------------------------------------------------------
describe('toPush() returns null for non-push notification types', function () {
    it('returns null for notifications not requiring push', function () {
        $user = User::factory()->create();
        $game = Game::factory()->create();
        $campaign = Campaign::factory()->create();
        $team = Team::factory()->create();

        $notifications = [
            new ParticipantJoined($user, $game, 'game'),
            new ParticipantRemoved($user, $game, 'game'),
            new TeamInvitation($team, $user),
            new SessionAddedToCampaign($game, $campaign),
            new NewApplication($user, $game, 'game'),
            new ApplicationApproved($game, 'game', $user),
            new ApplicationRejected($game, 'game', $user),
            new GameCompleted($game),
            new CampaignCompleted($campaign),
            new TeamMemberRemoved($team, $user),
        ];

        foreach ($notifications as $notification) {
            expect($notification->toPush($user))->toBeNull();
        }
    });
});
