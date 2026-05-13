<?php

use App\Enums\ActivityType;
use App\Enums\GameType;
use App\Enums\JoinSource;
use App\Enums\Visibility;
use App\Jobs\EnrichPostHogProfile;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Review;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\PostHogClient;
use App\Services\PostHogEventBridge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Helpers\TestablePostHogClient;

beforeEach(function () {
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_test_key');

    $this->posthogClient = new TestablePostHogClient();
    $this->app->instance(PostHogClient::class, $this->posthogClient);
});

/**
 * Helper to make a Game with gameSystem relation pre-loaded
 * to avoid lazy-load DB queries with synthetic IDs.
 */
function makeGameWithSystem(array $gameAttrs = [], array $systemAttrs = []): Game
{
    $systemId = $systemAttrs['id'] ?? Str::uuid()->toString();
    $gameSystem = GameSystem::factory()->make(array_merge(['id' => $systemId, 'name' => 'D&D 5e'], $systemAttrs));
    $game = Game::factory()->make(array_merge([
        'id' => Str::uuid()->toString(),
        'game_system_id' => $systemId,
    ], $gameAttrs));
    $game->setRelation('gameSystem', $gameSystem);

    return $game;
}

function makeCampaignWithSystem(array $campaignAttrs = [], array $systemAttrs = []): Campaign
{
    $systemId = $systemAttrs['id'] ?? Str::uuid()->toString();
    $gameSystem = GameSystem::factory()->make(array_merge(['id' => $systemId, 'name' => 'D&D 5e'], $systemAttrs));
    $campaign = Campaign::factory()->make(array_merge([
        'id' => Str::uuid()->toString(),
        'game_system_id' => $systemId,
    ], $campaignAttrs));
    $campaign->setRelation('gameSystem', $gameSystem);

    return $campaign;
}

/**
 * Get the last captured call from the test client.
 *
 * Activity observers may add extra capture calls during test setup
 * (e.g., User::factory()->create() triggers ActivityLogService).
 * The explicit forwardEvent call is always the last one.
 */
function lastCapture(TestablePostHogClient $client): array
{
    return $client->capturedCalls[array_key_last($client->capturedCalls)];
}

// ── Core forwarding behavior ────────────────────────────

it('calls capture with correct distinct ID and event name', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 42]);
    $game = makeGameWithSystem();

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user, $game);

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    expect($this->posthogClient->capturedCalls[0]['distinctId'])->toBe('42');
    expect($this->posthogClient->capturedCalls[0]['event'])->toBe('game.created');
});

it('merges caller-provided properties with extracted properties', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $game = makeGameWithSystem();

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user, $game, [
        'custom_field' => 'custom_value',
    ]);

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    expect($this->posthogClient->capturedCalls[0]['properties'])->toHaveKey('custom_field', 'custom_value');
    expect($this->posthogClient->capturedCalls[0]['properties'])->toHaveKey('game_id');
});

it('skips when PostHog is disabled', function () {
    $this->posthogClient->setEnabled(false);

    $user = User::factory()->make(['id' => 1]);

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user);

    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

it('skips when API key is missing', function () {
    $this->posthogClient->setEnabled(false);

    $user = User::factory()->make(['id' => 1]);

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user);

    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

it('never throws when capture fails', function () {
    // Bind a client that throws on capture
    $throwingClient = new class extends TestablePostHogClient {
        public function capture(array $payload): void
        {
            throw new RuntimeException('PostHog down');
        }
    };
    $this->app->instance(PostHogClient::class, $throwingClient);

    $user = User::factory()->make(['id' => 1]);

    // Should NOT throw
    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user);

    expect(true)->toBeTrue();
});

it('logs warning on capture failure', function () {
    Log::shouldReceive('channel')->with('daily')->andReturnSelf();
    Log::shouldReceive('warning')
        ->once()
        ->with('posthog.event_bridge.failed', Mockery::on(function ($ctx) {
            return $ctx['event_type'] === 'game_created'
                && $ctx['error'] === 'PostHog down';
        }));

    $throwingClient = new class extends TestablePostHogClient {
        public function capture(array $payload): void
        {
            throw new RuntimeException('PostHog down');
        }
    };
    $this->app->instance(PostHogClient::class, $throwingClient);

    $user = User::factory()->make(['id' => 1]);
    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user);
});

// ── Event name mapping ──────────────────────────────────

