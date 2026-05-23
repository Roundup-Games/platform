<?php

namespace Tests\Unit;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Services\DashboardCacheService;
use App\Services\Geohash;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardInvalidationTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheService = app(DashboardCacheService::class);
        // Suppress log noise in tests
        Log::spy();
    }

    // ── 1. Game events (created / updated / deleted) ──────────

    #[Test]
    public function game_created_invalidates_week_for_owner(): void
    {
        $owner = User::factory()->create();

        // Pre-populate week cache directly
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $cacheKey = "dashboard:week:{$owner->id}:{$weekKey}";
        Cache::put($cacheKey, ['games_this_week' => []], 300);
        $this->assertNotNull(Cache::get($cacheKey));

        // Create a game — should trigger invalidation via saved hook
        Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
        ]);

        // Week cache should be cleared for owner
        $this->assertNull(Cache::get($cacheKey));
    }

    #[Test]
    public function game_updated_invalidates_week_for_owner(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
        ]);

        // Pre-populate week cache (after game creation already cleared it)
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $cacheKey = "dashboard:week:{$owner->id}:{$weekKey}";
        Cache::put($cacheKey, ['data' => true], 300);

        // Update the game — should trigger saved hook
        $game->update(['name' => ['en' => 'Updated Game Name']]);

        $this->assertNull(Cache::get($cacheKey));
    }

    #[Test]
    public function game_deleted_invalidates_week_for_owner_and_approved_participants(): void
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

        // Pre-populate week cache after all model hooks have fired
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $ownerKey = "dashboard:week:{$owner->id}:{$weekKey}";
        $playerKey = "dashboard:week:{$player->id}:{$weekKey}";
        Cache::put($ownerKey, ['data' => true], 300);
        Cache::put($playerKey, ['data' => true], 300);

        $game->delete();

        $this->assertNull(Cache::get($ownerKey));
        $this->assertNull(Cache::get($playerKey));
    }

    #[Test]
    public function game_event_invalidates_trending_for_game_location(): void
    {
        $location = $this->createLocation();
        $geohash4 = $location->geohash_4;
        $owner = User::factory()->create();

        // Pre-populate trending cache
        Cache::put("dashboard:trending:{$geohash4}", ['games' => []], 600);

        Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
            'location_id' => $location->id,
        ]);

        // Trending for the game's geohash should be cleared
        $this->assertNull(Cache::get("dashboard:trending:{$geohash4}"));
    }

    // ── 2. Follow / unfollow ──────────────────────────────────

    #[Test]
    public function follow_invalidates_feed_for_both_users(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        // Pre-populate feed cache for both users
        Cache::put("dashboard:feed:{$user->id}", ['items' => []], 900);
        Cache::put("dashboard:feed:{$target->id}", ['items' => []], 900);

        UserRelationship::follow($user, $target);

        $this->assertNull(Cache::get("dashboard:feed:{$user->id}"));
        $this->assertNull(Cache::get("dashboard:feed:{$target->id}"));
    }

    #[Test]
    public function unfollow_invalidates_feed_for_both_users(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        // Create follow relationship first
        UserRelationship::follow($user, $target);

        // Pre-populate feed cache after follow invalidation
        Cache::put("dashboard:feed:{$user->id}", ['items' => []], 900);
        Cache::put("dashboard:feed:{$target->id}", ['items' => []], 900);

        UserRelationship::unfollow($user, $target);

        $this->assertNull(Cache::get("dashboard:feed:{$user->id}"));
        $this->assertNull(Cache::get("dashboard:feed:{$target->id}"));
    }

    // ── 3. Player join / leave ─────────────────────────────────

    #[Test]
    public function player_join_invalidates_week_for_player(): void
    {
        $player = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Scheduled,
        ]);

        // Pre-populate week cache
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $cacheKey = "dashboard:week:{$player->id}:{$weekKey}";
        Cache::put($cacheKey, ['data' => true], 300);

        // Player joins the game
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->assertNull(Cache::get($cacheKey));
    }

    #[Test]
    public function player_leave_invalidates_week_for_player(): void
    {
        $player = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Scheduled,
        ]);
        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => ParticipantStatus::Approved,
        ]);

        // Pre-populate week cache after join invalidation
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $cacheKey = "dashboard:week:{$player->id}:{$weekKey}";
        Cache::put($cacheKey, ['data' => true], 300);

        // Player leaves
        $participant->delete();

        $this->assertNull(Cache::get($cacheKey));
    }

    // ── 4. User preference change (opportunities) ──────────────

    #[Test]
    public function opportunities_invalidation_clears_tracked_keys(): void
    {
        $user = User::factory()->create();
        $geohash4 = 'u33d';

        // Use the service to populate, which tracks the key
        $this->cacheService->warmOpportunities($user, $geohash4);
        $opKey = "dashboard:opportunities:{$user->id}:{$geohash4}";
        $this->assertNotNull(Cache::get($opKey));

        // Invalidate via the service
        $this->cacheService->invalidateForUser($user->id, ['opportunities']);

        $this->assertNull(Cache::get($opKey));
    }

    // ── 5. Recap written ───────────────────────────────────────

    #[Test]
    public function recap_update_invalidates_contributions_for_owner(): void
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Completed,
        ]);

        // Pre-populate contributions cache (after game creation hooks have fired)
        Cache::put("dashboard:contributions:{$host->id}", ['data' => true], 3600);

        // Update recap — the saved hook should detect recap change
        $game->update(['recap' => 'Great session everyone!']);

        $this->assertNull(Cache::get("dashboard:contributions:{$host->id}"));
    }

    #[Test]
    public function recap_update_invalidates_contributions_for_approved_participants(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Completed,
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => ParticipantStatus::Approved,
        ]);

        // Pre-populate contributions cache for player
        Cache::put("dashboard:contributions:{$player->id}", ['data' => true], 3600);

        $game->update(['recap' => 'Great session everyone!']);

        $this->assertNull(Cache::get("dashboard:contributions:{$player->id}"));
    }

    // ── 6. Location change ─────────────────────────────────────

    #[Test]
    public function location_change_invalidates_trending_for_old_and_new_geohash(): void
    {
        $oldLocation = $this->createLocation(52.5163, 13.3777); // Berlin
        $newLocation = $this->createLocation(48.8566, 2.3522);  // Paris

        $oldGeohash = $oldLocation->geohash_4;
        $newGeohash = $newLocation->geohash_4;

        // Pre-populate trending caches
        Cache::put("dashboard:trending:{$oldGeohash}", ['games' => []], 600);
        Cache::put("dashboard:trending:{$newGeohash}", ['games' => []], 600);

        // Simulate location change invalidation
        $this->cacheService->invalidateTrendingForGeohash($oldGeohash);
        $this->cacheService->invalidateTrendingForGeohash($newGeohash);

        $this->assertNull(Cache::get("dashboard:trending:{$oldGeohash}"));
        $this->assertNull(Cache::get("dashboard:trending:{$newGeohash}"));
    }

    #[Test]
    public function location_change_invalidates_opportunities_via_tracking(): void
    {
        $user = User::factory()->create();
        $location = $this->createLocation();
        $geohash4 = $location->geohash_4;

        // Use service to populate (tracks key)
        $this->cacheService->warmOpportunities($user, $geohash4);
        $opKey = "dashboard:opportunities:{$user->id}:{$geohash4}";
        $this->assertNotNull(Cache::get($opKey));

        // Invalidate for location change
        $this->cacheService->invalidateForUser($user->id, ['opportunities']);

        $this->assertNull(Cache::get($opKey));
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function createLocation(float $lat = 52.5163, float $lng = 13.3777): Location
    {
        return Location::factory()->create([
            'latitude' => $lat,
            'longitude' => $lng,
            'geohash_4' => Geohash::tilePrefix($lat, $lng, 4),
        ]);
    }
}
