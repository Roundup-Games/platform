<?php

namespace Tests\Feature\People;

use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\Geohash;
use App\Services\PeopleDiscoveryService;
use App\Services\ProfileVisibilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NearbyPerformanceTest extends TestCase
{
    use RefreshDatabase;

    // Central Berlin (Mitte) — all candidates share this geohash-4 tile
    private const LAT = 52.5163;
    private const LNG = 13.3777;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ── Helpers ──────────────────────────────────────

    private function createLocationAt(float $lat, float $lng): Location
    {
        return Location::factory()->create([
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }

    private function createUserAt(float $lat, float $lng, array $overrides = []): User
    {
        $location = $this->createLocationAt($lat, $lng);

        return User::factory()->create(array_merge([
            'location_id' => $location->id,
            'profile_complete' => true,
            'is_disabled' => false,
        ], $overrides));
    }

    /**
     * Attach favorite game systems to a user via the pivot table.
     *
     * @param  User  $user
     * @param  GameSystem[]  $systems
     */
    private function attachFavoriteGameSystems(User $user, array $systems): void
    {
        foreach ($systems as $system) {
            DB::table('user_game_system_preferences')->insert([
                'user_id' => $user->id,
                'game_system_id' => $system->id,
                'preference_type' => 'favorite',
            ]);
        }
    }

    /**
     * Attach favorite vibes to a user via the pivot table.
     *
     * @param  User  $user
     * @param  string[]  $vibeValues  e.g. ['atmospheric', 'story-rich']
     */
    private function attachFavoriteVibes(User $user, array $vibeValues): void
    {
        foreach ($vibeValues as $value) {
            DB::table('user_vibe_preferences')->insert([
                'user_id' => $user->id,
                'vibe_preference_value' => $value,
                'preference_type' => 'favorite',
            ]);
        }
    }

    private function getDiscoveryService(): PeopleDiscoveryService
    {
        return app(PeopleDiscoveryService::class);
    }

    // ── Performance Test ─────────────────────────────

    public function test_discover_completes_under_500ms_with_200_candidates(): void
    {
        // Seed game systems and vibe options
        $gameSystems = GameSystem::factory()->count(20)->create();
        $allVibes = [
            'atmospheric', 'lighthearted', 'serious', 'horror', 'humorous',
            'mature-themes', 'family-friendly', 'character-driven', 'story-rich',
            'rules-light', 'rules-heavy', 'tactical', 'combat-focused',
            'roleplay-heavy', 'exploration', 'puzzle-solving', 'competitive',
            'cooperative', 'new-player-friendly', 'drop-in-friendly',
        ];

        // Create 200 candidate users in the same geohash-4 tile
        // Slight offsets keep them in the same tile but avoid identical coordinates
        for ($i = 0; $i < 200; $i++) {
            $lat = self::LAT + ($i * 0.0001);
            $lng = self::LNG + ($i * 0.00005);
            $candidate = $this->createUserAt($lat, $lng);

            // 3-5 random favorite game systems
            $systemCount = rand(3, 5);
            $pickedSystems = $gameSystems->random($systemCount);
            $this->attachFavoriteGameSystems($candidate, $pickedSystems->all());

            // 2-4 random favorite vibes
            $vibeCount = rand(2, 4);
            $pickedVibes = array_rand(array_flip($allVibes), $vibeCount);
            $pickedVibes = is_array($pickedVibes) ? $pickedVibes : [$pickedVibes];
            $this->attachFavoriteVibes($candidate, $pickedVibes);
        }

        // Create the viewer with known preferences
        $viewerLocation = $this->createLocationAt(self::LAT, self::LNG);
        $viewer = User::factory()->create([
            'location_id' => $viewerLocation->id,
            'profile_complete' => true,
            'is_disabled' => false,
        ]);

        // Give viewer a known set of preferences
        $viewerGameSystems = $gameSystems->take(5);
        $this->attachFavoriteGameSystems($viewer, $viewerGameSystems->all());
        $this->attachFavoriteVibes($viewer, ['atmospheric', 'story-rich', 'cooperative']);

        $service = $this->getDiscoveryService();

        // Measure time and query count
        $start = microtime(true);
        DB::enableQueryLog();

        $result = $service->discover($viewer, self::LAT, self::LNG, 12, 1);

        $elapsed = (microtime(true) - $start) * 1000; // ms
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Assert performance < 500ms
        $this->assertLessThan(
            500,
            $elapsed,
            "discover() took {$elapsed}ms, expected < 500ms with 200 candidates"
        );

        // Assert query count is reasonable for the current implementation.
        // NOTE: ProfileVisibilityResolver calls per-candidate relationship checks
        // (isBlockedBy, hasBlocked, getRelationshipLevel) which create an N+1 pattern.
        // Current baseline is ~6-8 queries per candidate. With 200 candidates this
        // produces ~1000 queries. This assertion catches regressions beyond the
        // current baseline. A future optimization should bulk-load relationship state.
        $this->assertLessThan(
            1500,
            $queries,
            "discover() executed {$queries} queries, expected < 1500 for 200 candidates"
        );

        // Assert pagination is 12 per page
        $paginator = $result['results'];
        $this->assertEquals(12, $paginator->perPage());
        $this->assertEquals(12, $paginator->count(), 'First page should have 12 items');
        $this->assertEquals(200, $paginator->total(), 'Should have 200 total candidates');

        // Assert top result has highest Jaccard overlap with viewer
        $items = $paginator->items();
        $topScore = $items[0]['compatibility_score'];

        // Top score should be > 0 (viewer has preferences shared by some candidates)
        $this->assertGreaterThan(0, $topScore, 'Top result should have positive score');

        // Verify scores are sorted descending
        for ($i = 1; $i < count($items); $i++) {
            $this->assertLessThanOrEqual(
                $items[$i - 1]['compatibility_score'],
                $items[$i]['compatibility_score'],
                "Results should be sorted by descending score (item {$i} out of order)"
            );
        }
    }

    // ── Edge Case: User with 0 game systems, 0 vibes ──

    public function test_candidate_with_no_preferences_appears_with_nearby_reason(): void
    {
        $viewerLocation = $this->createLocationAt(self::LAT, self::LNG);
        $viewer = User::factory()->create([
            'location_id' => $viewerLocation->id,
            'profile_complete' => true,
        ]);
        $gs = GameSystem::factory()->create();
        $this->attachFavoriteGameSystems($viewer, [$gs]);
        $this->attachFavoriteVibes($viewer, ['atmospheric']);

        // Candidate with 0 game systems, 0 vibes — but has location
        $candidate = $this->createUserAt(self::LAT + 0.001, self::LNG);

        $service = $this->getDiscoveryService();
        $result = $service->discover($viewer, self::LAT, self::LNG, 12, 1);

        $paginator = $result['results'];
        $this->assertEquals(1, $paginator->total());

        $item = $paginator->items()[0];
        $this->assertEquals($candidate->id, $item['user']->id);
        $this->assertEquals(0, $item['compatibility_score'], 'Score should be 0 with no preferences');
        $this->assertContains('Nearby', $item['match_reasons'], 'Should have "Nearby" as reason');
    }

    // ── Edge Case: User with 1 game system match ──

    public function test_candidate_with_single_game_system_match_shows_shared_reason(): void
    {
        $gameSystem = GameSystem::factory()->create(['name' => 'Terraforming Mars']);

        $viewerLocation = $this->createLocationAt(self::LAT, self::LNG);
        $viewer = User::factory()->create([
            'location_id' => $viewerLocation->id,
            'profile_complete' => true,
        ]);
        $this->attachFavoriteGameSystems($viewer, [$gameSystem]);
        $this->attachFavoriteVibes($viewer, ['atmospheric']);

        // Candidate with exactly 1 matching game system
        $candidate = $this->createUserAt(self::LAT + 0.001, self::LNG);
        $this->attachFavoriteGameSystems($candidate, [$gameSystem]);
        // No vibes overlap — give them different vibes
        $this->attachFavoriteVibes($candidate, ['competitive']);

        $service = $this->getDiscoveryService();
        $result = $service->discover($viewer, self::LAT, self::LNG, 12, 1);

        $paginator = $result['results'];
        $this->assertEquals(1, $paginator->total());

        $item = $paginator->items()[0];
        $this->assertContains(
            'shared_game_systems',
            $item['match_reasons'],
            'Should show shared_game_systems reason for single match'
        );

        // Score should reflect partial overlap, not 100%
        // Viewer has 1 game system + 1 vibe; candidate matches 1/1 game but 0/1 vibes
        // taste_score = avg(game_jaccard, vibe_jaccard) = avg(1.0, 0.0) = 0.5
        $this->assertGreaterThan(0, $item['compatibility_score']);
        $this->assertLessThan(1.0, $item['compatibility_score'], 'Score should not be a misleading 100%');
    }

    // ── Edge Case: User with all privacy set to 'nobody' ──

    public function test_candidate_with_all_privacy_nobody_is_excluded(): void
    {
        $viewerLocation = $this->createLocationAt(self::LAT, self::LNG);
        $viewer = User::factory()->create([
            'location_id' => $viewerLocation->id,
            'profile_complete' => true,
        ]);

        // Candidate with ALL fields set to 'nobody' — including location
        $candidate = $this->createUserAt(self::LAT + 0.001, self::LNG, [
            'privacy_settings' => [
                'location' => 'nobody',
                'game_systems' => 'nobody',
                'vibes' => 'nobody',
                'teams' => 'nobody',
                'friends_list' => 'nobody',
                'campaigns' => 'nobody',
            ],
        ]);

        $service = $this->getDiscoveryService();
        $result = $service->discover($viewer, self::LAT, self::LNG, 12, 1);

        $paginator = $result['results'];
        $this->assertEquals(0, $paginator->total(), 'Candidate with location=nobody should be excluded');
    }

    // ── Edge Case: User with only location visible ──

    public function test_candidate_with_only_location_visible_appears_with_nearby_reason(): void
    {
        $viewerLocation = $this->createLocationAt(self::LAT, self::LNG);
        $viewer = User::factory()->create([
            'location_id' => $viewerLocation->id,
            'profile_complete' => true,
        ]);

        // Candidate: location visible, everything else hidden
        $candidate = $this->createUserAt(self::LAT + 0.001, self::LNG, [
            'privacy_settings' => [
                'location' => 'everyone',
                'game_systems' => 'nobody',
                'vibes' => 'nobody',
                'teams' => 'nobody',
                'friends_list' => 'nobody',
                'campaigns' => 'nobody',
            ],
        ]);

        // Give candidate some preferences (they just won't be visible to viewer)
        $this->attachFavoriteGameSystems($candidate, [GameSystem::factory()->create()]);
        $this->attachFavoriteVibes($candidate, ['atmospheric']);

        $service = $this->getDiscoveryService();
        $result = $service->discover($viewer, self::LAT, self::LNG, 12, 1);

        $paginator = $result['results'];
        $this->assertEquals(1, $paginator->total());

        $item = $paginator->items()[0];
        $this->assertEquals($candidate->id, $item['user']->id);
        $this->assertEquals(0, $item['compatibility_score'], 'Score should be 0 with hidden preferences');
        $this->assertContains('Nearby', $item['match_reasons'], 'Should have "Nearby" as only reason');
        $this->assertCount(1, $item['match_reasons'], 'Should have exactly 1 match reason');
    }
}
