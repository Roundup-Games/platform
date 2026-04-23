<?php

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->service = new ActivityLogService();
    $this->user = User::factory()->create();
});

describe('log()', function () {
    it('creates an activity log entry', function () {
        $log = $this->service->log(
            ActivityType::FollowReceived,
            $this->user,
        );

        expect($log)->not->toBeNull();
        expect($log->user_id)->toBe($this->user->id);
        expect($log->event_type)->toBe(ActivityType::FollowReceived);
        expect($log->subject_type)->toBeNull();
        expect($log->subject_id)->toBeNull();
        expect($log->properties)->toBeNull();
        expect($log->created_at)->not->toBeNull();
    });

    it('creates entry with subject and properties', function () {
        $game = Game::factory()->create();
        $props = ['detail' => 'Catan night'];

        $log = $this->service->log(
            ActivityType::GameCreated,
            $this->user,
            $game,
            $props,
        );

        expect($log)->not->toBeNull();
        expect($log->subject_type)->toBe(Game::class);
        expect($log->subject_id)->toBe($game->id);
        expect($log->event_type)->toBe(ActivityType::GameCreated);
        expect($log->properties)->toBe(['detail' => 'Catan night']);
    });

    it('returns null when logging fails', function () {
        Log::spy();

        // Drop the activity_logs table to force a real DB error
        DB::statement('DROP TABLE activity_logs');

        $result = $this->service->log(ActivityType::ReviewReceived, $this->user);

        expect($result)->toBeNull();

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Activity log write failed', \Mockery::on(fn ($ctx) =>
                $ctx['event_type'] === 'review_received'
                && $ctx['user_id'] === $this->user->id
            ));
    });
});

describe('getRecentForUser()', function () {
    it('returns entries ordered by created_at descending', function () {
        // Create logs with explicit timestamps
        ActivityLog::insert([
            [
                'user_id' => $this->user->id,
                'event_type' => 'game_created',
                'created_at' => now()->subHours(2),
            ],
            [
                'user_id' => $this->user->id,
                'event_type' => 'follow_received',
                'created_at' => now()->subHour(),
            ],
            [
                'user_id' => $this->user->id,
                'event_type' => 'review_received',
                'created_at' => now(),
            ],
        ]);

        $results = $this->service->getRecentForUser($this->user);

        expect($results)->toHaveCount(3);
        expect($results->first()->event_type)->toBe(ActivityType::ReviewReceived);
        expect($results->last()->event_type)->toBe(ActivityType::GameCreated);
    });

    it('respects the limit parameter', function () {
        for ($i = 0; $i < 25; $i++) {
            ActivityLog::insert([
                'user_id' => $this->user->id,
                'event_type' => 'game_created',
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $results = $this->service->getRecentForUser($this->user, 10);

        expect($results)->toHaveCount(10);
    });

    it('only returns entries for the given user', function () {
        $otherUser = User::factory()->create();

        ActivityLog::insert([
            ['user_id' => $this->user->id, 'event_type' => 'game_created', 'created_at' => now()],
            ['user_id' => $otherUser->id, 'event_type' => 'follow_received', 'created_at' => now()],
        ]);

        $results = $this->service->getRecentForUser($this->user);

        expect($results)->toHaveCount(1);
        expect($results->first()->user_id)->toBe($this->user->id);
    });

    it('eager loads subject relationship', function () {
        $game = Game::factory()->create();

        $this->service->log(ActivityType::GameCreated, $this->user, $game);

        $results = $this->service->getRecentForUser($this->user);

        expect($results)->toHaveCount(1);
        expect($results->first()->relationLoaded('subject'))->toBeTrue();
        expect($results->first()->subject)->toBeInstanceOf(Game::class);
    });
});

describe('logForParticipants()', function () {
    it('logs for all game participants', function () {
        $game = Game::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        GameParticipant::create(['game_id' => $game->id, 'user_id' => $user1->id, 'role' => 'owner', 'status' => 'approved']);
        GameParticipant::create(['game_id' => $game->id, 'user_id' => $user2->id, 'role' => 'player', 'status' => 'approved']);

        // Clear observer-generated logs so we only measure logForParticipants output
        ActivityLog::query()->delete();

        $this->service->logForParticipants(ActivityType::GameCreated, $game, ['name' => 'Test Game']);

        $logs = ActivityLog::where('subject_type', Game::class)
            ->where('subject_id', $game->id)
            ->get();

        expect($logs)->toHaveCount(2);
        expect($logs->pluck('user_id')->sort()->values()->all())
            ->toBe([$user1->id, $user2->id]);
        expect($logs->first()->event_type->value)->toBe('game_created');
    });

    it('does nothing for unsupported subject types', function () {
        $user = User::factory()->create();

        $this->service->logForParticipants(ActivityType::FollowReceived, $user);

        expect(ActivityLog::count())->toBe(0);
    });
});
