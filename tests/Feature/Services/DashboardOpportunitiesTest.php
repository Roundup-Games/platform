<?php

namespace Tests\Unit;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\DashboardCacheService;
use App\Services\Geohash;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardOpportunitiesTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardCacheService $service;

    private GameSystem $gameSystem;

    private GameSystem $otherGameSystem;

    private Location $location;

    private string $geohash4;

    protected function setUp(): void
    {
        parent::setUp();
        // Flush the array cache so the invalidate_for_* tests start from a
        // known empty state. Without this, a stale entry from a prior test
        // (or a sibling --parallel worker) can flip expect(Cache::has(...))
        // into a false pass.
        Cache::flush();
        $this->service = app(DashboardCacheService::class);
        Log::spy();

        // Create game systems
        $this->gameSystem = GameSystem::factory()->create(['name' => ['en' => 'D&D 5e']]);
        $this->otherGameSystem = GameSystem::factory()->create(['name' => ['en' => 'Warhammer 40k']]);

        // Create a location in Berlin
        $this->location = Location::factory()->create([
            'latitude' => 52.52,
            'longitude' => 13.405,
        ]);

        $this->geohash4 = Geohash::tilePrefix(52.52, 13.405, 4);
    }

    private function createUserWithPreferences(array $overrides = []): User
    {
        $user = User::factory()->create($overrides);

        // Attach preferred game system
        $user->gameSystemPreferences()->attach($this->gameSystem->id, [
            'preference_type' => 'favorite',
        ]);

        // Give user a location
        $user->location_id = $this->location->id;
        $user->save();

        return $user;
    }

    private function createGameWithLocation(array $overrides = []): Game
    {
        return Game::factory()->create(array_merge([
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->addDays(3),
            'location_id' => $this->location->id,
            'max_players' => 6,
            'visibility' => Visibility::Public,
        ], $overrides));
    }

    // ── Basic functionality ────────────────────────────

    #[Test]
    public function it_returns_games_matching_user_preferred_game_systems(): void
    {
        $user = $this->createUserWithPreferences();

        // Game matching user's preferred system
        $game = $this->createGameWithLocation();

        // Game with different system — should NOT appear
        $this->createGameWithLocation([
            'game_system_id' => $this->otherGameSystem->id,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['games']);
        $this->assertEquals($game->id, $result['games'][0]['entity_id']);
        $this->assertEquals('game', $result['games'][0]['entity_type']);
    }

    #[Test]
    public function it_excludes_games_user_already_owns(): void
    {
        $user = $this->createUserWithPreferences();

        // Game owned by the user
        $this->createGameWithLocation(['owner_id' => $user->id]);

        // Game owned by someone else
        $otherUser = User::factory()->create();
        $otherGame = $this->createGameWithLocation(['owner_id' => $otherUser->id]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['games']);
        $this->assertEquals($otherGame->id, $result['games'][0]['entity_id']);
    }

    #[Test]
    public function it_excludes_games_user_already_participates_in(): void
    {
        $user = $this->createUserWithPreferences();
        $otherUser = User::factory()->create();

        // Game the user participates in
        $participatingGame = $this->createGameWithLocation(['owner_id' => $otherUser->id]);
        GameParticipant::create([
            'game_id' => $participatingGame->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Game the user does NOT participate in
        $openGame = $this->createGameWithLocation(['owner_id' => $otherUser->id]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['games']);
        $this->assertEquals($openGame->id, $result['games'][0]['entity_id']);
    }

    #[Test]
    public function it_only_includes_games_with_available_spots(): void
    {
        $user = $this->createUserWithPreferences();
        $owner = User::factory()->create();

        // Full game (max_players = 2, 2 approved participants)
        $fullGame = $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'max_players' => 2,
        ]);
        GameParticipant::create([
            'game_id' => $fullGame->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $fullGame->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Open game with spots
        $openGame = $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'max_players' => 6,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['games']);
        $this->assertEquals($openGame->id, $result['games'][0]['entity_id']);
    }

    #[Test]
    public function it_limits_games_to_top_4(): void
    {
        $user = $this->createUserWithPreferences();
        $owner = User::factory()->create();

        // Create 6 eligible games
        $games = collect();
        for ($i = 0; $i < 6; $i++) {
            $games->push($this->createGameWithLocation([
                'owner_id' => $owner->id,
                'date_time' => now()->addDays($i + 1),
            ]));
        }

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(4, $result['games']);
    }

    #[Test]
    public function it_limits_campaigns_to_top_2(): void
    {
        $user = $this->createUserWithPreferences();

        // Create 4 active campaigns matching user's system
        for ($i = 0; $i < 4; $i++) {
            Campaign::factory()->create([
                'owner_id' => User::factory()->create()->id,
                'game_system_id' => $this->gameSystem->id,
                'status' => CampaignStatus::Active,
                'visibility' => Visibility::Public,
            ]);
        }

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(2, $result['campaigns']);
    }

    #[Test]
    public function it_returns_campaigns_matching_user_preferences(): void
    {
        $user = $this->createUserWithPreferences();

        // Campaign matching user's preferred system
        $matchingCampaign = Campaign::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Public,
        ]);

        // Campaign with different system
        Campaign::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'game_system_id' => $this->otherGameSystem->id,
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Public,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['campaigns']);
        $this->assertEquals($matchingCampaign->id, $result['campaigns'][0]['entity_id']);
        $this->assertEquals('campaign', $result['campaigns'][0]['entity_type']);
    }

    #[Test]
    public function it_excludes_campaigns_user_already_participates_in(): void
    {
        $user = $this->createUserWithPreferences();
        $owner = User::factory()->create();

        // Campaign user already participates in
        $joinedCampaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Public,
        ]);
        CampaignParticipant::create([
            'campaign_id' => $joinedCampaign->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Campaign user does NOT participate in
        $openCampaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Public,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['campaigns']);
        $this->assertEquals($openCampaign->id, $result['campaigns'][0]['entity_id']);
    }

    #[Test]
    public function it_excludes_campaigns_user_owns(): void
    {
        $user = $this->createUserWithPreferences();

        // Campaign owned by the user
        Campaign::factory()->create([
            'owner_id' => $user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Public,
        ]);

        // Campaign owned by someone else
        $openCampaign = Campaign::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Public,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['campaigns']);
        $this->assertEquals($openCampaign->id, $result['campaigns'][0]['entity_id']);
    }

    // ── Scoring ────────────────────────────────────────

    #[Test]
    public function it_scores_closer_games_higher(): void
    {
        $user = $this->createUserWithPreferences();
        $owner = User::factory()->create();

        // Nearby location
        $nearLocation = Location::factory()->create([
            'latitude' => 52.53,
            'longitude' => 13.41,
        ]);

        // Far location (still in bounding box — geohash-4 tiles are ~20km)
        $farLocation = Location::factory()->create([
            'latitude' => $this->location->latitude + 0.03,
            'longitude' => $this->location->longitude + 0.08,
        ]);

        $farGame = $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'location_id' => $farLocation->id,
            'date_time' => now()->addDays(3),
        ]);

        $nearGame = $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'location_id' => $nearLocation->id,
            'date_time' => now()->addDays(3),
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(2, $result['games']);
        // Near game should be first (higher score)
        $this->assertEquals($nearGame->id, $result['games'][0]['entity_id']);
    }

    #[Test]
    public function it_scores_sooner_games_higher(): void
    {
        $user = $this->createUserWithPreferences();
        $owner = User::factory()->create();

        // Same location for both, different dates
        $laterGame = $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(10),
        ]);

        $soonerGame = $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(1),
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(2, $result['games']);
        // Sooner game should be first (higher urgency score)
        $this->assertEquals($soonerGame->id, $result['games'][0]['entity_id']);
    }

    // ── Edge cases ─────────────────────────────────────

    #[Test]
    public function it_returns_empty_when_no_games_match(): void
    {
        $user = $this->createUserWithPreferences();

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertEquals(['games' => [], 'campaigns' => [], 'total_available' => 0], $result);
    }

    #[Test]
    public function it_returns_empty_for_user_with_no_game_system_preferences(): void
    {
        $user = User::factory()->create();
        // No game system preferences attached

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertEquals(['games' => [], 'campaigns' => [], 'total_available' => 0], $result);
    }

    #[Test]
    public function it_excludes_games_outside_14_day_window(): void
    {
        $user = $this->createUserWithPreferences();
        $owner = User::factory()->create();

        // Game 20 days from now — outside window
        $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(20),
        ]);

        // Game 5 days from now — within window
        $nearGame = $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(5),
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['games']);
        $this->assertEquals($nearGame->id, $result['games'][0]['entity_id']);
    }

    #[Test]
    public function it_excludes_non_scheduled_games(): void
    {
        $user = $this->createUserWithPreferences();
        $owner = User::factory()->create();

        // Completed game
        $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'status' => GameStatus::Completed,
        ]);

        // Cancelled game
        $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'status' => GameStatus::Canceled,
        ]);

        // Scheduled game
        $scheduled = $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['games']);
        $this->assertEquals($scheduled->id, $result['games'][0]['entity_id']);
    }

    #[Test]
    public function it_excludes_private_campaigns(): void
    {
        $user = $this->createUserWithPreferences();

        // Private campaign — should be excluded
        Campaign::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Private,
        ]);

        // Public campaign
        $publicCampaign = Campaign::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Public,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['campaigns']);
        $this->assertEquals($publicCampaign->id, $result['campaigns'][0]['entity_id']);
    }

    #[Test]
    public function it_includes_protected_games_for_friends(): void
    {
        $owner = User::factory()->create();
        $user = $this->createUserWithPreferences();

        // Make user a friend of the owner (mutual follow)
        UserRelationship::create([
            'user_id' => $user->id,
            'related_user_id' => $owner->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $owner->id,
            'related_user_id' => $user->id,
            'type' => RelationshipType::Follow,
        ]);

        $protectedGame = $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Protected,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['games']);
        $this->assertEquals($protectedGame->id, $result['games'][0]['entity_id']);
    }

    #[Test]
    public function it_excludes_protected_games_for_strangers(): void
    {
        $owner = User::factory()->create();
        $user = $this->createUserWithPreferences();

        // No follow relationship — user is a stranger to the owner

        $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Protected,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(0, $result['games']);
    }

    #[Test]
    public function it_includes_protected_campaigns_for_friends(): void
    {
        $owner = User::factory()->create();
        $user = $this->createUserWithPreferences();

        // Mutual follow = friend
        UserRelationship::create([
            'user_id' => $user->id,
            'related_user_id' => $owner->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $owner->id,
            'related_user_id' => $user->id,
            'type' => RelationshipType::Follow,
        ]);

        $protectedCampaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Protected,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['campaigns']);
        $this->assertEquals($protectedCampaign->id, $result['campaigns'][0]['entity_id']);
    }

    #[Test]
    public function it_excludes_protected_campaigns_for_strangers(): void
    {
        $owner = User::factory()->create();
        $user = $this->createUserWithPreferences();

        // No follow relationship

        Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Protected,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(0, $result['campaigns']);
    }

    #[Test]
    public function it_excludes_non_active_campaigns(): void
    {
        $user = $this->createUserWithPreferences();

        // Cancelled campaign
        Campaign::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Cancelled,
            'visibility' => Visibility::Public,
        ]);

        // Completed campaign
        Campaign::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Completed,
            'visibility' => Visibility::Public,
        ]);

        // Active campaign
        $activeCampaign = Campaign::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Public,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['campaigns']);
        $this->assertEquals($activeCampaign->id, $result['campaigns'][0]['entity_id']);
    }

    // ── Return format ──────────────────────────────────

    #[Test]
    public function game_result_has_correct_format(): void
    {
        $user = $this->createUserWithPreferences();
        $owner = User::factory()->create(['name' => 'Game Master']);

        $game = $this->createGameWithLocation([
            'owner_id' => $owner->id,
            'name' => 'Epic Adventure',
        ]);

        // Add owner participant (explicit owner model)
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['games']);
        $gameResult = $result['games'][0];

        $this->assertEquals('game', $gameResult['entity_type']);
        $this->assertEquals($game->id, $gameResult['entity_id']);
        $this->assertEquals('Epic Adventure', $gameResult['entity_name']);
        $this->assertEquals('D&D 5e', $gameResult['game_system_name']);
        $this->assertNotNull($gameResult['date_time']);
        $this->assertEquals(5, $gameResult['spots_available']); // max_players(6) - owner participant(1) = 5
        $this->assertNotNull($gameResult['distance_km']);
        $this->assertEquals('Game Master', $gameResult['owner_name']);
    }

    #[Test]
    public function campaign_result_has_correct_format(): void
    {
        $user = $this->createUserWithPreferences();

        Campaign::factory()->create([
            'owner_id' => User::factory()->create(['name' => 'Campaign Host'])->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Weekly Dragons'],
            'recurrence' => 'weekly',
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Public,
            'max_players' => 8,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertCount(1, $result['campaigns']);
        $campaignResult = $result['campaigns'][0];

        $this->assertEquals('campaign', $campaignResult['entity_type']);
        $this->assertEquals('Weekly Dragons', $campaignResult['entity_name']);
        $this->assertEquals('D&D 5e', $campaignResult['game_system_name']);
        $this->assertEquals('weekly', $campaignResult['recurrence']);
        $this->assertNull($campaignResult['distance_km']);
        $this->assertEquals('Campaign Host', $campaignResult['owner_name']);
    }

    #[Test]
    public function total_available_counts_games_and_campaigns(): void
    {
        $user = $this->createUserWithPreferences();
        $owner = User::factory()->create();

        // 2 games
        $this->createGameWithLocation(['owner_id' => $owner->id]);
        $this->createGameWithLocation(['owner_id' => $owner->id]);

        // 1 campaign
        Campaign::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active,
            'visibility' => Visibility::Public,
        ]);

        $result = $this->service->computeOpportunities($user, $this->geohash4);

        $this->assertEquals(3, $result['total_available']);
    }
}
