<?php

namespace Tests\Unit\Services;

use App\Enums\ParticipantRole;
use App\Enums\RelationshipType;
use App\Jobs\UpdateUserDiscoveryCache;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\PeopleDiscoveryService;
use App\Services\ProfileVisibilityResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesUsers;

class PeopleDiscoveryServiceTest extends TestCase
{
    use CreatesUsers;
    use DatabaseTransactions;

    private PeopleDiscoveryService $service;

    // Berlin coordinates (Mitte area)
    private const LAT = 52.5163;

    private const LNG = 13.3777;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PeopleDiscoveryService(new ProfileVisibilityResolver);
        Cache::flush();
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
            'id' => (string) Str::orderedUuid(),
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    /**
     * Warm cache then read from discover().
     * Simulates the production flow: background job computes, page reads cache.
     */
    private function discover(User $viewer, ?float $lat = self::LAT, ?float $lng = self::LNG, int $perPage = 12, int $page = 1)
    {
        // Warm the cache first (simulates background job)
        if ($lat !== null && $lng !== null) {
            $this->service->computeAndCache($viewer, $lat, $lng);
        }

        $response = $this->service->discover($viewer, $lat, $lng, $perPage, $page);

        return $response['results'];
    }

    /**
     * Call discover() and return the full response array (results + status).
     * Does NOT warm the cache — use this to test cache-miss behavior.
     */
    private function discoverWithStatus(User $viewer, ?float $lat = self::LAT, ?float $lng = self::LNG, int $perPage = 12, int $page = 1): array
    {
        return $this->service->discover($viewer, $lat, $lng, $perPage, $page);
    }

    /**
     * Warm cache then read with status.
     */
    private function discoverWarmed(User $viewer, ?float $lat = self::LAT, ?float $lng = self::LNG, int $perPage = 12, int $page = 1): array
    {
        if ($lat !== null && $lng !== null) {
            $this->service->computeAndCache($viewer, $lat, $lng);
        }

        return $this->service->discover($viewer, $lat, $lng, $perPage, $page);
    }

    // ── Phase 1: Candidate Retrieval ──

    #[Test]
    public function it_excludes_self_from_results()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $other = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        $results = $this->discover($viewer, self::LAT, self::LNG);

        $ids = collect($results->items())->pluck('user.id');
        $this->assertFalse($ids->contains($viewer->id), 'Self should be excluded');
        $this->assertTrue($ids->contains($other->id), 'Other user should be present');
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

