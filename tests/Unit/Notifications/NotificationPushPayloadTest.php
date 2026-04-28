<?php

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\ApplicationApproved;
use App\Notifications\ApplicationRejected;
use App\Notifications\CampaignCancelled;
use App\Notifications\CampaignInvitation;
use App\Notifications\GameCancelled;
use App\Notifications\GameInvitation;
use App\Notifications\NewFollower;
use App\Notifications\ParticipantJoined;
use App\Notifications\PlayerBenched;
use App\Notifications\SessionReminder;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ---------------------------------------------------------------------------
// Push-enabled notifications
// ---------------------------------------------------------------------------
describe('GameInvitation push payload', function () {
    it('returns PushPayload with correct fields', function () {
        $game = Game::factory()->create(['name' => 'Epic Quest']);
        $inviter = User::factory()->create(['name' => 'Host']);
        $notifiable = User::factory()->create();

        $payload = (new GameInvitation($game, $inviter))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(PushPayload::class)
            ->and($payload->title)->toBe('Game Invitation')
            ->and($payload->body)->toBe('Host invited you to Epic Quest')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/games/')
            ->and($payload->tag)->toBe("game-invitation-{$game->id}");
    });

    it('URL resolves to a valid game detail route', function () {
        $game = Game::factory()->create();
        $inviter = User::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new GameInvitation($game, $inviter))->toPush($notifiable);

        expect($payload->url)->toBe(route('games.detail', $game->id));
    });

    it('body contains game name', function () {
        $game = Game::factory()->create(['name' => 'D&D Night']);
        $inviter = User::factory()->create(['name' => 'Alice']);
        $notifiable = User::factory()->create();

        $payload = (new GameInvitation($game, $inviter))->toPush($notifiable);

        expect($payload->body)->toContain('D&D Night');
    });
});

describe('CampaignInvitation push payload', function () {
    it('returns PushPayload with correct fields', function () {
        $campaign = Campaign::factory()->create(['name' => 'Long Campaign']);
        $inviter = User::factory()->create(['name' => 'DM']);
        $notifiable = User::factory()->create();

        $payload = (new CampaignInvitation($campaign, $inviter))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(PushPayload::class)
            ->and($payload->title)->toBe('Campaign Invitation')
            ->and($payload->body)->toBe('DM invited you to Long Campaign')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/campaigns/')
            ->and($payload->tag)->toBe("campaign-invitation-{$campaign->id}");
    });

    it('URL resolves to a valid campaign detail route', function () {
        $campaign = Campaign::factory()->create();
        $inviter = User::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new CampaignInvitation($campaign, $inviter))->toPush($notifiable);

        expect($payload->url)->toBe(route('campaigns.detail', $campaign->id));
    });

    it('body contains campaign name', function () {
        $campaign = Campaign::factory()->create(['name' => 'Curse of Strahd']);
        $inviter = User::factory()->create(['name' => 'Bob']);
        $notifiable = User::factory()->create();

        $payload = (new CampaignInvitation($campaign, $inviter))->toPush($notifiable);

        expect($payload->body)->toContain('Curse of Strahd');
    });
});

describe('NewFollower push payload', function () {
    it('returns PushPayload with correct fields', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $notifiable = User::factory()->create();

        $payload = (new NewFollower($follower))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(PushPayload::class)
            ->and($payload->title)->toBe('New Follower')
            ->and($payload->body)->toBe('Alice started following you')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/u/')
            ->and($payload->tag)->toBe("new-follower-{$follower->id}");
    });

    it('URL resolves to a valid profile route', function () {
        $follower = User::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new NewFollower($follower))->toPush($notifiable);

        expect($payload->url)->toContain('/u/' . $follower->id);
    });
});

describe('GameCancelled push payload', function () {
    it('returns PushPayload with correct fields', function () {
        $game = Game::factory()->create(['name' => 'Cancelled Game']);
        $notifiable = User::factory()->create();

        $payload = (new GameCancelled($game))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(PushPayload::class)
            ->and($payload->title)->toBe('Game Cancelled')
            ->and($payload->body)->toBe('Cancelled Game has been cancelled')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/games/')
            ->and($payload->tag)->toBe("game-cancelled-{$game->id}");
    });

    it('body contains game name', function () {
        $game = Game::factory()->create(['name' => 'Session 42']);
        $notifiable = User::factory()->create();

        $payload = (new GameCancelled($game))->toPush($notifiable);

        expect($payload->body)->toContain('Session 42');
    });
});

describe('CampaignCancelled push payload', function () {
    it('returns PushPayload with correct fields', function () {
        $campaign = Campaign::factory()->create(['name' => 'Cancelled Camp']);
        $notifiable = User::factory()->create();

        $payload = (new CampaignCancelled($campaign))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(PushPayload::class)
            ->and($payload->title)->toBe('Campaign Cancelled')
            ->and($payload->body)->toBe('Cancelled Camp has been cancelled')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/campaigns/')
            ->and($payload->tag)->toBe("campaign-cancelled-{$campaign->id}");
    });

    it('body contains campaign name', function () {
        $campaign = Campaign::factory()->create(['name' => 'Dragon Lance']);
        $notifiable = User::factory()->create();

        $payload = (new CampaignCancelled($campaign))->toPush($notifiable);

        expect($payload->body)->toContain('Dragon Lance');
    });
});

