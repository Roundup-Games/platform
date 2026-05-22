<?php

namespace Tests\Feature\People;

use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Services\PeopleDiscoveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class NearbyBehavioralTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale {
        SetsUpLocale::setUp as setUpLocale;
    }

    // Central Berlin (Mitte) — all candidates share this geohash-4 tile
    private const LAT = 52.5163;
    private const LNG = 13.3777;

    protected function setUp(): void
    {
        $this->setUpLocale();
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

    private function attachFavoriteGameSystems(User $user, array $systems): void
    {
        foreach ($systems as $system) {
            \DB::table('user_game_system_preferences')->insert([
                'user_id' => $user->id,
                'game_system_id' => $system->id,
                'preference_type' => 'favorite',
            ]);
        }
    }

    private function attachFavoriteVibes(User $user, array $vibeValues): void
    {
        foreach ($vibeValues as $value) {
            \DB::table('user_vibe_preferences')->insert([
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

    // ── Behavioral Tests ─────────────────────────────

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
        $gameSystem = GameSystem::factory()->create(['name' => ['en' => 'Terraforming Mars']]);

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
