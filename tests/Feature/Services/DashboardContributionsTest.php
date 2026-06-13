<?php

namespace Tests\Unit;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Review;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\DashboardCacheService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardContributionsTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardCacheService::class);
        Log::spy();
    }

    // ── Games hosted ───────────────────────────────────

    #[Test]
    public function it_counts_games_hosted_with_completed_status(): void
    {
        $user = User::factory()->create();

        // 3 completed games owned by user
        Game::factory()->count(3)->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
        ]);

        // 1 scheduled game — should NOT count
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
        ]);

        $result = $this->service->computeContributions($user);

        $this->assertEquals(3, $result['hosted']['count']);
    }

    #[Test]
    public function it_sums_expected_duration_for_hosted_hours(): void
    {
        $user = User::factory()->create();

        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
            'expected_duration' => 2.5,
        ]);
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
            'expected_duration' => 3.0,
        ]);

        $result = $this->service->computeContributions($user);

        $this->assertEquals(5.5, $result['hosted']['hours']);
    }

    #[Test]
    public function it_counts_unique_players_across_hosted_games_excluding_self(): void
    {
        $user = User::factory()->create();
        $player1 = User::factory()->create();
        $player2 = User::factory()->create();
        $player3 = User::factory()->create();

        $game1 = Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
        ]);
        $game2 = Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
        ]);

        // game1: player1, player2, and self (should exclude self)
        GameParticipant::factory()->create(['game_id' => $game1->id, 'user_id' => $player1->id, 'status' => ParticipantStatus::Approved]);
        GameParticipant::factory()->create(['game_id' => $game1->id, 'user_id' => $player2->id, 'status' => ParticipantStatus::Approved]);
        GameParticipant::factory()->create(['game_id' => $game1->id, 'user_id' => $user->id, 'status' => ParticipantStatus::Approved]);

        // game2: player2, player3 (player2 appears in both — should count once)
        GameParticipant::factory()->create(['game_id' => $game2->id, 'user_id' => $player2->id, 'status' => ParticipantStatus::Approved]);
        GameParticipant::factory()->create(['game_id' => $game2->id, 'user_id' => $player3->id, 'status' => ParticipantStatus::Approved]);

        $result = $this->service->computeContributions($user);

        $this->assertEquals(3, $result['hosted']['unique_players']);
    }

    // ── Games played ───────────────────────────────────

    #[Test]
    public function it_counts_games_played_not_owned(): void
    {
        $user = User::factory()->create();
        $otherOwner = User::factory()->create();

        $game1 = Game::factory()->create([
            'owner_id' => $otherOwner->id,
            'status' => GameStatus::Completed,
        ]);
        $game2 = Game::factory()->create([
            'owner_id' => $otherOwner->id,
            'status' => GameStatus::Completed,
        ]);

        GameParticipant::factory()->create(['game_id' => $game1->id, 'user_id' => $user->id, 'status' => ParticipantStatus::Approved]);
        GameParticipant::factory()->create(['game_id' => $game2->id, 'user_id' => $user->id, 'status' => ParticipantStatus::Approved]);

        $result = $this->service->computeContributions($user);

        $this->assertEquals(2, $result['played']['count']);
    }

    #[Test]
    public function it_excludes_owned_games_from_played_count(): void
    {
        $user = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
        ]);
        GameParticipant::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'status' => ParticipantStatus::Approved]);

        $result = $this->service->computeContributions($user);

        $this->assertEquals(0, $result['played']['count']);
    }

    #[Test]
    public function it_counts_distinct_game_systems_from_played_games(): void
    {
        $user = User::factory()->create();
        $otherOwner = User::factory()->create();
        $system1 = GameSystem::factory()->create();
        $system2 = GameSystem::factory()->create();
        $system3 = GameSystem::factory()->create();

        // 3 games across 2 distinct systems
        $game1 = Game::factory()->create(['owner_id' => $otherOwner->id, 'status' => GameStatus::Completed, 'game_system_id' => $system1->id]);
        $game2 = Game::factory()->create(['owner_id' => $otherOwner->id, 'status' => GameStatus::Completed, 'game_system_id' => $system1->id]);
        $game3 = Game::factory()->create(['owner_id' => $otherOwner->id, 'status' => GameStatus::Completed, 'game_system_id' => $system2->id]);

        GameParticipant::factory()->create(['game_id' => $game1->id, 'user_id' => $user->id, 'status' => ParticipantStatus::Approved]);
        GameParticipant::factory()->create(['game_id' => $game2->id, 'user_id' => $user->id, 'status' => ParticipantStatus::Approved]);
        GameParticipant::factory()->create(['game_id' => $game3->id, 'user_id' => $user->id, 'status' => ParticipantStatus::Approved]);

        $result = $this->service->computeContributions($user);

        $this->assertEquals(2, $result['played']['system_count']);
    }

    // ── Longest campaign ───────────────────────────────

    #[Test]
    public function it_finds_longest_active_campaign_with_most_completed_games(): void
    {
        $user = User::factory()->create();

        $campaign1 = Campaign::factory()->create([
            'owner_id' => $user->id,
            'status' => CampaignStatus::Active,
            'name' => ['en' => 'Short Campaign'],
        ]);
        $campaign2 = Campaign::factory()->create([
            'owner_id' => $user->id,
            'status' => CampaignStatus::Active,
            'name' => ['en' => 'Long Campaign'],
        ]);

        // campaign1: 2 completed games
        Game::factory()->count(2)->create([
            'campaign_id' => $campaign1->id,
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
        ]);

        // campaign2: 5 completed games
        Game::factory()->count(5)->create([
            'campaign_id' => $campaign2->id,
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
        ]);

        $result = $this->service->computeContributions($user);

        $this->assertNotNull($result['campaigns']);
        $this->assertEquals('Long Campaign', $result['campaigns']['name']);
        $this->assertEquals(5, $result['campaigns']['session_count']);
    }

    #[Test]
    public function it_returns_null_campaigns_when_no_active_owned_campaigns(): void
    {
        $user = User::factory()->create();

        // No campaigns at all
        $result = $this->service->computeContributions($user);

        $this->assertNull($result['campaigns']);
    }

    #[Test]
    public function it_returns_null_campaigns_when_active_campaign_has_no_completed_games(): void
    {
        $user = User::factory()->create();

        $campaign = Campaign::factory()->create([
            'owner_id' => $user->id,
            'status' => CampaignStatus::Active,
            'name' => ['en' => 'Empty Campaign'],
        ]);

        // Only scheduled games — no completed
        Game::factory()->create([
            'campaign_id' => $campaign->id,
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
        ]);

        $result = $this->service->computeContributions($user);

        $this->assertNull($result['campaigns']);
    }

    // ── Recaps written ─────────────────────────────────

    #[Test]
    public function it_counts_recaps_written(): void
    {
        $user = User::factory()->create();

        // 2 games with recaps
        Game::factory()->count(2)->create([
            'owner_id' => $user->id,
            'recap' => 'Great session!',
        ]);

        // 1 game without recap
        Game::factory()->create([
            'owner_id' => $user->id,
            'recap' => null,
        ]);

        $result = $this->service->computeContributions($user);

        $this->assertEquals(2, $result['recaps_written']);
    }

    // ── Reviews given ──────────────────────────────────

    #[Test]
    public function it_counts_reviews_given(): void
    {
        $user = User::factory()->create();

        Review::factory()->count(3)->create([
            'reviewer_id' => $user->id,
        ]);

        $result = $this->service->computeContributions($user);

        $this->assertEquals(3, $result['reviews_given']);
    }

    // ── Followers ──────────────────────────────────────

    #[Test]
    public function it_counts_followers(): void
    {
        $user = User::factory()->create();

        $follower1 = User::factory()->create();
        $follower2 = User::factory()->create();
        $follower3 = User::factory()->create();

        UserRelationship::create([
            'user_id' => $follower1->id,
            'related_user_id' => $user->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $follower2->id,
            'related_user_id' => $user->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $follower3->id,
            'related_user_id' => $user->id,
            'type' => RelationshipType::Follow,
        ]);

        $result = $this->service->computeContributions($user);

        $this->assertEquals(3, $result['followers']);
    }

    // ── Zero state ─────────────────────────────────────

    #[Test]
    public function it_returns_zeros_for_new_user(): void
    {
        $user = User::factory()->create();

        $result = $this->service->computeContributions($user);

        $this->assertEquals(0, $result['hosted']['count']);
        $this->assertEquals(0.0, $result['hosted']['hours']);
        $this->assertEquals(0, $result['hosted']['unique_players']);
        $this->assertEquals(0, $result['played']['count']);
        $this->assertEquals(0, $result['played']['system_count']);
        $this->assertNull($result['campaigns']);
        $this->assertEquals(0, $result['recaps_written']);
        $this->assertEquals(0, $result['reviews_given']);
        $this->assertEquals(0, $result['followers']);
    }

    // ── Cache integration ──────────────────────────────

    #[Test]
    public function get_contributions_caches_result(): void
    {
        $user = User::factory()->create();

        $result1 = $this->service->getContributions($user);
        $result2 = $this->service->getContributions($user);

        $this->assertEquals($result1, $result2);
    }

    #[Test]
    public function warm_contributions_stores_in_cache(): void
    {
        $user = User::factory()->create();

        $result = $this->service->warmContributions($user);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('hosted', $result);
        $this->assertArrayHasKey('played', $result);
        $this->assertArrayHasKey('campaigns', $result);
        $this->assertArrayHasKey('recaps_written', $result);
        $this->assertArrayHasKey('reviews_given', $result);
        $this->assertArrayHasKey('followers', $result);
    }

    #[Test]
    public function contributions_cache_ttl_is_one_hour(): void
    {
        $user = User::factory()->create();

        // Use Cache spy to capture all Cache::put calls and verify TTL
        Cache::spy();
        Cache::shouldReceive('get')->andReturn(null);

        $this->service->getContributions($user);

        // Verify at least one put was for the contributions key with 3600s TTL
        Cache::shouldHaveReceived('put')
            ->withArgs(function (string $key, $value, int $ttl) {
                return str_contains($key, 'dashboard:contributions:')
                    && is_array($value)
                    && $ttl === 3600;
            });
    }

    // ── Invalidation ───────────────────────────────────

    #[Test]
    public function invalidate_for_user_clears_contributions_cache(): void
    {
        $user = User::factory()->create();

        // Populate contributions cache
        $result = $this->service->getContributions($user);
        $cacheKey = "dashboard:contributions:{$user->id}";
        $this->assertNotNull(Cache::get($cacheKey), 'Cache should be populated');

        // Simulate invalidation that happens when a recap is written (game completion)
        $this->service->invalidateForUser((string) $user->id, ['contributions']);

        $this->assertNull(Cache::get($cacheKey), 'Cache should be cleared after invalidation');

        // Next call should recompute
        $result2 = $this->service->getContributions($user);
        $this->assertArrayHasKey('hosted', $result2);
    }
}