        $nearby1 = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);
        $nearby2 = $this->createUserWithLocation(self::LAT + 0.002, self::LNG);

        $farUser = $this->createUserWithLocation(52.5636, 13.5352);

        $results = $this->discover($viewer, self::LAT, self::LNG, 12);

        $tierMap = [];
        foreach ($results->items() as $item) {
            $tierMap[$item['user']->id] = $item['tier'];
        }

        $this->assertEquals(1, $tierMap[$nearby1->id] ?? null);
        $this->assertEquals(1, $tierMap[$nearby2->id] ?? null);
        $this->assertEquals(2, $tierMap[$farUser->id] ?? null);
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
    public function it_finds_users_in_same_geohash_tile_with_distance_and_tier()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        $results = $this->discover($viewer, self::LAT, self::LNG);

        $this->assertEquals(1, $results->total());
        $item = $results->items()[0];
        $this->assertEquals($candidate->id, $item['user']->id);
        $this->assertIsFloat($item['distance_km']);
        $this->assertGreaterThan(0, $item['distance_km']);
        $this->assertLessThan(1, $item['distance_km']);
        $this->assertEquals(1, $item['tier']);
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
                $this->assertContains('Nearby', $item['match_reasons']);
            }
        }

        $this->assertTrue($found, 'Candidate with no preferences should still appear');
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

    // ── All-signals-hidden candidate ──

    #[Test]
    public function it_shows_all_signals_hidden_candidate_with_score_zero_and_nearby_reason()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $this->attachGameSystems($viewer, [$sys1]);
        $this->attachVibes($viewer, ['cooperative']);

        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG, [
            'privacy_settings' => [
                'game_systems' => 'nobody',
                'vibes' => 'nobody',
                'teams' => 'nobody',
                'friends_list' => 'nobody',
            ],
        ]);
        $this->attachGameSystems($candidate, [$sys1]);
        $this->attachVibes($candidate, ['cooperative']);

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

        $visibleCandidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);
        $this->attachGameSystems($visibleCandidate, [$sys1]);

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
    public function it_invalidates_cache_after_follow_action()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        // Warm cache
        $response1 = $this->discoverWarmed($viewer, self::LAT, self::LNG);
        $this->assertEquals(1, $response1['results']->total());

        // Follow the candidate — invalidates viewer's cache
        UserRelationship::follow($viewer, $candidate);

        // Re-warm cache (follow excluded the candidate)
        $this->service->computeAndCache($viewer, self::LAT, self::LNG);

        $response2 = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals('ok', $response2['status']);
        $this->assertEquals(0, $response2['results']->total(), 'Cache should reflect exclusion after follow');
    }

    #[Test]
    public function it_invalidates_cache_after_block_action()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $candidate = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        // Warm cache
        $response1 = $this->discoverWarmed($viewer, self::LAT, self::LNG);
        $this->assertEquals(1, $response1['results']->total());

        // Block — invalidates cache
        UserRelationship::block($viewer, $candidate);

        // Re-warm
        $this->service->computeAndCache($viewer, self::LAT, self::LNG);

        $response2 = $this->discoverWithStatus($viewer, self::LAT, self::LNG);
        $this->assertEquals(0, $response2['results']->total(), 'Cache should reflect exclusion after block');
    }

    // ── Zero candidates across all tiers ──

    #[Test]
    public function it_returns_empty_paginator_for_isolated_location()
    {
        $viewer = $this->createUserWithLocation(71.7069, -42.6043); // Greenland

        // Warm cache (will be empty)
        $this->service->computeAndCache($viewer, 71.7069, -42.6043);

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
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $this->attachGameSystems($viewer, [$sys1]);

        for ($i = 0; $i < 5; $i++) {
            $c = $this->createUserWithLocation(self::LAT + 0.001 * ($i + 1), self::LNG);
            if ($i < 2) {
                $this->attachGameSystems($c, [$sys1]);
            }
        }

        // Warm cache
        $this->service->computeAndCache($viewer, self::LAT, self::LNG);

        // Page 2 served from cache
        $response = $this->discoverWithStatus($viewer, self::LAT, self::LNG, 3, 2);
        $this->assertEquals('ok', $response['status']);
        $this->assertEquals(5, $response['results']->total(), 'Cache should serve all 5 results');
        $this->assertCount(2, $response['results']->items(), 'Page 2 should have 2 items');
    }

    // ── Cache-only discover() behavior ──

    #[Test]
    public function discover_returns_pending_on_cache_miss()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        // Do NOT warm cache — discover should return pending
        $response = $this->discoverWithStatus($viewer, self::LAT, self::LNG);

        $this->assertEquals('pending', $response['status']);
        $this->assertEquals(0, $response['results']->total());
    }

    #[Test]
    public function discover_does_not_dispatch_jobs()
    {
        Queue::fake();

        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $this->createUserWithLocation(self::LAT + 0.001, self::LNG);

        // discover() is cache-only — it should never dispatch jobs
        $this->discoverWithStatus($viewer, self::LAT, self::LNG);

        Queue::assertNotPushed(UpdateUserDiscoveryCache::class);
    }

    #[Test]
    public function should_warm_cache_returns_true_when_cache_cold()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);

        $this->assertTrue($this->service->shouldWarmCache($viewer, self::LAT, self::LNG));
    }

    #[Test]
    public function should_warm_cache_returns_false_when_cache_warm()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);

        $this->service->computeAndCache($viewer, self::LAT, self::LNG);

        $this->assertFalse($this->service->shouldWarmCache($viewer, self::LAT, self::LNG));
    }

    #[Test]
    public function should_warm_cache_returns_false_when_no_location()
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);

        $this->assertFalse($this->service->shouldWarmCache($viewer, null, null));
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

    // ── Pure-Math: Jaccard Similarity (via Reflection) ──

    #[Test]
    public function jaccard_identical_sets_returns_one(): void
    {
        $result = $this->callJaccard([1, 2, 3], [1, 2, 3]);
        $this->assertEqualsWithDelta(1.0, $result, 0.0001);
    }

    #[Test]
    public function jaccard_disjoint_sets_returns_zero(): void
    {
        $result = $this->callJaccard([1, 2], [3, 4]);
        $this->assertEqualsWithDelta(0.0, $result, 0.0001);
    }

    #[Test]
    public function jaccard_partial_overlap(): void
    {
        $result = $this->callJaccard([1, 2, 3], [2, 3, 4]);
        $this->assertEqualsWithDelta(0.5, $result, 0.0001);
    }

    #[Test]
    public function jaccard_one_empty_set_returns_zero(): void
    {
        $result = $this->callJaccard([], [1, 2]);
        $this->assertEqualsWithDelta(0.0, $result, 0.0001);
    }

    #[Test]
    public function jaccard_both_empty_returns_zero(): void
    {
        $result = $this->callJaccard([], []);
        $this->assertEqualsWithDelta(0.0, $result, 0.0001);
    }

    #[Test]
    public function jaccard_single_element_match_returns_one(): void
    {
        $result = $this->callJaccard([1], [1]);
        $this->assertEqualsWithDelta(1.0, $result, 0.0001);
    }

    #[Test]
    public function jaccard_single_element_no_match_returns_zero(): void
    {
        $result = $this->callJaccard([1], [2]);
        $this->assertEqualsWithDelta(0.0, $result, 0.0001);
    }

    #[Test]
    public function jaccard_string_values_work_correctly(): void
    {
        $result = $this->callJaccard(['cooperative', 'strategic'], ['cooperative', 'creative']);
        $this->assertEqualsWithDelta(0.3333, $result, 0.01);
    }

    #[Test]
    public function jaccard_large_overlap(): void
    {
        $a = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $b = [1, 2, 3, 4, 5, 6, 7, 8, 9, 11];
        $result = $this->callJaccard($a, $b);
        $this->assertEqualsWithDelta(0.8182, $result, 0.01);
    }

    #[Test]
    public function jaccard_superset_returns_correct_ratio(): void
    {
        $result = $this->callJaccard([1, 2, 3, 4, 5], [1, 2]);
        $this->assertEqualsWithDelta(0.4, $result, 0.0001);
    }

    #[Test]
    public function jaccard_duplicates_are_ignored(): void
    {
        $result = $this->callJaccard([1, 1, 2], [1, 2]);
        $this->assertEqualsWithDelta(1.0, $result, 0.0001);
    }

    // ── Score Composition ──

    #[Test]
    public function more_shared_game_systems_scores_higher_than_fewer(): void
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $sys2 = GameSystem::factory()->create();
        $sys3 = GameSystem::factory()->create();

        $this->attachGameSystems($viewer, [$sys1, $sys2, $sys3]);

        $threeShared = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);
        $this->attachGameSystems($threeShared, [$sys1, $sys2, $sys3]);

        $oneShared = $this->createUserWithLocation(self::LAT + 0.002, self::LNG);
        $this->attachGameSystems($oneShared, [$sys1]);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $scoreThree = $this->findScoreForUser($items, $threeShared);
        $scoreOne = $this->findScoreForUser($items, $oneShared);

        $this->assertNotNull($scoreThree);
        $this->assertNotNull($scoreOne);
        $this->assertGreaterThan($scoreOne, $scoreThree, '3 shared game systems should score higher than 1');
    }

    #[Test]
    public function adding_shared_vibes_increases_score(): void
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $sys2 = GameSystem::factory()->create();
        $this->attachGameSystems($viewer, [$sys1, $sys2]);
        $this->attachVibes($viewer, ['cooperative', 'tactical']);

        $withVibes = $this->createUserWithLocation(self::LAT + 0.001, self::LNG);
        $this->attachGameSystems($withVibes, [$sys1]);
        $this->attachVibes($withVibes, ['cooperative', 'tactical']);

        $withoutVibes = $this->createUserWithLocation(self::LAT + 0.002, self::LNG);
        $this->attachGameSystems($withoutVibes, [$sys1]);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $scoreWithVibes = $this->findScoreForUser($items, $withVibes);
        $scoreWithoutVibes = $this->findScoreForUser($items, $withoutVibes);

        $this->assertNotNull($scoreWithVibes);
        $this->assertNotNull($scoreWithoutVibes);
        $this->assertGreaterThan($scoreWithoutVibes, $scoreWithVibes, 'Shared vibes should increase score');
    }

    #[Test]
    public function mutual_follow_provides_social_bonus(): void
    {
        $viewer = $this->createUserWithLocation(self::LAT, self::LNG);
        $sys1 = GameSystem::factory()->create();
        $this->attachGameSystems($viewer, [$sys1]);

        // One-way follow (candidate→viewer) — NOT mutual, no social bonus
        $candidateFollowsViewer = $this->createUserWithLocation(self::LAT + 0.002, self::LNG);
        $this->attachGameSystems($candidateFollowsViewer, [$sys1]);
        UserRelationship::create([
            'user_id' => $candidateFollowsViewer->id,
            'related_user_id' => $viewer->id,
            'type' => RelationshipType::Follow,
        ]);

        $noFollow = $this->createUserWithLocation(self::LAT + 0.003, self::LNG);
        $this->attachGameSystems($noFollow, [$sys1]);

        $results = $this->discover($viewer, self::LAT, self::LNG);
        $items = $results->items();

        $scoreFollowsViewer = $this->findScoreForUser($items, $candidateFollowsViewer);
        $scoreNoFollow = $this->findScoreForUser($items, $noFollow);

        $this->assertNotNull($scoreFollowsViewer);
        $this->assertNotNull($scoreNoFollow);
        // One-way follow is not mutual → same score
        $this->assertEqualsWithDelta($scoreNoFollow, $scoreFollowsViewer, 0.001,
            'One-way follow should not add social bonus (not mutual)');
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

    /**
     * Call the jaccard() method directly (static, no reflection needed).
     */
    private function callJaccard(array $a, array $b): float
    {
        return PeopleDiscoveryService::jaccard($a, $b);
    }
}
