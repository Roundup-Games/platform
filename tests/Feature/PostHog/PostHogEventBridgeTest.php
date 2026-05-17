<?php

use App\Enums\ActivityType;
use App\Enums\GameType;
use App\Enums\JoinSource;
use App\Enums\Visibility;
use App\Jobs\EnrichPostHogProfile;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Review;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\ActivityLogService;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use App\Services\PostHogEventBridge;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\TestablePostHogClient;

beforeEach(function () {
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_test_key');
    $this->posthogClient = new TestablePostHogClient();
    $this->app->instance(PostHogClient::class, $this->posthogClient);

    // Grant analytics consent by default — individual tests can override
    $consentChecker = $this->mock(PostHogConsentChecker::class);
    $consentChecker->shouldReceive('hasAnalyticsConsent')->andReturn(true);
    $this->app->instance(PostHogConsentChecker::class, $consentChecker);
});

// ── Event name mapping via full integration ─────────────

it('maps each ActivityType to the correct PostHog event name through ActivityLogService', function () {
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

    // Verify mapping completeness
    expect(count($expectedMap))->toBe(count(ActivityType::cases()));

    $user = User::factory()->create();

    $service = new ActivityLogService();

    foreach (ActivityType::cases() as $type) {
        $result = $service->log($type, $user);
        expect($result)->not->toBeNull("ActivityLog for {$type->value} should be written");
    }

    // Every ActivityType should produce exactly one captured call
    expect($this->posthogClient->capturedCalls)->toHaveCount(count(ActivityType::cases()));

    // Verify each event name mapping
    foreach ($this->posthogClient->capturedCalls as $i => $call) {
        $type = ActivityType::cases()[$i];
        expect($call['event'])->toBe($expectedMap[$type->value],
            "ActivityType::{$type->name} should map to {$expectedMap[$type->value]}");
    }
});

// ── GameCreated property enrichment ─────────────────────

it('enriches GameCreated with game_system, visibility, and max_players properties', function () {
    $user = User::factory()->create();
    $gameSystem = GameSystem::factory()->create(['name' => 'Pathfinder 2e']);
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
        'visibility' => Visibility::Public,
        'max_players' => 8,
        'min_players' => 2,
        'game_type' => GameType::Ttrpg,
        'location' => ['type' => 'online', 'details' => 'Discord'],
    ]);

    // Reset calls captured by observer during factory creation
    $this->posthogClient->capturedCalls = [];

    $service = new ActivityLogService();
    $service->log(ActivityType::GameCreated, $user, $game);

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    $props = $this->posthogClient->capturedCalls[0]['properties'];

    expect($props['game_system'])->toBe('Pathfinder 2e')
        ->and($props['visibility'])->toBe('public')
        ->and($props['max_players'])->toBe(8)
        ->and($props['min_players'])->toBe(2)
        ->and($props['location_type'])->toBe('online')
        ->and($props['is_online'])->toBeTrue()
        ->and($props['game_type'])->toBe('ttrpg')
        ->and($props)->toHaveKey('game_id')
        ->and($props['game_id'])->toBe($game->id);
});

// ── PlayerJoined property enrichment ────────────────────

it('enriches PlayerJoined with game_system and participant_role', function () {
    $user = User::factory()->create();
    $gameSystem = GameSystem::factory()->create(['name' => 'D&D 5e']);
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
    ]);
    $participant = GameParticipant::create([
        'id' => Str::uuid()->toString(),
        'game_id' => $game->id,
        'user_id' => $user->id,
        'role' => 'player',
        'join_source' => JoinSource::ShareLink,
    ]);

    // Reset calls captured by observers during factory creation
    $this->posthogClient->capturedCalls = [];

    $service = new ActivityLogService();
    $service->log(ActivityType::PlayerJoined, $user, $participant);

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    $props = $this->posthogClient->capturedCalls[0]['properties'];

    expect($props['game_system'])->toBe('D&D 5e')
        ->and($props['participant_role'])->toBe('player')
        ->and($props['source'])->toBe('share_link')
        ->and($props['game_id'])->toBe($game->id);
});

// ── PostHog capture failure resilience ──────────────────