describe('SessionReminder push payload', function () {
    it('returns PushPayload with correct fields', function () {
        $game = Game::factory()->create([
            'name' => 'Weekly Session',
            'date_time' => now()->addMinutes(30),
        ]);
        $notifiable = User::factory()->create();

        $payload = (new SessionReminder($game))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(PushPayload::class)
            ->and($payload->title)->toBe('Game Reminder')
            ->and($payload->body)->toContain('Weekly Session')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/games/')
            ->and($payload->tag)->toBe("game-reminder-1h-{$game->id}");
    });

    it('body contains timezone-aware formatted time', function () {
        // 2026-04-27 19:00:00 UTC → 21:00:00 CEST (Europe/Berlin, DST active)
        $game = Game::factory()->create([
            'name' => 'Evening Game',
            'date_time' => Carbon\Carbon::parse('2026-04-27 19:00:00', 'UTC'),
        ]);
        $notifiable = User::factory()->create();

        $payload = (new SessionReminder($game))->toPush($notifiable);

        expect($payload->body)->toContain('9:00 PM CEST');
    });

    it('handles CET winter time correctly', function () {
        // 2026-01-15 19:00:00 UTC → 20:00:00 CET (Europe/Berlin, no DST)
        $game = Game::factory()->create([
            'name' => 'Winter Game',
            'date_time' => Carbon\Carbon::parse('2026-01-15 19:00:00', 'UTC'),
        ]);
        $notifiable = User::factory()->create();

        $payload = (new SessionReminder($game))->toPush($notifiable);

        expect($payload->body)->toContain('8:00 PM CET');
    });
});

describe('PlayerBenched push payload', function () {
    it('returns PushPayload with correct fields for game entity', function () {
        $game = Game::factory()->create(['name' => 'Full Table']);
        $notifiable = User::factory()->create();

        $payload = (new PlayerBenched($game, 'game'))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(PushPayload::class)
            ->and($payload->title)->toBe("You're on the Bench")
            ->and($payload->body)->toContain('Full Table')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/games/')
            ->and($payload->tag)->toBe("player-benched-game-{$game->id}");
    });

    it('returns PushPayload with correct fields for campaign entity', function () {
        $campaign = Campaign::factory()->create(['name' => 'Long Campaign']);
        $notifiable = User::factory()->create();

        $payload = (new PlayerBenched($campaign, 'campaign'))->toPush($notifiable);

        expect($payload)->toBeInstanceOf(PushPayload::class)
            ->and($payload->title)->toBe("You're on the Bench")
            ->and($payload->body)->toContain('Long Campaign')
            ->and($payload->icon)->toBe('/icons/pwa-192x192.png')
            ->and($payload->url)->toContain('/campaigns/')
            ->and($payload->tag)->toBe("player-benched-campaign-{$campaign->id}");
    });

    it('URL resolves to game detail page for game entity', function () {
        $game = Game::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new PlayerBenched($game, 'game'))->toPush($notifiable);

        expect($payload->url)->toBe(route('games.detail', $game->id));
    });

    it('URL resolves to campaign detail page for campaign entity', function () {
        $campaign = Campaign::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new PlayerBenched($campaign, 'campaign'))->toPush($notifiable);

        expect($payload->url)->toBe(route('campaigns.detail', $campaign->id));
    });

    it('body contains entity name', function () {
        $game = Game::factory()->create(['name' => 'Catan Night']);
        $notifiable = User::factory()->create();

        $payload = (new PlayerBenched($game, 'game'))->toPush($notifiable);

        expect($payload->body)->toContain('Catan Night');
    });
});

// ---------------------------------------------------------------------------
// Non-push notifications return null
// ---------------------------------------------------------------------------
describe('Non-push notifications', function () {
    it('ApplicationApproved returns null', function () {
        $game = Game::factory()->create();
        $approver = User::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new ApplicationApproved($game, 'game', $approver))->toPush($notifiable);

        expect($payload)->toBeNull();
    });

    it('ApplicationRejected returns null', function () {
        $game = Game::factory()->create();
        $rejector = User::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new ApplicationRejected($game, 'game', $rejector))->toPush($notifiable);

        expect($payload)->toBeNull();
    });

    it('ParticipantJoined returns null', function () {
        $participant = User::factory()->create();
        $game = Game::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new ParticipantJoined($participant, $game, 'game'))->toPush($notifiable);

        expect($payload)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// toArray() structure
// ---------------------------------------------------------------------------
describe('PushPayload toArray', function () {
    it('includes all fields including tag', function () {
        $payload = new PushPayload(
            title: 'Title',
            body: 'Body',
            icon: '/icon.png',
            url: 'https://example.com',
            tag: 'test-tag',
        );

        expect($payload->toArray())->toBe([
            'title' => 'Title',
            'body' => 'Body',
            'icon' => '/icon.png',
            'url' => 'https://example.com',
            'tag' => 'test-tag',
        ]);
    });

    it('omits tag when null', function () {
        $payload = new PushPayload(
            title: 'Title',
            body: 'Body',
            icon: '/icon.png',
            url: 'https://example.com',
            tag: null,
        );

        $array = $payload->toArray();
        expect($array)->not->toHaveKey('tag')
            ->and($array)->toHaveCount(4);
    });
});
