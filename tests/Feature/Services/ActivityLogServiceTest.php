<?php

use App\Enums\ActivityType;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\ActivityLog;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->service = new ActivityLogService;
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
        // Clear any rows first so the CHECK constraint can be added (PostgreSQL
        // rejects ADD CONSTRAINT when existing rows would violate it). Both the
        // rows and the constraint roll back with DatabaseTransactions (PostgreSQL
        // DDL is transactional).
        ActivityLog::query()->delete();

        Log::shouldReceive('warning')
            ->once()
            ->with('Activity log write failed', Mockery::on(fn ($ctx) => $ctx['event_type'] === 'review_received'
                && $ctx['user_id'] === $this->user->id
            ));

        // Add a CHECK constraint that rejects the insert, forcing a real DB error
        DB::statement("ALTER TABLE activity_logs ADD CONSTRAINT test_fail_check CHECK (event_type != 'review_received')");

        $result = $this->service->log(ActivityType::ReviewReceived, $this->user);

        expect($result)->toBeNull();
    });
});

describe('logForParticipants()', function () {
    it('logs for all game participants', function () {
        $game = Game::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        GameParticipant::create(['game_id' => $game->id, 'user_id' => $user1->id, 'role' => ParticipantRole::Owner->value, 'status' => ParticipantStatus::Approved->value]);
        GameParticipant::create(['game_id' => $game->id, 'user_id' => $user2->id, 'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value]);

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
        // Clear observer-generated logs so the count assertion is isolated to
        // this test's logForParticipants() call (matches the sibling test above).
        ActivityLog::query()->delete();

        $user = User::factory()->create();

        $this->service->logForParticipants(ActivityType::FollowReceived, $user);

        expect(ActivityLog::count())->toBe(0);
    });
});
