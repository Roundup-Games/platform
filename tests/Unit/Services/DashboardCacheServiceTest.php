<?php

namespace Tests\Unit\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Jobs\WarmDashboardCache;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Services\Geohash;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardCacheServiceTest extends TestCase
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

    // ── Cache miss: synchronous fallback ───────────────────────

    #[Test]
    public function get_week_data_returns_real_data_on_miss(): void
    {
        $user = User::factory()->create();

        $result = $this->service->getWeekData($user);

        $this->assertArrayHasKey('days', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertCount(7, $result['days']);
        $this->assertEquals(0, $result['summary']['total']);
        $this->assertEquals(0, $result['summary']['past']);
        $this->assertEquals(0, $result['summary']['upcoming']);
        $this->assertEquals(0, $result['summary']['hosting']);
        $this->assertEquals(0, $result['summary']['playing']);
    }

    #[Test]
    public function get_feed_data_returns_empty_items_on_miss_with_no_follows(): void
    {
        $user = User::factory()->create();

        $result = $this->service->getFeedData($user);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('source', $result);
        $this->assertArrayHasKey('fetched_at', $result);
        $this->assertEquals([], $result['items']);
        $this->assertEquals('friends', $result['source']);
    }

    #[Test]
    public function get_trending_nearby_returns_placeholder_on_miss(): void
    {
        $result = $this->service->getTrendingNearby('u33d');

        $this->assertArrayHasKey('games', $result);
        $this->assertEquals([], $result['games']);
    }

    #[Test]
    public function get_opportunities_returns_placeholder_on_miss(): void
    {
        $user = User::factory()->create();

        $result = $this->service->getOpportunities($user, 'u33d');

        $this->assertArrayHasKey('games', $result);
        $this->assertArrayHasKey('total_available', $result);
        $this->assertEquals([], $result['games']);
        $this->assertEquals(0, $result['total_available']);
    }

    #[Test]
    public function get_contributions_returns_placeholder_on_miss(): void
    {
        $user = User::factory()->create();

        $result = $this->service->getContributions($user);

        $this->assertArrayHasKey('hosted', $result);
        $this->assertArrayHasKey('played', $result);
        $this->assertArrayHasKey('recaps_written', $result);
        $this->assertArrayHasKey('reviews_given', $result);
    }

    // ── Cache miss: data stored in cache ───────────────────────

    #[Test]
    public function get_week_data_stores_result_in_cache(): void
    {
        $user = User::factory()->create();
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $cacheKey = "dashboard:week:{$user->id}:{$weekKey}";

        $this->service->getWeekData($user);

        $cached = Cache::get($cacheKey);
        $this->assertNotNull($cached);
        $this->assertArrayHasKey('days', $cached);
        $this->assertArrayHasKey('summary', $cached);
    }

    #[Test]
    public function get_feed_data_stores_result_in_cache(): void
    {
        $user = User::factory()->create();

        $this->service->getFeedData($user);

        $cached = Cache::get("dashboard:feed:{$user->id}");
        $this->assertNotNull($cached);
        $this->assertArrayHasKey('items', $cached);
    }

    #[Test]
    public function get_trending_nearby_stores_result_in_cache(): void
    {
        $this->service->getTrendingNearby('u33d');

        $cached = Cache::get('dashboard:trending:u33d');
        $this->assertNotNull($cached);
        $this->assertArrayHasKey('games', $cached);
    }

    #[Test]
    public function get_opportunities_stores_result_in_cache(): void
    {
        $user = User::factory()->create();

        $this->service->getOpportunities($user, 'u33d');

        $cached = Cache::get("dashboard:opportunities:{$user->id}:u33d");
        $this->assertNotNull($cached);
    }

    #[Test]
    public function get_contributions_stores_result_in_cache(): void
    {
        $user = User::factory()->create();

        $this->service->getContributions($user);

        $cached = Cache::get("dashboard:contributions:{$user->id}");
        $this->assertNotNull($cached);
    }

    // ── Cache hit: returns cached data without recomputation ───

    #[Test]
    public function get_week_data_returns_cached_data_on_hit(): void
    {
        $user = User::factory()->create();
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $cacheKey = "dashboard:week:{$user->id}:{$weekKey}";
        $expected = ['days' => ['test'], 'summary' => ['total' => 1]];

        Cache::put($cacheKey, $expected, 300);

        $result = $this->service->getWeekData($user);

        $this->assertEquals($expected, $result);
        // No warm job dispatched on cache hit
        Queue::assertNotPushed(WarmDashboardCache::class);
    }

    #[Test]
    public function get_feed_data_returns_cached_data_on_hit(): void
    {
        $user = User::factory()->create();
        $expected = ['items' => ['test_item'], 'source' => 'friends', 'fetched_at' => now()->toISOString()];

        Cache::put("dashboard:feed:{$user->id}", $expected, 900);

        $result = $this->service->getFeedData($user);

        $this->assertEquals($expected, $result);
        Queue::assertNotPushed(WarmDashboardCache::class);
    }

    #[Test]
    public function get_trending_nearby_returns_cached_data_on_hit(): void
    {
        $expected = ['games' => [['id' => 'test']]];

        Cache::put('dashboard:trending:u33d', $expected, 600);

        $result = $this->service->getTrendingNearby('u33d');

        $this->assertEquals($expected, $result);
    }

    #[Test]
    public function get_opportunities_returns_cached_data_on_hit(): void
    {
        $user = User::factory()->create();
        $expected = ['games' => ['test'], 'total_available' => 7];

        Cache::put("dashboard:opportunities:{$user->id}:u33d", $expected, 600);

        $result = $this->service->getOpportunities($user, 'u33d');

        $this->assertEquals($expected, $result);
        Queue::assertNotPushed(WarmDashboardCache::class);
    }

    #[Test]
    public function get_contributions_returns_cached_data_on_hit(): void
    {
        $user = User::factory()->create();
        $expected = ['hosted' => ['count' => 10, 'hours' => 0, 'unique_players' => 0], 'played' => ['count' => 20, 'system_count' => 0], 'campaigns' => null, 'recaps_written' => 0, 'reviews_given' => 0, 'followers' => 0];

        Cache::put("dashboard:contributions:{$user->id}", $expected, 3600);

        $result = $this->service->getContributions($user);

        $this->assertEquals($expected, $result);
        Queue::assertNotPushed(WarmDashboardCache::class);
    }

    // ── Cache miss dispatches WarmDashboardCache job ───────────

    #[Test]
    public function get_week_data_dispatches_warm_job_on_miss(): void
    {
        $user = User::factory()->create();

        $this->service->getWeekData($user);

        Queue::assertPushed(WarmDashboardCache::class, function ($job) use ($user) {
            return $job->userId === (string) $user->id
                && $job->triggerType === 'cache_miss_week';
        });
    }

    #[Test]
    public function get_feed_data_dispatches_warm_job_on_miss(): void
    {
        $user = User::factory()->create();

        $this->service->getFeedData($user);

        Queue::assertPushed(WarmDashboardCache::class, function ($job) use ($user) {
            return $job->userId === (string) $user->id
                && $job->triggerType === 'cache_miss_feed';
        });
    }

    #[Test]
    public function get_opportunities_dispatches_warm_job_on_miss(): void
    {
        $user = User::factory()->create();

        $this->service->getOpportunities($user, 'u33d');

        Queue::assertPushed(WarmDashboardCache::class, function ($job) use ($user) {
            return $job->userId === (string) $user->id
                && $job->triggerType === 'cache_miss_opportunities';
        });
    }

    #[Test]
    public function get_contributions_dispatches_warm_job_on_miss(): void
    {
        $user = User::factory()->create();

        $this->service->getContributions($user);

        Queue::assertPushed(WarmDashboardCache::class, function ($job) use ($user) {
            return $job->userId === (string) $user->id
                && $job->triggerType === 'cache_miss_contributions';
        });
    }

    // ── Invalidation methods ───────────────────────────────────

    #[Test]
    public function invalidate_for_user_clears_week_cache(): void
    {
        $user = User::factory()->create();
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $cacheKey = "dashboard:week:{$user->id}:{$weekKey}";
        Cache::put($cacheKey, ['data' => true], 300);

        $this->service->invalidateForUser((string) $user->id, ['week']);

        $this->assertNull(Cache::get($cacheKey));
    }

    #[Test]
    public function invalidate_for_user_clears_feed_cache(): void
    {
        $user = User::factory()->create();
        Cache::put("dashboard:feed:{$user->id}", ['items' => []], 900);

        $this->service->invalidateForUser((string) $user->id, ['feed']);

        $this->assertNull(Cache::get("dashboard:feed:{$user->id}"));
    }

    #[Test]
    public function invalidate_for_user_clears_contributions_cache(): void
    {
        $user = User::factory()->create();
        Cache::put("dashboard:contributions:{$user->id}", ['data' => true], 3600);

        $this->service->invalidateForUser((string) $user->id, ['contributions']);

        $this->assertNull(Cache::get("dashboard:contributions:{$user->id}"));
    }

    #[Test]
    public function invalidate_for_user_clears_opportunities_with_tracked_keys(): void
    {
        $user = User::factory()->create();

        // Use warm to populate and track the key
        $this->service->warmOpportunities($user, 'u33d');
        $opKey = "dashboard:opportunities:{$user->id}:u33d";
        $this->assertNotNull(Cache::get($opKey));

        $this->service->invalidateForUser((string) $user->id, ['opportunities']);

        $this->assertNull(Cache::get($opKey));
    }

    #[Test]
    public function invalidate_for_user_clears_all_sections_by_default(): void
    {
        $user = User::factory()->create();
        $weekKey = now()->startOfWeek()->format('Y-m-d');

        Cache::put("dashboard:week:{$user->id}:{$weekKey}", ['data' => true], 300);
        Cache::put("dashboard:feed:{$user->id}", ['data' => true], 900);
        Cache::put("dashboard:contributions:{$user->id}", ['data' => true], 3600);

        $this->service->invalidateForUser((string) $user->id);

        $this->assertNull(Cache::get("dashboard:week:{$user->id}:{$weekKey}"));
        $this->assertNull(Cache::get("dashboard:feed:{$user->id}"));
        $this->assertNull(Cache::get("dashboard:contributions:{$user->id}"));
    }

    #[Test]
    public function invalidate_trending_for_geohash_clears_trending_cache(): void
    {
        Cache::put('dashboard:trending:u33d', ['games' => []], 600);

        $this->service->invalidateTrendingForGeohash('u33d');

        $this->assertNull(Cache::get('dashboard:trending:u33d'));
    }

    // ── Game event invalidation ────────────────────────────────

    #[Test]
    public function invalidate_for_game_event_clears_week_for_owner(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
        ]);

        // Populate week cache after creation hooks have fired
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $ownerWeekKey = "dashboard:week:{$owner->id}:{$weekKey}";
        Cache::put($ownerWeekKey, ['data' => true], 300);

        $this->service->invalidateForGameEvent($game, 'updated');

        $this->assertNull(Cache::get($ownerWeekKey));
    }

    #[Test]
    public function invalidate_for_game_event_clears_week_for_approved_participants(): void
    {
        $owner = User::factory()->create();
        $player = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => ParticipantStatus::Approved,
        ]);

        // Populate caches after creation hooks
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $playerWeekKey = "dashboard:week:{$player->id}:{$weekKey}";
        Cache::put($playerWeekKey, ['data' => true], 300);

        $this->service->invalidateForGameEvent($game, 'updated');

        $this->assertNull(Cache::get($playerWeekKey));
    }

    #[Test]
    public function invalidate_for_game_event_clears_trending_for_game_location(): void
    {
        $location = Location::factory()->create([
            'latitude' => 52.5163,
            'longitude' => 13.3777,
        ]);
        $geohash4 = Geohash::tilePrefix(52.5163, 13.3777, 4);

        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'status' => GameStatus::Scheduled,
        ]);

        // Populate after creation hooks
        Cache::put("dashboard:trending:{$geohash4}", ['games' => []], 600);

        $this->service->invalidateForGameEvent($game, 'updated');

        $this->assertNull(Cache::get("dashboard:trending:{$geohash4}"));
    }

    #[Test]
    public function invalidate_for_game_event_skips_trending_without_location(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
            'location_id' => null,
        ]);

        // Pre-populate some trending cache that should NOT be affected
        Cache::put('dashboard:trending:u33d', ['games' => ['unrelated']], 600);

        $this->service->invalidateForGameEvent($game, 'updated');

        // Unrelated trending cache should remain intact
        $this->assertNotNull(Cache::get('dashboard:trending:u33d'));
    }

    // ── Warm methods ───────────────────────────────────────────

    #[Test]
    public function warm_contributions_stores_data_in_cache(): void
    {
        $user = User::factory()->create();

        $result = $this->service->warmContributions($user);

        $this->assertArrayHasKey('hosted', $result);
        $this->assertArrayHasKey('played', $result);
        $cached = Cache::get("dashboard:contributions:{$user->id}");
        $this->assertNotNull($cached);
        $this->assertEquals($result, $cached);
    }

    #[Test]
    public function warm_feed_stores_data_in_cache(): void
    {
        $user = User::factory()->create();

        $result = $this->service->warmFeed($user);

        $this->assertArrayHasKey('items', $result);
        $cached = Cache::get("dashboard:feed:{$user->id}");
        $this->assertNotNull($cached);
        $this->assertEquals($result, $cached);
    }

    #[Test]
    public function warm_opportunities_stores_data_and_tracks_key(): void
    {
        $user = User::factory()->create();

        $result = $this->service->warmOpportunities($user, 'u33d');

        $this->assertArrayHasKey('games', $result);
        $cached = Cache::get("dashboard:opportunities:{$user->id}:u33d");
        $this->assertNotNull($cached);

        // Verify key is tracked
        $trackedKeys = Cache::get("dashboard:opportunities:keys:{$user->id}");
        $this->assertContains("dashboard:opportunities:{$user->id}:u33d", $trackedKeys);
    }

    #[Test]
    public function warm_trending_nearby_returns_game_count(): void
    {
        // Empty tile — should return 0
        $result = $this->service->warmTrendingNearby('u33d');

        $this->assertEquals(0, $result);
        $cached = Cache::get('dashboard:trending:u33d');
        $this->assertNotNull($cached);
        $this->assertCount(0, $cached['games']);
    }

    // ── TTL verification ───────────────────────────────────────

    #[Test]
    public function week_cache_key_includes_start_of_week_date(): void
    {
        $user = User::factory()->create();

        $this->service->getWeekData($user);

        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $this->assertNotNull(Cache::get("dashboard:week:{$user->id}:{$weekKey}"));

        // Different week key should not exist
        $this->assertNull(Cache::get("dashboard:week:{$user->id}:2099-12-31"));
    }

    #[Test]
    public function opportunities_tracks_multiple_geohash_keys(): void
    {
        $user = User::factory()->create();

        $this->service->warmOpportunities($user, 'u33d');
        $this->service->warmOpportunities($user, 'u33e');

        $trackedKeys = Cache::get("dashboard:opportunities:keys:{$user->id}");
        $this->assertCount(2, $trackedKeys);
        $this->assertContains("dashboard:opportunities:{$user->id}:u33d", $trackedKeys);
        $this->assertContains("dashboard:opportunities:{$user->id}:u33e", $trackedKeys);
    }

    #[Test]
    public function opportunities_does_not_duplicate_tracked_keys(): void
    {
        $user = User::factory()->create();

        $this->service->warmOpportunities($user, 'u33d');
        $this->service->warmOpportunities($user, 'u33d');

        $trackedKeys = Cache::get("dashboard:opportunities:keys:{$user->id}");
        $this->assertCount(1, $trackedKeys);
    }
}