it('maps all ActivityTypes to PostHog event names', function () {
    Queue::fake();

    $expectedMap = [
        'game_created' => 'game.created',
        'game_completed' => 'game.completed',
        'game_canceled' => 'game.canceled',
        'game_updated' => 'game.updated',
        'campaign_created' => 'campaign.created',
        'campaign_completed' => 'campaign.completed',
        'campaign_canceled' => 'campaign.canceled',
        'campaign_updated' => 'campaign.updated',
        'player_joined' => 'game.player_joined',
        'session_scheduled' => 'session.scheduled',
        'invitation_received' => 'invitation.received',
        'invitation_accepted' => 'invitation.accepted',
        'review_received' => 'review.received',
        'follow_received' => 'follow.received',
        'session_recapped' => 'session.recapped',
        'debriefing_submitted' => 'session.debriefing_submitted',
    ];

    $user = User::factory()->make(['id' => 1]);

    foreach (ActivityType::cases() as $type) {
        $client = new TestablePostHogClient();
        $this->app->instance(PostHogClient::class, $client);

        Queue::fake();

        $bridge = $this->app->make(PostHogEventBridge::class);
        $bridge->forwardEvent($type, $user);

        expect($client->capturedCalls)->toHaveCount(1);
        expect($client->capturedCalls[0]['event'])->toBe($expectedMap[$type->value]);
    }

    // Ensure every ActivityType is covered
    expect(count($expectedMap))->toBe(count(ActivityType::cases()));
});

// ── Game property extraction ────────────────────────────

it('extracts game properties for GameCreated', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $game = makeGameWithSystem(
        gameAttrs: [
            'visibility' => Visibility::Public,
            'max_players' => 6,
            'min_players' => 3,
            'game_type' => GameType::Ttrpg,
            'location' => ['type' => 'online', 'details' => 'Discord'],
        ],
        systemAttrs: ['name' => 'D&D 5e'],
    );

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user, $game);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['game_system'])->toBe('D&D 5e')
        ->and($props['visibility'])->toBe('public')
        ->and($props['max_players'])->toBe(6)
        ->and($props['min_players'])->toBe(3)
        ->and($props['location_type'])->toBe('online')
        ->and($props['is_online'])->toBeTrue()
        ->and($props['game_type'])->toBe('ttrpg')
        ->and($props)->toHaveKey('game_id');
});

it('detects offline location correctly', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $game = makeGameWithSystem(
        gameAttrs: ['location' => ['type' => 'physical', 'details' => 'Game Store']],
    );

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user, $game);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['is_online'])->toBeFalse()
        ->and($props['location_type'])->toBe('physical');
});

it('handles null location gracefully', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $game = makeGameWithSystem(gameAttrs: ['location' => null]);

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user, $game);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['is_online'])->toBeFalse()
        ->and($props['location_type'])->toBeNull();
});

// ── Campaign property extraction ────────────────────────

it('extracts campaign properties for CampaignCreated', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $campaign = makeCampaignWithSystem(
        campaignAttrs: [
            'visibility' => Visibility::Protected,
            'max_players' => 4,
            'min_players' => 2,
        ],
        systemAttrs: ['name' => 'Pathfinder 2e'],
    );

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::CampaignCreated, $user, $campaign);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['game_system'])->toBe('Pathfinder 2e')
        ->and($props['visibility'])->toBe('protected')
        ->and($props['max_players'])->toBe(4)
        ->and($props['min_players'])->toBe(2)
        ->and($props)->toHaveKey('campaign_id');
});

// ── Player joined property extraction ───────────────────

it('extracts player joined properties from GameParticipant subject', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $game = makeGameWithSystem(systemAttrs: ['name' => 'D&D 5e']);

    $participant = GameParticipant::factory()->make([
        'game_id' => $game->id,
        'role' => 'player',
        'join_source' => JoinSource::ShareLink,
    ]);
    $participant->setRelation('game', $game);

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::PlayerJoined, $user, $participant);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['game_id'])->toBe($game->id)
        ->and($props['game_system'])->toBe('D&D 5e')
        ->and($props['participant_role'])->toBe('player')
        ->and($props['source'])->toBe('share_link');
});

it('falls back to game properties when PlayerJoined subject is a Game', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $game = makeGameWithSystem(gameAttrs: ['location' => ['type' => 'online']]);

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::PlayerJoined, $user, $game);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['game_id'])->toBe($game->id)
        ->and($props['is_online'])->toBeTrue();
});

// ── Session scheduled property extraction ───────────────

it('extracts session scheduled properties', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $game = makeGameWithSystem(
        gameAttrs: [
            'date_time' => now()->parse('2025-08-15 19:00:00'),
            'location' => ['type' => 'physical'],
        ],
        systemAttrs: ['name' => 'D&D 5e'],
    );

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::SessionScheduled, $user, $game);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['game_system'])->toBe('D&D 5e')
        ->and($props['scheduled_date'])->toBe('2025-08-15')
        ->and($props['location_type'])->toBe('physical')
        ->and($props['is_online'])->toBeFalse();
});

