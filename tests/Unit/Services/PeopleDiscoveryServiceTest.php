<?php

namespace Tests\Unit\Services;

use App\Enums\RelationshipType;
use App\Enums\VibeFlag;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\Geohash;
use App\Services\PeopleDiscoveryService;
use App\Services\ProfileVisibilityResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PeopleDiscoveryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private PeopleDiscoveryService $service;

    // Berlin coordinates (Mitte area)
    private const LAT = 52.5163;
    private const LNG = 13.3777;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PeopleDiscoveryService(new ProfileVisibilityResolver());
        Cache::flush();
    }

    // ── Helper: Create a complete-profile user with a location ──

    private function createUserWithLocation(float $lat, float $lng, array $overrides = []): User
    {
        $location = Location::factory()->create([
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        return User::factory()->create(array_merge([
            'location_id' => $location->id,
            'profile_complete' => true,
            'is_disabled' => false,
        ], $overrides));
    }

    private function attachGameSystems(User $user, array $systems): void
    {
        foreach ($systems as $system) {
            $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);
        }
    }

    private function attachVibes(User $user, array $vibes): void
    {
        foreach ($vibes as $vibe) {
            $user->vibePreferences()->create([
                'vibe_preference_value' => $vibe,
                'preference_type' => 'favorite',
            ]);
        }
    }

    private function attachToTeam(User $user, Team $team): void
    {
        DB::table('team_members')->insert([
            'id' => (string) \Illuminate\Support\Str::orderedUuid(),
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    /**
     * Call discover() and return the paginator directly.
     * Handles the ['results', 'status'] return format.
     */
    private function discover(User $viewer, ?float $lat = self::LAT, ?float $lng = self::LNG, int $perPage = 12, int $page = 1)
    {
        $response = $this->service->discover($viewer, $lat, $lng, $perPage, $page);

        return $response['results'];
    }

    /**
     * Call discover() and return the full response array (results + status).
     */
    private function discoverWithStatus(User $viewer, ?float $lat = self::LAT, ?float $lng = self::LNG, int $perPage = 12, int $page = 1): array
    {
        return $this->service->discover($viewer, $lat, $lng, $perPage, $page);
    }

    // ── Phase 1: Candidate Retrieval ──

    #[Test]
    public function it_returns_empty_paginator_when_no_candidates_exist()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);

        $results = $this->discover($viewer, self::LAT, self::LNG);

        $this->assertEquals(0, $results->total());
        $this->assertCount(0, $results->items());
    }

    #[Test]
    public function it_excludes_self_from_results()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);

        // Same location as viewer
        $results = $this->discover($viewer, self::LAT, self::LNG);

        $this->assertEquals(0, $results->total());
    }

    #[Test]
    public function it_excludes_blocked_users()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $blocked = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        UserRelationship::create([
            'user_id' => $viewer->id,
            'related_user_id' => $blocked->id,
            'type' => RelationshipType::Block,
        ]);

        $results = $this->discover($viewer, self::LAT, self::LNG);

        $ids = collect($results->items())->pluck('user.id');
        $this->assertFalse($ids->contains($blocked->id));
    }

    #[Test]
    public function it_excludes_users_who_blocked_viewer()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $blocker = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        UserRelationship::create([
            'user_id' => $blocker->id,
            'related_user_id' => $viewer->id,
            'type' => RelationshipType::Block,
        ]);

        $results = $this->discover($viewer, self::LAT, self::LNG);

        $ids = collect($results->items())->pluck('user.id');
        $this->assertFalse($ids->contains($blocker->id));
    }

    #[Test]
    public function it_excludes_already_followed_users()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $followed = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        UserRelationship::create([
            'user_id' => $viewer->id,
            'related_user_id' => $followed->id,
            'type' => RelationshipType::Follow,
        ]);

        $results = $this->discover($viewer, self::LAT, self::LNG);

        $ids = collect($results->items())->pluck('user.id');
        $this->assertFalse($ids->contains($followed->id));
    }

    #[Test]
    public function it_excludes_incomplete_profiles()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $incomplete = $this->createUserWithLocation(self::LAT + 0.001, self::LNG, [
            'profile_complete' => false,
        ]);

        $results = $this->discover($viewer, self::LAT, self::LNG);

        $ids = collect($results->items())->pluck('user.id');
        $this->assertFalse($ids->contains($incomplete->id));
    }

    #[Test]
    public function it_excludes_disabled_users()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $disabled = $this->createUserWithLocation(self::LAT + 0.001, self::LNG, [
            'is_disabled' => true,
        ]);

        $results = $this->discover($viewer, self::LAT, self::LNG);

        $ids = collect($results->items())->pluck('user.id');
        $this->assertFalse($ids->contains($disabled->id));
    }

    #[Test]
    public function it_excludes_users_with_hidden_location()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $hidden = $this->createUserWithLocation(self::LAT + 0.001, self::LNG, [
            'privacy_settings' => ['location' => 'nobody'],
        ]);

        $results = $this->discover($viewer, self::LAT, self::LNG);

        $ids = collect($results->items())->pluck('user.id');
        $this->assertFalse($ids->contains($hidden->id));
    }

    // ── Tier Expansion ──

    #[Test]
    public function it_expands_to_tier_2_when_few_results_in_tier_1()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);

        // Create 2 users in the same geohash_4 tile (tier 1)
        $nearby1 = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);
        $nearby2 = $this->createUserWithLocation(self::LAT + 0.002, self::LNG);

        // Create a user in a different geohash_4 tile but same geohash_3
        $viewerHash = Geohash::tilePrefix(self::LAT, self::LNG, 4);

        $bounds = Geohash::prefixBounds($viewerHash);
        $adjacentLat = $bounds['maxLat'] + 0.01;
        $adjacentLng = ($bounds['minLng'] + $bounds['maxLng']) / 2;

        $adjacentHash = Geohash::tilePrefix($adjacentLat, $adjacentLng, 4);
        if (substr($adjacentHash, 0, 3) === substr($viewerHash, 0, 3)) {
            $farUser = $this->createUserWithLocation($adjacentLat, $adjacentLng);

            $results = $this->discover($viewer, self::LAT, self::LNG, 12);

            $tierMap = [];
            foreach ($results->items() as $item) {
                $tierMap[$item['user']->id] = $item['tier'];
            }

            $this->assertEquals(1, $tierMap[$nearby1->id] ?? null);
            $this->assertEquals(1, $tierMap[$nearby2->id] ?? null);
            $this->assertEquals(2, $tierMap[$farUser->id] ?? null);
        } else {
            $results = $this->discover($viewer, self::LAT, self::LNG);
            $this->assertGreaterThanOrEqual(2, $results->total());
        }
    }

    // ── Phase 3: Scoring ──

    #[Test]
    public function it_ranks_users_by_taste_overlap()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $sys2 = GameSystem::factory()->create();
        $sys3 = GameSystem::factory()->create();

        $this->attachGameSystems($viewer, [$sys1, $sys2]);

        $candidateA = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);
        $this->attachGameSystems($candidateA, [$sys1, $sys2]);

        $candidateB = $this->createUserWithLocation(self::LAT + 0.002, self::LNG);
        $this->attachGameSystems($candidateB, [$sys1]);

        $candidateC = $this->createUserWithLocation(self::LAT + 0.003, self::LNG);
        $this->attachGameSystems($candidateC, [$sys3]);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $scoreA = $this->findScoreForUser($items, $candidateA);
        $scoreB = $this->findScoreForUser($items, $candidateB);
        $scoreC = $this->findScoreForUser($items, $candidateC);

        $this->assertNotNull($scoreA);
        $this->assertNotNull($scoreB);
        $this->assertNotNull($scoreC);

        $this->assertGreaterThan($scoreB, $scoreA, 'Candidate A (full overlap) should rank above B (partial)');
        $this->assertGreaterThan($scoreC, $scoreB, 'Candidate B (partial overlap) should rank above C (no overlap)');
    }

    #[Test]
    public function it_reweights_scoring_when_game_systems_hidden()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $this->attachGameSystems($viewer, [$sys1]);

        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG, [
            'privacy_settings' => ['game_systems' => 'nobody'],
        ]);
        $this->attachGameSystems($candidate, [$sys1]);
        $this->attachVibes($candidate, ['cooperative']);

        $this->attachVibes($viewer, ['cooperative']);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $found = false;
        foreach ($items as $item) {
            if ($item['user']->id === $candidate->id) {
                $found = true;
                $this->assertGreaterThan(0, $item['compatibility_score']);
                $this->assertNotContains('shared_game_systems', $item['match_reasons']);
                $this->assertContains('shared_vibes', $item['match_reasons']);
            }
        }

        $this->assertTrue($found, 'Candidate with hidden game_systems should still appear');
    }

    #[Test]
    public function it_computes_correct_jaccard_score()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $sys2 = GameSystem::factory()->create();
        $sys3 = GameSystem::factory()->create();

        $this->attachGameSystems($viewer, [$sys1, $sys2]);

        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);
        $this->attachGameSystems($candidate, [$sys2, $sys3]);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $score = $this->findScoreForUser($items, $candidate);
        $this->assertNotNull($score);

        $this->assertEqualsWithDelta(0.3333, $score, 0.01);
    }

    #[Test]
    public function it_combines_taste_and_social_scores()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $this->attachGameSystems($viewer, [$sys1]);

        $team = Team::factory()->create();
        $this->attachToTeam($viewer, $team);

        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);
        $this->attachGameSystems($candidate, [$sys1]);
        $this->attachToTeam($candidate, $team);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $score = $this->findScoreForUser($items, $candidate);
        $this->assertNotNull($score);

        $this->assertEqualsWithDelta(1.0, $score, 0.01);
    }

    #[Test]
    public function it_includes_match_reasons()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $this->attachGameSystems($viewer, [$sys1]);
        $this->attachVibes($viewer, ['cooperative']);

        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);
        $this->attachGameSystems($candidate, [$sys1]);
        $this->attachVibes($candidate, ['cooperative']);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $found = false;
        foreach ($items as $item) {
            if ($item['user']->id === $candidate->id) {
                $found = true;
                $this->assertContains('shared_game_systems', $item['match_reasons']);
                $this->assertContains('shared_vibes', $item['match_reasons']);
            }
        }

        $this->assertTrue($found);
    }

    #[Test]
    public function it_includes_distance_km_in_results()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $found = false;
        foreach ($items as $item) {
            if ($item['user']->id === $candidate->id) {
                $found = true;
                $this->assertIsFloat($item['distance_km']);
                $this->assertGreaterThan(0, $item['distance_km']);
                $this->assertLessThan(1, $item['distance_km']);
            }
        }

        $this->assertTrue($found);
    }

    #[Test]
    public function it_includes_tier_in_results()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $found = false;
        foreach ($items as $item) {
            if ($item['user']->id === $candidate->id) {
                $found = true;
                $this->assertEquals(1, $item['tier']);
            }
        }

        $this->assertTrue($found);
    }

    // ── Pagination ──

    #[Test]
    public function it_paginates_results()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);

        for ($i = 0; $i < 5; $i++) {
            $this->createUserWithLocation(self::LAT + 0.001 * ($i + 1), self::LNG);
        }

        $page1 = $this->discover($viewer, self::LAT, self::LNG, 3, 1);
        $page2 = $this->discover($viewer, self::LAT, self::LNG, 3, 2);

        $this->assertEquals(5, $page1->total());
        $this->assertCount(3, $page1->items());
        $this->assertCount(2, $page2->items());
    }

    // ── Users without location ──

    #[Test]
    public function it_excludes_users_without_location()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);

        $noLocation = User::factory()->create([
            'location_id' => null,
            'profile_complete' => true,
        ]);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $ids = collect($results->items())->pluck('user.id');

        $this->assertFalse($ids->contains($noLocation->id));
    }

    // ── Edge cases ──

    #[Test]
    public function it_handles_users_with_no_preferences()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $this->attachGameSystems($viewer, [$sys1]);

        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $found = false;
        foreach ($items as $item) {
            if ($item['user']->id === $candidate->id) {
                $found = true;
                $this->assertEquals(0.0, $item['compatibility_score']);
                // With no preferences, 'Nearby' is the fallback match reason
                $this->assertContains('Nearby', $item['match_reasons']);
            }
        }

        $this->assertTrue($found, 'Candidate with no preferences should still appear');
    }

    #[Test]
    public function it_finds_users_in_same_geohash_tile()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        $results = $this->discover($viewer, self::LAT, self::LNG);

        $this->assertEquals(1, $results->total());
        $this->assertEquals($candidate->id, $results->items()[0]['user']->id);
    }

    // ── No-location viewer ──

    #[Test]
    public function it_returns_no_location_status_when_viewer_has_no_location()
    {
        $viewer = User::factory()->create([
            'location_id' => null,
            'profile_complete' => true,
        ]);

        $response = $this->discoverWithStatus($viewer, null, null);

        $this->assertEquals('no_location', $response['status']);
        $this->assertEquals(0, $response['results']->total());
        $this->assertCount(0, $response['results']->items());
    }

    #[Test]
    public function it_returns_no_location_status_when_lat_is_null()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);

        $response = $this->discoverWithStatus($viewer, null, self::LNG);

        $this->assertEquals('no_location', $response['status']);
        $this->assertEquals(0, $response['results']->total());
    }

    #[Test]
    public function it_returns_no_location_status_when_lng_is_null()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);

        $response = $this->discoverWithStatus($viewer, self::LAT, null);

        $this->assertEquals('no_location', $response['status']);
        $this->assertEquals(0, $response['results']->total());
    }

    // ── All-signals-hidden candidate ──

    #[Test]
    public function it_shows_all_signals_hidden_candidate_with_score_zero_and_nearby_reason()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $this->attachGameSystems($viewer, [$sys1]);
        $this->attachVibes($viewer, ['cooperative']);

        // Candidate with everything hidden except location
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG, [
            'privacy_settings' => [
                'game_systems' => 'nobody',
                'vibes' => 'nobody',
                'teams' => 'nobody',
                'friends_list' => 'nobody',
            ],
        ]);
        $this->attachGameSystems($candidate, [$sys1]); // Won't matter — hidden
        $this->attachVibes($candidate, ['cooperative']); // Won't matter — hidden

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $found = false;
        foreach ($items as $item) {
            if ($item['user']->id === $candidate->id) {
                $found = true;
                $this->assertEquals(0.0, $item['compatibility_score']);
                $this->assertContains('Nearby', $item['match_reasons']);
                $this->assertNotContains('shared_game_systems', $item['match_reasons']);
                $this->assertNotContains('shared_vibes', $item['match_reasons']);
            }
        }

        $this->assertTrue($found, 'Candidate with all signals hidden should appear with Nearby reason');
    }

    #[Test]
    public function it_ranks_all_signals_hidden_candidate_last()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $this->attachGameSystems($viewer, [$sys1]);

        // Candidate with shared preferences (visible)
        $visibleCandidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);
        $this->attachGameSystems($visibleCandidate, [$sys1]);

        // Candidate with all signals hidden
        $hiddenCandidate = $this->createUserWithLocation(self::LAT + 0.002, self::LNG, [
            'privacy_settings' => [
                'game_systems' => 'nobody',
                'vibes' => 'nobody',
                'teams' => 'nobody',
                'friends_list' => 'nobody',
            ],
        ]);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $scoreVisible = $this->findScoreForUser($items, $visibleCandidate);
        $scoreHidden = $this->findScoreForUser($items, $hiddenCandidate);

        $this->assertNotNull($scoreVisible);
        $this->assertNotNull($scoreHidden);
        $this->assertGreaterThan($scoreHidden, $scoreVisible, 'Visible candidate should rank above all-signals-hidden candidate');
    }

    // ── Caching ──

    #[Test]
    public function it_caches_discovery_results_on_first_call()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        // First call — cache miss
        $response1 = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals('ok', $response1['status']);
        $this->assertEquals(1, $response1['results']->total());

        // Delete the candidate from DB — if cache works, second call still returns 1 result
        User::where('id', $candidate->id)->delete();
        Location::where('id', $candidate->location_id)->delete();

        // Second call — should hit cache
        $response2 = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals('ok', $response2['status']);
        $this->assertEquals(1, $response2['results']->total(), 'Cache hit should return cached results');
    }

    #[Test]
    public function it_logs_cache_hit_and_miss()
    {
        Queue::fake();

        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        Log::shouldReceive('debug')
            ->with('discovery.cache_miss', \Mockery::on(fn ($ctx) => $ctx['viewer_id'] === $viewer->id))
            ->once();

        Log::shouldReceive('debug')
            ->with('discovery.cache_hit', \Mockery::on(fn ($ctx) => $ctx['viewer_id'] === $viewer->id))
            ->once();

        // Also allow the stats and query_count_high logs
        Log::shouldReceive('debug')->with('discovery.stats', \Mockery::any())->zeroOrMoreTimes();
        Log::shouldReceive('debug')->with('discovery.cache_invalidated', \Mockery::any())->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        // First call — miss
        $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        // Second call — hit
        $this->discoverWithStatus($viewer, self::LAT, self::LNG);
    }

    #[Test]
    public function it_invalidates_cache_after_follow_action()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        // Populate the cache
        $response1 = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals(1, $response1['results']->total());

        // Follow the candidate — this invalidates viewer's cache
        UserRelationship::follow($viewer, $candidate);

        // Now a new discover call should reflect the follow (candidate excluded)
        $response2 = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals('ok', $response2['status']);
        $this->assertEquals(0, $response2['results']->total(), 'Cache should be invalidated after follow');
    }

    #[Test]
    public function it_invalidates_cache_after_block_action()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        // Populate the cache
        $response1 = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals(1, $response1['results']->total());

        // Block the candidate — invalidates viewer's cache
        UserRelationship::block($viewer, $candidate);

        // Cache should be invalidated
        $response2 = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals(0, $response2['results']->total(), 'Cache should be invalidated after block');
    }

    #[Test]
    public function it_invalidates_cache_after_unblock_action()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        // Block first
        UserRelationship::block($viewer, $candidate);

        // Discover — empty
        $response1 = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals(0, $response1['results']->total());

        // Unblock — invalidates cache
        UserRelationship::unblock($viewer, $candidate);

        // Should now see the candidate again
        $response2 = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals(1, $response2['results']->total(), 'Cache should be invalidated after unblock');
    }

    #[Test]
    public function it_invalidates_cache_after_unfollow_action()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        // Follow first — candidate excluded
        UserRelationship::follow($viewer, $candidate);

        $response1 = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals(0, $response1['results']->total());

        // Unfollow — invalidates cache, candidate should come back
        UserRelationship::unfollow($viewer, $candidate);

        $response2 = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals(1, $response2['results']->total(), 'Cache should be invalidated after unfollow');
    }

    // ── Zero candidates across all tiers ──

    #[Test]
    public function it_returns_empty_paginator_for_isolated_location()
    {
        // Create a viewer in a very remote location with no nearby users
        $viewer = $this->createUserWithLocation(71.7069, -42.6043); // Greenland

        $response = $this->discoverWithStatus($viewer, 71.7069, -42.6043);

        $this->assertEquals('ok', $response['status']);
        $this->assertEquals(0, $response['results']->total());
        $this->assertCount(0, $response['results']->items());
    }

    // ── Cache Read vs Compute ──

    #[Test]
    public function compute_and_cache_returns_same_results_as_discover()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $sys2 = GameSystem::factory()->create();
        $this->attachGameSystems($viewer, [$sys1, $sys2]);

        $candidateA = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);
        $this->attachGameSystems($candidateA, [$sys1, $sys2]);

        $candidateB = $this->createUserWithLocation(self::LAT + 0.002, self::LNG);
        $this->attachGameSystems($candidateB, [$sys1]);

        // computeAndCache returns raw scored results
        $scored = $this->service->computeAndCache($viewer, self::LAT, self::LNG);

        $this->assertCount(2, $scored);
        $this->assertEquals($candidateA->id, $scored[0]['user']->id);
        $this->assertEquals($candidateB->id, $scored[1]['user']->id);
        $this->assertGreaterThan($scored[1]['compatibility_score'], $scored[0]['compatibility_score']);
    }

    #[Test]
    public function compute_and_cache_stores_results_in_cache()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        $this->service->computeAndCache($viewer, self::LAT, self::LNG);

        // Delete candidate from DB — cache should still return it
        User::where('id', $candidate->id)->delete();

        $response = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals('ok', $response['status']);
        $this->assertEquals(1, $response['results']->total(), 'Cache should contain the pre-computed result');
    }

    #[Test]
    public function discover_reads_from_cache_on_page_2()
    {
        Queue::fake();

        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $this->attachGameSystems($viewer, [$sys1]);

        // Create 5 candidates with varying scores
        for ($i = 0; $i < 5; $i++) {
            $c = $this->createUserWithLocation(self::LAT + 0.001 * ($i + 1), self::LNG);
            if ($i < 2) {
                $this->attachGameSystems($c, [$sys1]);
            }
        }

        // Pre-populate cache via computeAndCache
        $this->service->computeAndCache($viewer, self::LAT, self::LNG);

        // Page 2 should be served from cache (no page=1 restriction)
        $response = $this->discoverWithStatus($viewer, self::LAT, self::LNG, 3, 2);
        $this->assertEquals('ok', $response['status']);
        $this->assertEquals(5, $response['results']->total(), 'Cache should serve all 5 results');
        $this->assertCount(2, $response['results']->items(), 'Page 2 should have 2 items');

        // Verify the background job was NOT dispatched (cache hit)
        Queue::assertNotPushed(\App\Jobs\UpdateUserDiscoveryCache::class);
    }

    #[Test]
    public function discover_dispatches_background_refresh_on_cache_miss()
    {
        Queue::fake();

        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        // First call — cache miss, should dispatch background refresh
        $this->discoverWithStatus($viewer, self::LAT, self::LNG);

        Queue::assertPushed(\App\Jobs\UpdateUserDiscoveryCache::class, function ($job) use ($viewer) {
            return $job->userId === $viewer->id
                && $job->triggerType === 'cache_miss_refresh';
        });
    }

    #[Test]
    public function discover_does_not_dispatch_job_when_no_linked_location()
    {
        Queue::fake();

        // Viewer with location but no linkedLocation (guest location)
        $viewer = User::factory()->create([
            'location_id' => null,
            'profile_complete' => true,
        ]);

        // discover with explicit lat/lng (simulating guest location)
        $this->discoverWithStatus($viewer, self::LAT, self::LNG);

        Queue::assertNotPushed(\App\Jobs\UpdateUserDiscoveryCache::class);
    }

    #[Test]
    public function discover_does_not_dispatch_job_on_cache_hit()
    {
        Queue::fake();

        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        // Pre-populate cache
        $this->service->computeAndCache($viewer, self::LAT, self::LNG);
        Queue::fake(); // Reset after computeAndCache

        // Second call — cache hit, should NOT dispatch
        $this->discoverWithStatus($viewer, self::LAT, self::LNG);

        Queue::assertNotPushed(\App\Jobs\UpdateUserDiscoveryCache::class);
    }

    #[Test]
    public function compute_and_cache_handles_empty_candidates()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);

        $scored = $this->service->computeAndCache($viewer, self::LAT, self::LNG);

        $this->assertEquals([], $scored);

        // Empty cache should be stored (preventing repeated empty computations)
        $response = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals('ok', $response['status']);
        $this->assertEquals(0, $response['results']->total());
    }

    // ── Helper ──

    private function findScoreForUser(array $items, User $user): ?float
    {
        foreach ($items as $item) {
            if ($item['user']->id === $user->id) {
                return $item['compatibility_score'];
            }
        }

        return null;
    }
}