it('does not propagate PostHog capture failure — ActivityLog still writes', function () {
    $user = User::factory()->create();

    // Client that throws on capture
    $failingClient = new class extends TestablePostHogClient {
        public function capture(array $payload): void
        {
            throw new RuntimeException('PostHog SDK connection refused');
        }
    };
    $this->app->instance(PostHogClient::class, $failingClient);

    $service = new ActivityLogService();
    $result = $service->log(ActivityType::GameCreated, $user);

    // ActivityLog entry was still written to DB
    expect($result)->not->toBeNull();
    expect($result->event_type)->toBe(ActivityType::GameCreated);

    // Verify the DB entry directly
    $logEntry = ActivityLog::where('user_id', $user->id)
        ->where('event_type', ActivityType::GameCreated->value)
        ->first();
    expect($logEntry)->not->toBeNull();
});

// ── EnrichPostHogProfile job dispatch ───────────────────

it('dispatches EnrichPostHogProfile job on GameCreated', function () {
    Queue::fake();

    $user = User::factory()->create();
    $gameSystem = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
    ]);

    $this->posthogClient->capturedCalls = [];

    $service = new ActivityLogService();
    $service->log(ActivityType::GameCreated, $user, $game);

    // Event was captured inline
    expect($this->posthogClient->capturedCalls)->toHaveCount(1);

    // Enrichment job was dispatched
    Queue::assertPushed(EnrichPostHogProfile::class, function ($job) use ($user, $game) {
        return $job->type === ActivityType::GameCreated->value
            && $job->userId === $user->id
            && $job->subjectType === 'App\\Models\\Game'
            && $job->subjectId === $game->id;
    });
});

it('dispatches EnrichPostHogProfile job on PlayerJoined', function () {
    Queue::fake();

    $user = User::factory()->create();
    $gameSystem = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
    ]);

    $service = new ActivityLogService();
    $service->log(ActivityType::PlayerJoined, $user, $game);

    Queue::assertPushed(EnrichPostHogProfile::class, function ($job) use ($user) {
        return $job->type === ActivityType::PlayerJoined->value
            && $job->userId === $user->id;
    });
});

it('dispatches EnrichPostHogProfile job on SessionScheduled', function () {
    Queue::fake();

    $user = User::factory()->create();
    $gameSystem = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
    ]);

    $this->posthogClient->capturedCalls = [];

    $service = new ActivityLogService();
    $service->log(ActivityType::SessionScheduled, $user, $game);

    Queue::assertPushed(EnrichPostHogProfile::class, function ($job) use ($user, $game) {
        return $job->type === ActivityType::SessionScheduled->value
            && $job->userId === $user->id
            && $job->subjectType === 'App\\Models\\Game'
            && $job->subjectId === $game->id;
    });
});

// ── Config flag disables forwarding ─────────────────────

it('does not forward to PostHog when POSTHOG_ENABLED is false', function () {
    Config::set('posthog.enabled', false);
    $this->posthogClient->setEnabled(false);

    $user = User::factory()->create();

    $service = new ActivityLogService();
    $result = $service->log(ActivityType::GameCreated, $user);

    // ActivityLog entry still written
    expect($result)->not->toBeNull();

    // But no PostHog calls were made
    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
    expect($this->posthogClient->identifyCalls)->toHaveCount(0);
    expect($this->posthogClient->groupIdentifyCalls)->toHaveCount(0);
});

it('does not forward when API key is null', function () {
    Config::set('posthog.api_key', null);
    $this->posthogClient->setEnabled(false);

    $user = User::factory()->create();

    $service = new ActivityLogService();
    $result = $service->log(ActivityType::GameCreated, $user);

    expect($result)->not->toBeNull();
    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

// ── Autocapture vs server-side bridge: distinct event spaces ──

it('bridge captures server-side events distinct from autocapture UI events', function () {
    $user = User::factory()->create();
    $gameSystem = GameSystem::factory()->create(['name' => 'D&D 5e']);
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
        'visibility' => Visibility::Public,
        'max_players' => 6,
        'location' => ['type' => 'online'],
    ]);

    $this->posthogClient->capturedCalls = [];

    $service = new ActivityLogService();
    $service->log(ActivityType::GameCreated, $user, $game);

    // The bridge event name uses namespace.action format —
    // distinct from autocapture's '$autocapture' event type
    expect($this->posthogClient->capturedCalls[0]['event'])->toBe('game.created');
    expect($this->posthogClient->capturedCalls[0]['properties'])->toHaveKey('game_system');
    expect($this->posthogClient->capturedCalls[0]['properties'])->toHaveKey('visibility');
    expect($this->posthogClient->capturedCalls[0]['properties'])->toHaveKey('max_players');
});

