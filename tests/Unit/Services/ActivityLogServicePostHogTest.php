<?php

use App\Enums\ActivityType;
use App\Enums\Visibility;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\PostHogClient;
use App\Services\PostHogEventBridge;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Testable ActivityLogService that records PostHog forwarding calls.
 * Overrides forwardToPostHog() to capture arguments instead of resolving
 * the real bridge from the container.
 */
class TestableActivityLogService extends ActivityLogService
{
    public array $posthogCalls = [];

    protected function forwardToPostHog(
        ActivityType $type,
        User $user,
        ?\Illuminate\Database\Eloquent\Model $subject = null,
        array $properties = [],
    ): void {
        $this->posthogCalls[] = [
            'type' => $type,
            'user' => $user,
            'subject' => $subject,
            'properties' => $properties,
        ];
    }
}

beforeEach(function () {
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_test_key');

    $this->posthogClient = new Tests\Helpers\TestablePostHogClient();
    $this->app->instance(PostHogClient::class, $this->posthogClient);
});

// ── log() forwards to PostHog ────────────────────────────

it('forwards event to PostHog after successful log write', function () {
    $user = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => GameSystem::factory()->create()->id,
    ]);

    $service = new TestableActivityLogService();
    $result = $service->log(ActivityType::GameCreated, $user, $game, ['custom' => 'prop']);

    // ActivityLog entry created
    expect($result)->not->toBeNull();
    expect($result->event_type)->toBe(ActivityType::GameCreated);

    // PostHog forwarding was called
    expect($service->posthogCalls)->toHaveCount(1);
    expect($service->posthogCalls[0]['type'])->toBe(ActivityType::GameCreated);
    expect($service->posthogCalls[0]['user']->id)->toBe($user->id);
    expect($service->posthogCalls[0]['subject']->id)->toBe($game->id);
    expect($service->posthogCalls[0]['properties'])->toBe(['custom' => 'prop']);
});

it('does not forward to PostHog when log write fails', function () {
    $user = User::factory()->make(['id' => Str::uuid()]); // Non-persisted user with synthetic ID

    $service = new TestableActivityLogService();

    // This will fail because user doesn't exist in DB (FK constraint or invalid data)
    // But the service catches it and returns null
    $result = $service->log(ActivityType::GameCreated, $user);

    expect($result)->toBeNull();
    // PostHog should NOT be called because the log write failed
    expect($service->posthogCalls)->toHaveCount(0);
});

it('forwards different activity types correctly', function () {
    $user = User::factory()->create();

    $service = new TestableActivityLogService();

    $service->log(ActivityType::FollowReceived, $user);
    $service->log(ActivityType::ReviewReceived, $user);

    expect($service->posthogCalls)->toHaveCount(2);
    expect($service->posthogCalls[0]['type'])->toBe(ActivityType::FollowReceived);
    expect($service->posthogCalls[1]['type'])->toBe(ActivityType::ReviewReceived);
});

it('forwards event with null subject', function () {
    $user = User::factory()->create();

    $service = new TestableActivityLogService();
    $result = $service->log(ActivityType::FollowReceived, $user, null);

    expect($result)->not->toBeNull();
    expect($service->posthogCalls)->toHaveCount(1);
    expect($service->posthogCalls[0]['subject'])->toBeNull();
});

// ── logForParticipants() forwards to PostHog for owner ───

it('forwards participant event to PostHog for game owner', function () {
    $owner = User::factory()->create();
    $participant = User::factory()->create();
    $gameSystem = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $gameSystem->id,
    ]);
    DB::table('game_participants')->insert([
        'id' => Str::uuid()->toString(),
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'created_at' => now(),
    ]);
    DB::table('game_participants')->insert([
        'id' => Str::uuid()->toString(),
        'game_id' => $game->id,
        'user_id' => $participant->id,
        'role' => 'player',
        'created_at' => now(),
    ]);

    $service = new TestableActivityLogService();
    $service->logForParticipants(ActivityType::GameCreated, $game, ['key' => 'value']);

    // PostHog forwarding called once for the owner
    expect($service->posthogCalls)->toHaveCount(1);
    expect($service->posthogCalls[0]['type'])->toBe(ActivityType::GameCreated);
    expect($service->posthogCalls[0]['user']->id)->toBe($owner->id);
    expect($service->posthogCalls[0]['properties'])->toBe(['key' => 'value']);
});

