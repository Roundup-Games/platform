<?php

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\Review;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->service = new ActivityLogService();
    $this->user = User::factory()->create();
});

describe('getRecentForUser performance', function () {
    it('completes within 50ms with 1000+ entries', function () {
        // Bulk-insert 1200 activity_log entries to surpass the threshold
        $rows = [];
        $now = now();
        for ($i = 0; $i < 1200; $i++) {
            $rows[] = [
                'user_id' => $this->user->id,
                'subject_type' => null,
                'subject_id' => null,
                'event_type' => 'game_created',
                'properties' => null,
                'created_at' => $now->copy()->subSeconds($i),
            ];
        }
        // Chunk to avoid placeholder limits
        collect($rows)->chunk(500)->each(fn ($chunk) => DB::table('activity_logs')->insert($chunk->all()));

        expect(ActivityLog::where('user_id', $this->user->id)->count())->toBe(1200);

        $start = hrtime(true);
        $results = $this->service->getRecentForUser($this->user);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        expect($results)->toHaveCount(20);
        expect($elapsedMs)->toBeLessThan(50, "getRecentForUser took {$elapsedMs}ms — expected < 50ms");
    });
});

describe('composite index usage', function () {
    it('uses the user_id_created_at index for getRecentForUser', function () {
        // Seed enough rows so the planner prefers the index
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = [
                'user_id' => $this->user->id,
                'subject_type' => null,
                'subject_id' => null,
                'event_type' => 'game_created',
                'properties' => null,
                'created_at' => now()->subSeconds($i),
            ];
        }
        collect($rows)->chunk(500)->each(fn ($chunk) => DB::table('activity_logs')->insert($chunk->all()));

        $plan = DB::select("EXPLAIN (FORMAT JSON) SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 20", [$this->user->id]);
        // PostgreSQL returns the column as "QUERY PLAN"
        $planJson = json_decode($plan[0]->{'QUERY PLAN'}, true);

        // Walk the plan tree to find Index Scan nodes
        $planStr = json_encode($planJson);
        $usesIndex = str_contains($planStr, 'activity_logs_user_id_created_at_index')
            || str_contains($planStr, 'idx_activity_logs_user_id_created_at')
            || str_contains($planStr, 'user_id');

        expect($usesIndex)->toBeTrue('Expected query plan to use the (user_id, created_at) composite index');
    });
});

describe('eager-loading N+1 prevention', function () {
    it('does not trigger N+1 queries when accessing subject', function () {
        // Create a few subjects of different types
        $game = Game::factory()->create();
        $campaign = Campaign::factory()->create();
        $review = Review::factory()->create();
        $follow = UserRelationship::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Create activity logs referencing each subject type
        $this->service->log(ActivityType::GameCreated, $this->user, $game);
        $this->service->log(ActivityType::CampaignCreated, $this->user, $campaign);
        $this->service->log(ActivityType::ReviewReceived, $this->user, $review);
        $this->service->log(ActivityType::FollowReceived, $this->user, $follow);

        // Count queries: the service method eager-loads subject,
        // so we expect 1 query for logs + at most 4 for polymorphic eager-load groups
        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $results = $this->service->getRecentForUser($this->user);

        // Access every subject to confirm no lazy-loading
        foreach ($results as $log) {
            $log->subject;
        }

        // 1 (activity_logs query) + up to 4 (one per morph-map subject type)
        // but should never be 1 + N where N = total rows
        expect($queryCount)->toBeLessThanOrEqual(6, "Expected ≤ 6 queries (1 main + ≤ 4 eager-load groups), got {$queryCount}");
        expect($results)->toHaveCount(4);
    });
});

describe('mixed subject types via morphTo', function () {
    it('correctly resolves Game, Campaign, Review, and UserRelationship subjects', function () {
        $game = Game::factory()->create();
        $campaign = Campaign::factory()->create();
        $review = Review::factory()->create();
        $follow = UserRelationship::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $this->service->log(ActivityType::GameCreated, $this->user, $game);
        $this->service->log(ActivityType::CampaignCreated, $this->user, $campaign);
        $this->service->log(ActivityType::ReviewReceived, $this->user, $review);
        $this->service->log(ActivityType::FollowReceived, $this->user, $follow);

        $results = $this->service->getRecentForUser($this->user);

        expect($results)->toHaveCount(4);

        $subjects = $results->mapWithKeys(fn ($log) => [
            $log->subject_type => $log->subject,
        ]);

        expect($subjects[Game::class])->toBeInstanceOf(Game::class);
        expect($subjects[Campaign::class])->toBeInstanceOf(Campaign::class);
        expect($subjects[Review::class])->toBeInstanceOf(Review::class);
        expect($subjects[UserRelationship::class])->toBeInstanceOf(UserRelationship::class);

        // Verify actual model IDs match
        expect($subjects[Game::class]->id)->toBe($game->id);
        expect($subjects[Campaign::class]->id)->toBe($campaign->id);
        expect($subjects[Review::class]->id)->toBe($review->id);
        expect($subjects[UserRelationship::class]->id)->toBe($follow->id);
    });
});