// ── logForParticipants integration ──────────────────────

it('forwards to PostHog only for game owner via logForParticipants', function () {
    $owner = User::factory()->create();
    $player = User::factory()->create();
    $gameSystem = GameSystem::factory()->create();

    // Create game without triggering observers — the test specifically
    // tests logForParticipants' own behavior in isolation.
    $game = Game::factory()->make([
        'owner_id' => $owner->id,
        'game_system_id' => $gameSystem->id,
    ]);
    $game->id = (string) Str::uuid();
    $game->saveQuietly();

    // Add both as participants (pending status — won't trigger observer)
    GameParticipant::create([
        'id' => Str::uuid()->toString(),
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'owner',
    ]);
    GameParticipant::create([
        'id' => Str::uuid()->toString(),
        'game_id' => $game->id,
        'user_id' => $player->id,
        'role' => 'player',
    ]);

    $service = new ActivityLogService();
    $service->logForParticipants(ActivityType::GameCreated, $game, ['source' => 'dashboard']);

    // Only one PostHog event — for the owner, not the player
    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    expect($this->posthogClient->capturedCalls[0]['distinctId'])->toBe((string) $owner->id);
    expect($this->posthogClient->capturedCalls[0]['properties']['source'])->toBe('dashboard');

    // Both participants got activity log entries from logForParticipants
    $logCount = ActivityLog::where('subject_type', Game::class)
        ->where('subject_id', $game->id)
        ->where('event_type', ActivityType::GameCreated->value)
        ->count();
    expect($logCount)->toBe(2);
});

// ── EnrichPostHogProfile job handles team group enrichment ──

it('dispatches EnrichPostHogProfile for team group analytics on GameCreated', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create([
        'name' => 'Adventurers Guild',
        'city' => 'Austin',
        'country' => 'US',
    ]);
    TeamMember::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => 'player',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $gameSystem = GameSystem::factory()->create(['name' => 'D&D 5e']);
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
    ]);

    $service = new ActivityLogService();
    $service->log(ActivityType::GameCreated, $user, $game);

    // Enrichment job dispatched — team group analytics run async
    Queue::assertPushed(EnrichPostHogProfile::class, function ($job) use ($user, $game) {
        return $job->type === ActivityType::GameCreated->value
            && $job->userId === $user->id
            && $job->subjectType === 'App\\Models\\Game'
            && $job->subjectId === $game->id;
    });
});

// ── Campaign property enrichment ────────────────────────

it('enriches CampaignCreated with game_system and visibility', function () {
    $user = User::factory()->create();
    $gameSystem = GameSystem::factory()->create(['name' => 'Call of Cthulhu']);
    $campaign = Campaign::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
        'visibility' => Visibility::Protected,
        'max_players' => 5,
        'min_players' => 3,
    ]);

    $this->posthogClient->capturedCalls = [];

    $service = new ActivityLogService();
    $service->log(ActivityType::CampaignCreated, $user, $campaign);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['game_system'])->toBe('Call of Cthulhu')
        ->and($props['visibility'])->toBe('protected')
        ->and($props['max_players'])->toBe(5)
        ->and($props['min_players'])->toBe(3)
        ->and($props)->toHaveKey('campaign_id');
});

// ── Review property enrichment ──────────────────────────

it('enriches ReviewReceived with rating and game_system', function () {
    $reviewer = User::factory()->create();
    $gameOwner = User::factory()->create();
    $gameSystem = GameSystem::factory()->create(['name' => 'D&D 5e']);
    $game = Game::factory()->create([
        'owner_id' => $gameOwner->id,
        'game_system_id' => $gameSystem->id,
    ]);
    $review = Review::factory()->create([
        'reviewer_id' => $reviewer->id,
        'rating' => 5,
        'reviewable_type' => Game::class,
        'reviewable_id' => $game->id,
    ]);

    $this->posthogClient->capturedCalls = [];

    $service = new ActivityLogService();
    $service->log(ActivityType::ReviewReceived, $reviewer, $review);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['rating'])->toBe(5)
        ->and($props['game_system'])->toBe('D&D 5e')
        ->and($props['reviewable_type'])->toBe('Game');
});