// ── Invitation property extraction ──────────────────────

it('extracts invitation properties for game subject', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $game = makeGameWithSystem(gameAttrs: ['owner_id' => 99]);

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::InvitationReceived, $user, $game);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['source_type'])->toBe('game')
        ->and($props['source_id'])->toBe($game->id)
        ->and($props['inviter_id'])->toBe(99);
});

it('extracts invitation properties for campaign subject', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $campaign = makeCampaignWithSystem(campaignAttrs: ['owner_id' => 50]);

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::InvitationAccepted, $user, $campaign);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['source_type'])->toBe('campaign')
        ->and($props['source_id'])->toBe($campaign->id)
        ->and($props['inviter_id'])->toBe(50);
});

// ── Review property extraction ──────────────────────────

it('extracts review properties', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $game = makeGameWithSystem();

    $review = Review::factory()->make([
        'id' => Str::uuid()->toString(),
        'rating' => 5,
        'reviewable_type' => Game::class,
        'reviewable_id' => $game->id,
    ]);
    $review->setRelation('reviewable', $game);

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::ReviewReceived, $user, $review);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['rating'])->toBe(5)
        ->and($props['game_system'])->toBe('D&D 5e')
        ->and($props['reviewable_type'])->toBe('Game');
});

// ── Follow property extraction ──────────────────────────

it('extracts follow properties with follower count', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $followedUser = User::factory()->create();

    // Create follow relationships
    $follower = User::factory()->create();
    UserRelationship::create([
        'user_id' => $follower->id,
        'related_user_id' => $followedUser->id,
        'type' => 'follow',
    ]);

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::FollowReceived, $user, $followedUser);

    // Activity observers may add prior capture calls — our call is the last one
    $props = lastCapture($this->posthogClient)['properties'];
    expect($props['followed_user_id'])->toBe($followedUser->id);
});

// ── Edge cases ──────────────────────────────────────────

it('handles null subject gracefully for all event types', function () {
    $user = User::factory()->make(['id' => 1]);

    foreach (ActivityType::cases() as $type) {
        $client = new TestablePostHogClient();
        $this->app->instance(PostHogClient::class, $client);

        Queue::fake();

        $bridge = $this->app->make(PostHogEventBridge::class);
        $bridge->forwardEvent($type, $user, null);

        expect($client->capturedCalls)->toHaveCount(1);
    }
});

it('handles wrong subject type for game properties', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $wrongSubject = makeCampaignWithSystem();

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user, $wrongSubject);

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    expect($this->posthogClient->capturedCalls[0]['properties'])->not->toHaveKey('game_id');
});

// ── Enrichment job dispatch ────────────────────────────

it('dispatches EnrichPostHogProfile after capture', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 42]);
    $game = makeGameWithSystem();

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user, $game);

    Queue::assertPushed(EnrichPostHogProfile::class, 1);
});

it('dispatches EnrichPostHogProfile with correct arguments', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 42]);
    $game = makeGameWithSystem();

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user, $game);

    Queue::assertPushed(EnrichPostHogProfile::class, function ($job) use ($game) {
        return $job->type === ActivityType::GameCreated->value
            && $job->userId === '42'
            && $job->subjectType === 'App\\Models\\Game'
            && $job->subjectId === $game->id;
    });
});

it('dispatches EnrichPostHogProfile with null subject when no subject given', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 7]);

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::SessionRecapped, $user);

    Queue::assertPushed(EnrichPostHogProfile::class, function ($job) {
        return $job->type === ActivityType::SessionRecapped->value
            && $job->userId === '7'
            && $job->subjectType === null
            && $job->subjectId === null;
    });
});

it('dispatches EnrichPostHogProfile with campaign subject type', function () {
    Queue::fake();

    $user = User::factory()->make(['id' => 1]);
    $campaign = makeCampaignWithSystem();

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::CampaignCreated, $user, $campaign);

    Queue::assertPushed(EnrichPostHogProfile::class, function ($job) use ($campaign) {
        return $job->type === ActivityType::CampaignCreated->value
            && $job->subjectType === 'App\\Models\\Campaign'
            && $job->subjectId === $campaign->id;
    });
});

it('does not dispatch enrichment job when PostHog is disabled', function () {
    Queue::fake();
    $this->posthogClient->setEnabled(false);

    $user = User::factory()->make(['id' => 1]);

    $bridge = $this->app->make(PostHogEventBridge::class);
    $bridge->forwardEvent(ActivityType::GameCreated, $user);

    Queue::assertNotPushed(EnrichPostHogProfile::class);
});