it('forwards participant event to PostHog for campaign owner', function () {
    $owner = User::factory()->create();
    $participant = User::factory()->create();
    $gameSystem = GameSystem::factory()->create();
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $gameSystem->id,
    ]);
    DB::table('campaign_participants')->insert([
        'id' => Str::uuid()->toString(),
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'approved',
        'created_at' => now(),
    ]);
    DB::table('campaign_participants')->insert([
        'id' => Str::uuid()->toString(),
        'campaign_id' => $campaign->id,
        'user_id' => $participant->id,
        'role' => 'player',
        'status' => 'approved',
        'created_at' => now(),
    ]);

    $service = new TestableActivityLogService();
    $service->logForParticipants(ActivityType::CampaignCreated, $campaign);

    expect($service->posthogCalls)->toHaveCount(1);
    expect($service->posthogCalls[0]['type'])->toBe(ActivityType::CampaignCreated);
    expect($service->posthogCalls[0]['user']->id)->toBe($owner->id);
});

it('does not forward when logForParticipants has no participants', function () {
    $owner = User::factory()->create();
    $gameSystem = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $gameSystem->id,
    ]);
    // No participants added — early return

    $service = new TestableActivityLogService();
    $service->logForParticipants(ActivityType::GameCreated, $game);

    expect($service->posthogCalls)->toHaveCount(0);
});

// ── Resilience: PostHog failures don't block writes ──────

it('never blocks log write when PostHog forwarding throws unexpectedly', function () {
    $user = User::factory()->create();

    // Service where forwardToPostHog throws — simulates a catastrophic failure
    // that bypasses the normal try/catch inside forwardToPostHog.
    // The outer catch in log() catches this and returns null, but the DB
    // entry was already committed before the forwarding call.
    $service = new class extends ActivityLogService {
        protected function forwardToPostHog(
            ActivityType $type,
            User $user,
            ?\Illuminate\Database\Eloquent\Model $subject = null,
            array $properties = [],
        ): void {
            throw new RuntimeException('PostHog completely down');
        }
    };

    // The DB write happens before forwardToPostHog, so the exception
    // causes log() to return null (caught by outer try/catch).
    // But the ActivityLog entry was already written to DB.
    $result = $service->log(ActivityType::GameCreated, $user);

    // log() returns null because the exception was caught, but the
    // DB entry exists — verify the write wasn't lost
    $logEntry = ActivityLog::where('user_id', $user->id)
        ->where('event_type', ActivityType::GameCreated)
        ->first();

    expect($logEntry)->not->toBeNull();
    expect($logEntry->event_type)->toBe(ActivityType::GameCreated);
});

// ── Integration: real bridge resolution ──────────────────

it('resolves PostHogEventBridge from container and calls forwardEvent', function () {
    $user = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'game_system_id' => GameSystem::factory()->create()->id,
    ]);

    // Use a testable bridge registered in the container
    $testBridge = new class(app(PostHogClient::class)) extends PostHogEventBridge {
        public array $forwardedCalls = [];

        public function forwardEvent(
            \App\Enums\ActivityType $type,
            User $user,
            ?\Illuminate\Database\Eloquent\Model $subject = null,
            array $properties = [],
        ): void {
            $this->forwardedCalls[] = [
                'type' => $type,
                'user_id' => $user->id,
                'subject_id' => $subject?->getKey(),
            ];
        }
    };

    app()->instance(PostHogEventBridge::class, $testBridge);

    $service = new ActivityLogService();
    $result = $service->log(ActivityType::GameCreated, $user, $game, ['test' => true]);

    expect($result)->not->toBeNull();
    expect($testBridge->forwardedCalls)->toHaveCount(1);
    expect($testBridge->forwardedCalls[0]['type'])->toBe(ActivityType::GameCreated);
    expect($testBridge->forwardedCalls[0]['user_id'])->toBe($user->id);
    expect($testBridge->forwardedCalls[0]['subject_id'])->toBe($game->id);
});

it('logs warning when PostHog bridge resolution fails', function () {
    $user = User::factory()->create();

    // Make PostHogEventBridge unresolvable by binding a broken factory
    app()->bind(PostHogEventBridge::class, function () {
        throw new RuntimeException('Container resolution failed');
    });

    Log::shouldReceive('warning')
        ->once()
        ->with('PostHog event forwarding failed', Mockery::on(function ($ctx) {
            return $ctx['event_type'] === 'game_created'
                && str_contains($ctx['error'], 'Container resolution failed');
        }));

    $service = new ActivityLogService();
    $result = $service->log(ActivityType::GameCreated, $user);

    // DB write still succeeded despite PostHog failure
    expect($result)->not->toBeNull();
});