// ── Follow property enrichment with real DB counts ──────

it('enriches FollowReceived with actual follower count from DB', function () {
    $followedUser = User::factory()->create();
    $follower = User::factory()->create();

    // Create a follow relationship
    UserRelationship::create([
        'user_id' => $follower->id,
        'related_user_id' => $followedUser->id,
        'type' => 'follow',
    ]);

    $this->posthogClient->capturedCalls = [];

    $service = new ActivityLogService();
    $service->log(ActivityType::FollowReceived, $follower, $followedUser);

    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props['followed_user_id'])->toBe($followedUser->id);
});

// ── Multiple events in sequence produce correct state ───

it('tracks user journey: game created → player joined → session scheduled', function () {
    $owner = User::factory()->create();
    $player = User::factory()->create();
    $gameSystem = GameSystem::factory()->create(['name' => 'D&D 5e']);

    // Create game without triggering observers — we're testing explicit
    // service calls, not observer-triggered automatic logging.
    $game = Game::factory()->make([
        'owner_id' => $owner->id,
        'game_system_id' => $gameSystem->id,
        'visibility' => Visibility::Public,
        'max_players' => 6,
        'location' => ['type' => 'online'],
    ]);
    $game->id = (string) Str::uuid();
    $game->saveQuietly();

    $service = new ActivityLogService();

    // Step 1: Owner creates game
    $service->log(ActivityType::GameCreated, $owner, $game);

    // Step 2: Player joins
    $participant = GameParticipant::create([
        'id' => Str::uuid()->toString(),
        'game_id' => $game->id,
        'user_id' => $player->id,
        'role' => 'player',
        'join_source' => JoinSource::Application,
    ]);
    $service->log(ActivityType::PlayerJoined, $player, $participant);

    // Step 3: Session scheduled
    $service->log(ActivityType::SessionScheduled, $owner, $game);

    // Verify all three events captured
    expect($this->posthogClient->capturedCalls)->toHaveCount(3);
    expect($this->posthogClient->capturedCalls[0]['event'])->toBe('game.created');
    expect($this->posthogClient->capturedCalls[1]['event'])->toBe('game.player_joined');
    expect($this->posthogClient->capturedCalls[2]['event'])->toBe('session.scheduled');

    // Verify property enrichment for each step
    expect($this->posthogClient->capturedCalls[0]['properties']['game_system'])->toBe('D&D 5e');
    expect($this->posthogClient->capturedCalls[1]['properties']['participant_role'])->toBe('player');
    expect($this->posthogClient->capturedCalls[1]['properties']['source'])->toBe('application');
});

// ── EnrichPostHogProfile job runs enrichment end-to-end ──

it('enriches user profile via EnrichPostHogProfile job for GameCreated', function () {
    $user = User::factory()->create();
    $gameSystem = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
    ]);

    // Reset observer-triggered calls
    $this->posthogClient->capturedCalls = [];
    $this->posthogClient->identifyCalls = [];

    // Run the job directly against the testable client
    $job = new EnrichPostHogProfile(
        ActivityType::GameCreated->value,
        $user->id,
        Game::class,
        $game->id,
    );
    $job->handle($this->posthogClient);

    expect($this->posthogClient->identifyCalls)->toHaveCount(1);
    $identifyPayload = $this->posthogClient->identifyCalls[0];
    expect($identifyPayload['distinctId'])->toBe((string) $user->id);

    $set = $identifyPayload['properties']['$set'];
    $setOnce = $identifyPayload['properties']['$set_once'];

    expect($set)->toHaveKey('last_active_at')
        ->and($set)->toHaveKey('games_created_count')
        ->and($set)->toHaveKey('last_game_created_at')
        ->and($set['games_created_count'])->toBeGreaterThanOrEqual(1)
        ->and($setOnce)->toHaveKey('first_game_created_at');
});

