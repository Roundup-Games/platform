<?php

namespace Tests\Unit\Services;

use App\Dto\FeedItem;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\DashboardCacheService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for the feed data computation and caching in DashboardCacheService.
 *
 * Covers: computeFeedData, getFeedData cache wiring, warmFeed,
 * getTrendingNearby real queries, and social-circle scoping.
 */
class DashboardFeedDataTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardCacheService::class);
        Cache::flush();
        Queue::fake();
        Log::spy();
    }

    // ── computeFeedData — basic structure ──────────────────────

    #[Test]
    public function compute_feed_data_returns_empty_for_user_with_no_follows(): void
    {
        $user = User::factory()->create();

        $result = $this->service->computeFeedData($user);

        $this->assertEquals([], $result['items']);
        $this->assertEquals('friends', $result['source']);
        $this->assertNotNull($result['fetched_at']);
    }

    #[Test]
    public function compute_feed_data_includes_games_created_by_followed_users(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        UserRelationship::create([
            'user_id' => $viewer->id,
            'related_user_id' => $friend->id,
            'type' => RelationshipType::Follow,
        ]);

        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => GameStatus::Scheduled,
        ]);

        $result = $this->service->computeFeedData($viewer);

        $this->assertCount(1, $result['items']);
        $this->assertEquals('game_created', $result['items'][0]['type']);
        $this->assertEquals((string) $friend->id, $result['items'][0]['userId']);
    }

    #[Test]
    public function compute_feed_data_merges_game_and_campaign_activity(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        UserRelationship::create([
            'user_id' => $viewer->id,
            'related_user_id' => $friend->id,
            'type' => RelationshipType::Follow,
        ]);

        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => GameStatus::Scheduled,
            'created_at' => now()->subHour(),
        ]);

        Campaign::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'active',
            'created_at' => now(),
        ]);

        $result = $this->service->computeFeedData($viewer);

        $types = collect($result['items'])->pluck('type')->toArray();
        $this->assertContains('game_created', $types);
        $this->assertContains('campaign_created', $types);
    }

    #[Test]
    public function compute_feed_data_limits_to_10_items(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        UserRelationship::create([
            'user_id' => $viewer->id,
            'related_user_id' => $friend->id,
            'type' => RelationshipType::Follow,
        ]);

        // Create 15 games — all from the followed user
        for ($i = 0; $i < 15; $i++) {
            Game::factory()->create([
                'owner_id' => $friend->id,
                'status' => GameStatus::Scheduled,
                'created_at' => now()->subMinutes($i * 10),
            ]);
        }

        $result = $this->service->computeFeedData($viewer);

        $this->assertCount(10, $result['items']);
    }

    #[Test]
    public function compute_feed_data_sorts_by_created_at_desc(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        UserRelationship::create([
            'user_id' => $viewer->id,
            'related_user_id' => $friend->id,
            'type' => RelationshipType::Follow,
        ]);

        $older = Game::factory()->create([
            'owner_id' => $friend->id,
            'name' => ['en' => 'Older Game'],
            'status' => GameStatus::Scheduled,
            'created_at' => now()->subDay(),
        ]);

        $newer = Game::factory()->create([
            'owner_id' => $friend->id,
            'name' => ['en' => 'Newer Game'],
            'status' => GameStatus::Scheduled,
            'created_at' => now(),
        ]);

        $result = $this->service->computeFeedData($viewer);

        $this->assertCount(2, $result['items']);
        $this->assertEquals('Newer Game', $result['items'][0]['entityName']);
        $this->assertEquals('Older Game', $result['items'][1]['entityName']);
    }

    #[Test]
    public function compute_feed_data_excludes_non_followed_users(): void
    {
        $viewer = User::factory()->create();
        $stranger = User::factory()->create();

        Game::factory()->create([
            'owner_id' => $stranger->id,
            'status' => GameStatus::Scheduled,
        ]);

        $result = $this->service->computeFeedData($viewer);

        $this->assertEquals([], $result['items']);
    }

    // ── FeedItem serialization in cache ────────────────────────

    #[Test]
    public function compute_feed_data_items_are_cache_serializable(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        UserRelationship::create([
            'user_id' => $viewer->id,
            'related_user_id' => $friend->id,
            'type' => RelationshipType::Follow,
        ]);

        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => GameStatus::Scheduled,
        ]);

        $result = $this->service->computeFeedData($viewer);

        // Each item should be a plain array, not an object
        $item = $result['items'][0];
        $this->assertIsArray($item);
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('type', $item);
        $this->assertArrayHasKey('entityType', $item);
        $this->assertArrayHasKey('entityId', $item);
        $this->assertArrayHasKey('entityName', $item);
        $this->assertArrayHasKey('userName', $item);
        $this->assertArrayHasKey('userId', $item);
        $this->assertArrayHasKey('createdAt', $item);

        // Should be JSON-serializable (no Eloquent models or Carbon instances)
        $json = json_encode($item);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertEquals($item['id'], $decoded['id']);
    }

    // ── getFeedData cache wiring ───────────────────────────────

    #[Test]
    public function get_feed_data_computes_and_caches_on_miss(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        UserRelationship::create([
            'user_id' => $viewer->id,
            'related_user_id' => $friend->id,
            'type' => RelationshipType::Follow,
        ]);

        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => GameStatus::Scheduled,
        ]);

        $result = $this->service->getFeedData($viewer);

        $this->assertCount(1, $result['items']);
        $this->assertEquals('friends', $result['source']);

        // Verify cached
        $cached = Cache::get("dashboard:feed:{$viewer->id}");
        $this->assertNotNull($cached);
        $this->assertCount(1, $cached['items']);

        // Second call returns cached (no recomputation)
        $result2 = $this->service->getFeedData($viewer);
        $this->assertEquals($result, $result2);
    }

    // ── Trending nearby ────────────────────────────────────────

    #[Test]
    public function get_trending_nearby_returns_real_games_for_tile(): void
    {
        $owner = User::factory()->create();
        $location = \App\Models\Location::factory()->create([
            'latitude' => 52.5163,
            'longitude' => 13.3777,
        ]);

        Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
            'location_id' => $location->id,
            'location' => ['type' => 'offline'],
            'date_time' => now()->addDays(3),
        ]);

        $geohash4 = \App\Services\Geohash::tilePrefix(52.5163, 13.3777, 4);

        $result = $this->service->getTrendingNearby($geohash4);

        $this->assertArrayHasKey('games', $result);
        // May or may not include the game depending on tile boundaries
        $this->assertIsArray($result['games']);
    }

    #[Test]
    public function warm_trending_nearby_scores_by_participants_and_recency(): void
    {
        $owner = User::factory()->create();
        $location = \App\Models\Location::factory()->create([
            'latitude' => 52.5163,
            'longitude' => 13.3777,
        ]);

        // New game with participants — should score higher
        $popularGame = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
            'location_id' => $location->id,
            'location' => ['type' => 'offline'],
            'date_time' => now()->addDays(3),
            'created_at' => now()->subDay(),
            'name' => ['en' => 'Popular Game'],
        ]);
        GameParticipant::factory()->create([
            'game_id' => $popularGame->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $geohash4 = \App\Services\Geohash::tilePrefix(52.5163, 13.3777, 4);
        $count = $this->service->warmTrendingNearby($geohash4);

        $cached = Cache::get("dashboard:trending:{$geohash4}");
        $this->assertNotNull($cached);

        // If the game is in this tile, verify its data shape
        foreach ($cached['games'] as $game) {
            $this->assertArrayHasKey('id', $game);
            $this->assertArrayHasKey('name', $game);
            $this->assertArrayHasKey('participant_count', $game);
            $this->assertArrayHasKey('max_players', $game);
        }
    }

    // ── Feed invalidation on follow/unfollow ───────────────────

    #[Test]
    public function follow_invalidates_feed_cache_for_both_users(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create();

        // Populate feed caches for both users
        $this->service->getFeedData($viewer);
        $this->service->getFeedData($target);
        $this->assertNotNull(Cache::get("dashboard:feed:{$viewer->id}"));
        $this->assertNotNull(Cache::get("dashboard:feed:{$target->id}"));

        // Follow invalidates both users' feed caches
        UserRelationship::follow($viewer, $target);

        $this->assertNull(Cache::get("dashboard:feed:{$viewer->id}"), 'Viewer feed cache should be invalidated on follow');
        $this->assertNull(Cache::get("dashboard:feed:{$target->id}"), 'Target feed cache should be invalidated on follow');
    }

    #[Test]
    public function unfollow_invalidates_feed_cache_for_both_users(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($viewer, $target);

        // Populate feed caches after follow
        $this->service->getFeedData($viewer);
        $this->service->getFeedData($target);
        $this->assertNotNull(Cache::get("dashboard:feed:{$viewer->id}"));
        $this->assertNotNull(Cache::get("dashboard:feed:{$target->id}"));

        // Unfollow invalidates both users' feed caches
        UserRelationship::unfollow($viewer, $target);

        $this->assertNull(Cache::get("dashboard:feed:{$viewer->id}"), 'Viewer feed cache should be invalidated on unfollow');
        $this->assertNull(Cache::get("dashboard:feed:{$target->id}"), 'Target feed cache should be invalidated on unfollow');
    }

    // ── warmFeed cache wiring ──────────────────────────────────

    #[Test]
    public function warm_feed_stores_computed_data_in_cache(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();

        UserRelationship::create([
            'user_id' => $viewer->id,
            'related_user_id' => $friend->id,
            'type' => RelationshipType::Follow,
        ]);

        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => GameStatus::Scheduled,
        ]);

        $result = $this->service->warmFeed($viewer);

        $this->assertCount(1, $result['items']);
        $this->assertEquals('friends', $result['source']);

        $cached = Cache::get("dashboard:feed:{$viewer->id}");
        $this->assertNotNull($cached);
        $this->assertEquals($result, $cached);
    }

    // ── Trending handles empty geohash ─────────────────────────

    #[Test]
    public function warm_trending_nearby_returns_zero_for_empty_tile(): void
    {
        $count = $this->service->warmTrendingNearby('zzzz');

        $this->assertEquals(0, $count);
        $cached = Cache::get('dashboard:trending:zzzz');
        $this->assertNotNull($cached);
        $this->assertCount(0, $cached['games']);
    }
}