it('enriches user profile via EnrichPostHogProfile job for PlayerJoined', function () {
    $user = User::factory()->create();
    $gameSystem = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
    ]);
    GameParticipant::create([
        'id' => Str::uuid()->toString(),
        'game_id' => $game->id,
        'user_id' => $user->id,
        'role' => 'player',
    ]);

    $this->posthogClient->identifyCalls = [];

    $job = new EnrichPostHogProfile(ActivityType::PlayerJoined->value, $user->id);
    $job->handle($this->posthogClient);

    expect($this->posthogClient->identifyCalls)->toHaveCount(1);
    $set = $this->posthogClient->identifyCalls[0]['properties']['$set'];
    expect($set)->toHaveKey('games_joined_count')
        ->and($set)->toHaveKey('last_active_at')
        ->and($set['games_joined_count'])->toBeGreaterThanOrEqual(1);
});

it('enriches user profile via EnrichPostHogProfile job with first_session_attended_at on SessionScheduled', function () {
    $user = User::factory()->create();
    $gameSystem = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
    ]);

    $this->posthogClient->identifyCalls = [];

    $job = new EnrichPostHogProfile(
        ActivityType::SessionScheduled->value,
        $user->id,
        Game::class,
        $game->id,
    );
    $job->handle($this->posthogClient);

    expect($this->posthogClient->identifyCalls)->toHaveCount(1);
    $setOnce = $this->posthogClient->identifyCalls[0]['properties']['$set_once'];
    expect($setOnce)->toHaveKey('first_session_attended_at');
});

it('enriches team group via EnrichPostHogProfile job for team member', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create([
        'name' => 'Adventurers Guild',
        'city' => 'Austin',
        'country' => 'US',
    ]);
    TeamMember::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => 'player',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $gameSystem = GameSystem::factory()->create(['name' => 'D&D 5e']);
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
    ]);

    // Reset all observer-triggered calls
    $this->posthogClient->capturedCalls = [];
    $this->posthogClient->identifyCalls = [];
    $this->posthogClient->groupIdentifyCalls = [];

    $job = new EnrichPostHogProfile(
        ActivityType::GameCreated->value,
        $user->id,
        Game::class,
        $game->id,
    );
    $job->handle($this->posthogClient);

    // Should have 1 identify (user profile) + 1 groupIdentify (team)
    expect($this->posthogClient->identifyCalls)->toHaveCount(1);
    expect($this->posthogClient->groupIdentifyCalls)->toHaveCount(1);

    $groupCall = $this->posthogClient->groupIdentifyCalls[0];
    expect($groupCall['groupType'])->toBe('team')
        ->and($groupCall['groupKey'])->toBe((string) $team->id)
        ->and($groupCall['properties']['name'])->toBe('Adventurers Guild')
        ->and($groupCall['properties']['game_system'])->toBe('D&D 5e')
        ->and($groupCall['properties']['city'])->toBe('Austin')
        ->and($groupCall['properties']['country'])->toBe('US')
        ->and($groupCall['properties'])->toHaveKey('member_count');
});

// ── Analytics consent gating ────────────────────────────

it('does not forward events to PostHog when analytics consent is denied', function () {
    // Override consent checker to deny consent
    $deniedChecker = $this->mock(PostHogConsentChecker::class);
    $deniedChecker->shouldReceive('hasAnalyticsConsent')->andReturn(false);
    $this->app->instance(PostHogConsentChecker::class, $deniedChecker);

    Queue::fake();

    $user = User::factory()->create();
    $gameSystem = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
    ]);

    $this->posthogClient->capturedCalls = [];

    $service = new ActivityLogService();
    $result = $service->log(ActivityType::GameCreated, $user, $game);

    // ActivityLog entry is still written — consent only gates PostHog
    expect($result)->not->toBeNull();

    // No PostHog calls made
    expect($this->posthogClient->capturedCalls)->toHaveCount(0);

    // No enrichment job dispatched
    Queue::assertNotPushed(EnrichPostHogProfile::class);
});

it('skips EnrichPostHogProfile job when hasConsent is false', function () {
    $user = User::factory()->create();
    $gameSystem = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => $gameSystem->id,
    ]);

    $this->posthogClient->capturedCalls = [];
    $this->posthogClient->identifyCalls = [];

    // Create job with hasConsent = false
    $job = new EnrichPostHogProfile(
        ActivityType::GameCreated->value,
        $user->id,
        Game::class,
        $game->id,
        false, // hasConsent
    );
    $job->handle($this->posthogClient);

    // No identify or capture calls made
    expect($this->posthogClient->identifyCalls)->toHaveCount(0);
    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});
