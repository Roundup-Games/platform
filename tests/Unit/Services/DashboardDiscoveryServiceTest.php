<?php

namespace Tests\Unit\Services;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\DashboardDiscoveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardDiscoveryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardDiscoveryService $service;
    private User $user;
    private GameSystem $gameSystem;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardDiscoveryService;
        Cache::flush();
        Queue::fake();

        $this->user = User::factory()->create();
        $this->gameSystem = GameSystem::factory()->create();
        $this->location = Location::factory()->create([
            'latitude' => 52.5200,
            'longitude' => 13.4050,
        ]);
    }

    // ── getNearbyNoteworthy ─────────────────────────────

    public function test_nearby_noteworthy_returns_games_in_geohash_tile(): void
    {
        $otherUser = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $otherUser->id,
            'game_system_id' => $this->gameSystem->id,
            'location_id' => $this->location->id,
            'date_time' => now()->addDays(3),
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
            'max_players' => 6,
        ]);

        // Get geohash4 from game location
        $geohash4 = \App\Services\Geohash::tilePrefix(52.5200, 13.4050, 4);

        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        $this->assertNotEmpty($results);
        $this->assertEquals($game->id, $results[0]['id']);
    }

    public function test_nearby_noteworthy_excludes_games_user_owns(): void
    {
        // Use a unique location far from other tests
        $uniqueLocation = Location::factory()->create([
            'latitude' => 48.2082,
            'longitude' => 16.3738,
        ]);

        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'location_id' => $uniqueLocation->id,
            'date_time' => now()->addDays(3),
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
            'max_players' => 6,
        ]);

        $geohash4 = \App\Services\Geohash::tilePrefix(48.2082, 16.3738, 4);
        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        $this->assertEmpty($results);
    }

    public function test_nearby_noteworthy_excludes_games_user_participates_in(): void
    {
        $otherUser = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $otherUser->id,
            'game_system_id' => $this->gameSystem->id,
            'location_id' => $this->location->id,
            'date_time' => now()->addDays(3),
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
            'max_players' => 6,
        ]);

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $geohash4 = \App\Services\Geohash::tilePrefix(52.5200, 13.4050, 4);
        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        $ids = array_column($results, 'id');
        $this->assertNotContains($game->id, $ids);
    }

    public function test_nearby_noteworthy_returns_correct_entry_shape(): void
    {
        $otherUser = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $otherUser->id,
            'game_system_id' => $this->gameSystem->id,
            'location_id' => $this->location->id,
            'date_time' => now()->addDays(3),
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
            'max_players' => 6,
        ]);

        $geohash4 = \App\Services\Geohash::tilePrefix(52.5200, 13.4050, 4);
        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        $this->assertNotEmpty($results);
        $entry = $results[0];

        $this->assertArrayHasKey('id', $entry);
        $this->assertArrayHasKey('name', $entry);
        $this->assertArrayHasKey('system_badge', $entry);
        $this->assertArrayHasKey('date_time', $entry);
        $this->assertArrayHasKey('relative_time', $entry);
        $this->assertArrayHasKey('spots_available', $entry);
        $this->assertArrayHasKey('distance_km', $entry);
        $this->assertArrayHasKey('relevance_tags', $entry);
        $this->assertIsArray($entry['relevance_tags']);
        $this->assertArrayHasKey('system_badge', $entry);
        $this->assertArrayHasKey('name', $entry['system_badge']);
    }

    public function test_nearby_noteworthy_sorts_by_relevance_tags_then_date(): void
    {
        // Use unique location to avoid test collision
        $sortLocation = Location::factory()->create([
            'latitude' => 48.1351,
            'longitude' => 11.5820,
        ]);

        $otherUser = User::factory()->create();
        $preferredSystem = GameSystem::factory()->create();
        $this->user->gameSystemPreferences()->attach($preferredSystem->id, ['preference_type' => 'favorite']);

        // Game with matches_your_taste tag (in user preferences) — later date
        $gameA = Game::factory()->create([
            'owner_id' => $otherUser->id,
            'game_system_id' => $preferredSystem->id,
            'location_id' => $sortLocation->id,
            'date_time' => now()->addDays(5),
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
            'max_players' => 6,
        ]);

        // Game without preference match but sooner — no tags
        $gameB = Game::factory()->create([
            'owner_id' => $otherUser->id,
            'game_system_id' => $this->gameSystem->id,
            'location_id' => $sortLocation->id,
            'date_time' => now()->addDays(2),
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
            'max_players' => 6,
        ]);

        $geohash4 = \App\Services\Geohash::tilePrefix(48.1351, 11.5820, 4);
        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        // Game with preference tag should come first
        $ids = array_column($results, 'id');
        $posA = array_search($gameA->id, $ids);
        $posB = array_search($gameB->id, $ids);
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posB, $posA, 'Game with more relevance tags should come first');
    }

    public function test_nearby_noteworthy_applies_matches_your_taste_tag(): void
    {
        $tasteLocation = Location::factory()->create([
            'latitude' => 50.1109,
            'longitude' => 8.6821,
        ]);

        $otherUser = User::factory()->create();
        $preferredSystem = GameSystem::factory()->create();
        $this->user->gameSystemPreferences()->attach($preferredSystem->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'owner_id' => $otherUser->id,
            'game_system_id' => $preferredSystem->id,
            'location_id' => $tasteLocation->id,
            'date_time' => now()->addDays(3),
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
            'max_players' => 6,
        ]);

        $geohash4 = \App\Services\Geohash::tilePrefix(50.1109, 8.6821, 4);
        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        $this->assertNotEmpty($results);
        $this->assertContains('matches_your_taste', $results[0]['relevance_tags']);
    }

    public function test_nearby_noteworthy_applies_starting_soon_tag(): void
    {
        $otherUser = User::factory()->create();

        Game::factory()->create([
            'owner_id' => $otherUser->id,
            'game_system_id' => $this->gameSystem->id,
            'location_id' => $this->location->id,
            'date_time' => now()->addHours(24),
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
            'max_players' => 6,
        ]);

        $geohash4 = \App\Services\Geohash::tilePrefix(52.5200, 13.4050, 4);
        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        $this->assertNotEmpty($results);
        $this->assertContains('starting_soon', $results[0]['relevance_tags']);
    }

    public function test_nearby_noteworthy_applies_filling_fast_tag(): void
    {
        $otherUser = User::factory()->create();
        // 4 participants → participant_count = 5 (4 + 1 owner). max_players = 6.
        // filling_fast: 5 >= 6 * 0.7 = 4.2 ✓, spots_available = 1 ✓
        $players = User::factory()->count(4)->create();

        $game = Game::factory()->create([
            'owner_id' => $otherUser->id,
            'game_system_id' => $this->gameSystem->id,
            'location_id' => $this->location->id,
            'date_time' => now()->addDays(5),
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
            'max_players' => 6,
        ]);

        foreach ($players as $player) {
            GameParticipant::factory()->create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        $geohash4 = \App\Services\Geohash::tilePrefix(52.5200, 13.4050, 4);
        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        $ids = array_column($results, 'id');
        $idx = array_search($game->id, $ids);
        $this->assertNotFalse($idx, "Game should appear in results. IDs: " . json_encode($ids));
        $this->assertContains('filling_fast', $results[$idx]['relevance_tags']);
        $this->assertContains('popular_nearby', $results[$idx]['relevance_tags']);
    }

    public function test_nearby_noteworthy_applies_friends_are_going_tag(): void
    {
        // Use unique location to avoid collision with other tests
        $friendLocation = Location::factory()->create([
            'latitude' => 47.0767,
            'longitude' => 15.4213,
        ]);

        $otherUser = User::factory()->create();
        $friend = User::factory()->create();

        // User follows the friend
        UserRelationship::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => RelationshipType::Follow->value,
        ]);

        $game = Game::factory()->create([
            'owner_id' => $otherUser->id,
            'game_system_id' => $this->gameSystem->id,
            'location_id' => $friendLocation->id,
            'date_time' => now()->addDays(3),
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
            'max_players' => 6,
        ]);

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $geohash4 = \App\Services\Geohash::tilePrefix(47.0767, 15.4213, 4);
        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        $ids = array_column($results, 'id');
        $idx = array_search($game->id, $ids);
        $this->assertNotFalse($idx, "Game should appear in results. IDs: " . json_encode($ids));
        $this->assertContains('friends_are_going', $results[$idx]['relevance_tags']);
    }

    public function test_nearby_noteworthy_returns_empty_for_no_games(): void
    {
        // Use a unique geohash far from any created games
        $geohash4 = \App\Services\Geohash::tilePrefix(-33.8688, 151.2093, 4); // Sydney
        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        $this->assertEmpty($results);
    }

    public function test_nearby_noteworthy_limits_to_six_games(): void
    {
        $otherUser = User::factory()->create();

        for ($i = 0; $i < 8; $i++) {
            Game::factory()->create([
                'owner_id' => $otherUser->id,
                'game_system_id' => $this->gameSystem->id,
                'location_id' => $this->location->id,
                'date_time' => now()->addDays($i + 1),
                'status' => GameStatus::Scheduled->value,
                'visibility' => 'public',
                'max_players' => 6,
            ]);
        }

        $geohash4 = \App\Services\Geohash::tilePrefix(52.5200, 13.4050, 4);
        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        $this->assertLessThanOrEqual(6, count($results));
    }

    public function test_nearby_noteworthy_excludes_full_games(): void
    {
        $otherUser = User::factory()->create();
        $players = User::factory()->count(6)->create();

        $game = Game::factory()->create([
            'owner_id' => $otherUser->id,
            'game_system_id' => $this->gameSystem->id,
            'location_id' => $this->location->id,
            'date_time' => now()->addDays(3),
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
            'max_players' => 6,
        ]);

        // Fill the game
        foreach ($players as $player) {
            GameParticipant::factory()->create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        $geohash4 = \App\Services\Geohash::tilePrefix(52.5200, 13.4050, 4);
        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        $ids = array_column($results, 'id');
        $this->assertNotContains($game->id, $ids);
    }

    public function test_nearby_noteworthy_computes_distance_km(): void
    {
        // Give user a location
        $userLocation = Location::factory()->create([
            'latitude' => 52.5200,
            'longitude' => 13.4050,
        ]);
        $this->user->update(['location_id' => $userLocation->id]);
        $this->user->refresh();

        $otherUser = User::factory()->create();

        Game::factory()->create([
            'owner_id' => $otherUser->id,
            'game_system_id' => $this->gameSystem->id,
            'location_id' => $this->location->id,
            'date_time' => now()->addDays(3),
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
            'max_players' => 6,
        ]);

        $geohash4 = \App\Services\Geohash::tilePrefix(52.5200, 13.4050, 4);
        $results = $this->service->getNearbyNoteworthy($this->user, $geohash4);

        $this->assertNotEmpty($results);
        $this->assertNotNull($results[0]['distance_km']);
        $this->assertIsFloat($results[0]['distance_km']);
    }

    // ── getMilestoneCards ───────────────────────────────

    public function test_milestone_cards_returns_empty_when_none_earned(): void
    {
        $cards = $this->service->getMilestoneCards($this->user);

        $this->assertEmpty($cards);
    }

    public function test_milestone_cards_veteran_host(): void
    {
        // Create 10 completed games as host
        for ($i = 0; $i < 10; $i++) {
            Game::factory()->create([
                'owner_id' => $this->user->id,
                'game_system_id' => $this->gameSystem->id,
                'date_time' => now()->subDays(30 - $i),
                'status' => GameStatus::Completed->value,
            ]);
        }

        $cards = $this->service->getMilestoneCards($this->user);

        $veteranHost = collect($cards)->firstWhere('key', 'veteran_host');
        $this->assertNotNull($veteranHost);
        $this->assertEquals('dashboard.milestones.veteran_host.title', $veteranHost['title_key']);
        $this->assertEquals('dashboard.milestones.veteran_host.description', $veteranHost['description_key']);
        $this->assertEquals('trophy', $veteranHost['icon']);
        $this->assertNotNull($veteranHost['earned_at']);
    }

    public function test_milestone_cards_no_veteran_host_with_fewer_than_10(): void
    {
        for ($i = 0; $i < 9; $i++) {
            Game::factory()->create([
                'owner_id' => $this->user->id,
                'game_system_id' => $this->gameSystem->id,
                'date_time' => now()->subDays(30 - $i),
                'status' => GameStatus::Completed->value,
            ]);
        }

        $cards = $this->service->getMilestoneCards($this->user);

        $veteranHost = collect($cards)->firstWhere('key', 'veteran_host');
        $this->assertNull($veteranHost);
    }

    public function test_milestone_cards_community_builder(): void
    {
        // Create a completed game with 5+ unique players
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->subDays(5),
            'status' => GameStatus::Completed->value,
        ]);

        $players = User::factory()->count(5)->create();
        foreach ($players as $player) {
            GameParticipant::factory()->create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        $cards = $this->service->getMilestoneCards($this->user);

        $communityBuilder = collect($cards)->firstWhere('key', 'community_builder');
        $this->assertNotNull($communityBuilder);
        $this->assertEquals('users', $communityBuilder['icon']);
    }

    public function test_milestone_cards_no_community_builder_with_fewer_than_5(): void
    {
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->subDays(5),
            'status' => GameStatus::Completed->value,
        ]);

        $players = User::factory()->count(4)->create();
        foreach ($players as $player) {
            GameParticipant::factory()->create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        $cards = $this->service->getMilestoneCards($this->user);

        $communityBuilder = collect($cards)->firstWhere('key', 'community_builder');
        $this->assertNull($communityBuilder);
    }

    public function test_milestone_cards_campaign_commitment(): void
    {
        $campaign = Campaign::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active->value,
        ]);

        CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Create 5 completed sessions in the campaign
        for ($i = 0; $i < 5; $i++) {
            Game::factory()->create([
                'owner_id' => $campaign->owner_id,
                'campaign_id' => $campaign->id,
                'game_system_id' => $this->gameSystem->id,
                'date_time' => now()->subDays(30 - $i * 7),
                'status' => GameStatus::Completed->value,
            ]);
        }

        $cards = $this->service->getMilestoneCards($this->user);

        $commitment = collect($cards)->firstWhere('key', 'campaign_commitment');
        $this->assertNotNull($commitment);
        $this->assertEquals('book-open', $commitment['icon']);
        $this->assertNotNull($commitment['earned_at']);
    }

    public function test_milestone_cards_no_campaign_commitment_with_fewer_sessions(): void
    {
        $campaign = Campaign::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => CampaignStatus::Active->value,
        ]);

        CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Only 4 completed sessions
        for ($i = 0; $i < 4; $i++) {
            Game::factory()->create([
                'owner_id' => $campaign->owner_id,
                'campaign_id' => $campaign->id,
                'game_system_id' => $this->gameSystem->id,
                'date_time' => now()->subDays(30 - $i * 7),
                'status' => GameStatus::Completed->value,
            ]);
        }

        $cards = $this->service->getMilestoneCards($this->user);

        $commitment = collect($cards)->firstWhere('key', 'campaign_commitment');
        $this->assertNull($commitment);
    }

    public function test_milestone_cards_trusted_voice(): void
    {
        $gmProfile = GMProfile::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Create 3 published reviews with avg >= 4.5
        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'rating' => 5,
            'status' => 'published',
        ]);
        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'rating' => 5,
            'status' => 'published',
        ]);
        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'rating' => 4,
            'status' => 'published',
        ]);

        $cards = $this->service->getMilestoneCards($this->user);

        $trustedVoice = collect($cards)->firstWhere('key', 'trusted_voice');
        $this->assertNotNull($trustedVoice);
        $this->assertEquals('star', $trustedVoice['icon']);
        $this->assertNotNull($trustedVoice['earned_at']);
    }

    public function test_milestone_cards_no_trusted_voice_with_low_rating(): void
    {
        $gmProfile = GMProfile::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // 3 reviews but avg < 4.5
        Review::factory()->create(['gm_profile_id' => $gmProfile->id, 'rating' => 3, 'status' => 'published']);
        Review::factory()->create(['gm_profile_id' => $gmProfile->id, 'rating' => 4, 'status' => 'published']);
        Review::factory()->create(['gm_profile_id' => $gmProfile->id, 'rating' => 4, 'status' => 'published']);

        $cards = $this->service->getMilestoneCards($this->user);

        $trustedVoice = collect($cards)->firstWhere('key', 'trusted_voice');
        $this->assertNull($trustedVoice);
    }

    public function test_milestone_cards_no_trusted_voice_with_fewer_than_3_reviews(): void
    {
        $gmProfile = GMProfile::factory()->create([
            'user_id' => $this->user->id,
        ]);

        Review::factory()->create(['gm_profile_id' => $gmProfile->id, 'rating' => 5, 'status' => 'published']);
        Review::factory()->create(['gm_profile_id' => $gmProfile->id, 'rating' => 5, 'status' => 'published']);

        $cards = $this->service->getMilestoneCards($this->user);

        $trustedVoice = collect($cards)->firstWhere('key', 'trusted_voice');
        $this->assertNull($trustedVoice);
    }

    public function test_milestone_cards_explorer(): void
    {
        // Participate in 5 different game systems
        $systems = GameSystem::factory()->count(5)->create();
        $host = User::factory()->create();

        foreach ($systems as $system) {
            $game = Game::factory()->create([
                'owner_id' => $host->id,
                'game_system_id' => $system->id,
                'status' => GameStatus::Completed->value,
                'date_time' => now()->subDays(10),
            ]);
            GameParticipant::factory()->create([
                'game_id' => $game->id,
                'user_id' => $this->user->id,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        $cards = $this->service->getMilestoneCards($this->user);

        $explorer = collect($cards)->firstWhere('key', 'explorer');
        $this->assertNotNull($explorer);
        $this->assertEquals('compass', $explorer['icon']);
    }

    public function test_milestone_cards_no_explorer_with_fewer_systems(): void
    {
        $systems = GameSystem::factory()->count(4)->create();
        $host = User::factory()->create();

        foreach ($systems as $system) {
            $game = Game::factory()->create([
                'owner_id' => $host->id,
                'game_system_id' => $system->id,
                'status' => GameStatus::Completed->value,
                'date_time' => now()->subDays(10),
            ]);
            GameParticipant::factory()->create([
                'game_id' => $game->id,
                'user_id' => $this->user->id,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        $cards = $this->service->getMilestoneCards($this->user);

        $explorer = collect($cards)->firstWhere('key', 'explorer');
        $this->assertNull($explorer);
    }

    public function test_milestone_cards_is_new_within_7_days(): void
    {
        // 10th completed game was yesterday
        for ($i = 0; $i < 9; $i++) {
            Game::factory()->create([
                'owner_id' => $this->user->id,
                'game_system_id' => $this->gameSystem->id,
                'date_time' => now()->subDays(30 - $i),
                'status' => GameStatus::Completed->value,
            ]);
        }
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->subDays(1),
            'status' => GameStatus::Completed->value,
        ]);

        $cards = $this->service->getMilestoneCards($this->user);

        $veteranHost = collect($cards)->firstWhere('key', 'veteran_host');
        $this->assertNotNull($veteranHost);
        $this->assertTrue($veteranHost['is_new']);
    }

    public function test_milestone_cards_is_not_new_after_7_days(): void
    {
        // 10th completed game was 10 days ago
        for ($i = 0; $i < 10; $i++) {
            Game::factory()->create([
                'owner_id' => $this->user->id,
                'game_system_id' => $this->gameSystem->id,
                'date_time' => now()->subDays(30 - $i),
                'status' => GameStatus::Completed->value,
            ]);
        }

        $cards = $this->service->getMilestoneCards($this->user);

        $veteranHost = collect($cards)->firstWhere('key', 'veteran_host');
        $this->assertNotNull($veteranHost);
        $this->assertFalse($veteranHost['is_new']);
    }

    public function test_milestone_card_entry_shape(): void
    {
        // Earn veteran_host
        for ($i = 0; $i < 10; $i++) {
            Game::factory()->create([
                'owner_id' => $this->user->id,
                'game_system_id' => $this->gameSystem->id,
                'date_time' => now()->subDays(30 - $i),
                'status' => GameStatus::Completed->value,
            ]);
        }

        $cards = $this->service->getMilestoneCards($this->user);

        $this->assertNotEmpty($cards);
        $card = $cards[0];
        $this->assertArrayHasKey('key', $card);
        $this->assertArrayHasKey('title_key', $card);
        $this->assertArrayHasKey('description_key', $card);
        $this->assertArrayHasKey('icon', $card);
        $this->assertArrayHasKey('earned_at', $card);
        $this->assertArrayHasKey('is_new', $card);
    }

    // ── shouldShowCommunityPulse ────────────────────────

    public function test_community_pulse_hidden_when_fewer_than_3_follows(): void
    {
        $followed = User::factory()->count(2)->create();
        foreach ($followed as $f) {
            UserRelationship::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $this->user->id,
                'related_user_id' => $f->id,
                'type' => RelationshipType::Follow->value,
            ]);
        }

        $this->assertFalse($this->service->shouldShowCommunityPulse($this->user));
    }

    public function test_community_pulse_hidden_when_no_feed_data(): void
    {
        $followed = User::factory()->count(3)->create();
        foreach ($followed as $f) {
            UserRelationship::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $this->user->id,
                'related_user_id' => $f->id,
                'type' => RelationshipType::Follow->value,
            ]);
        }

        // No feed data exists (no activity from followed users)
        $this->assertFalse($this->service->shouldShowCommunityPulse($this->user));
    }

    public function test_community_pulse_shown_when_3_follows_and_feed_data(): void
    {
        $followed = User::factory()->count(3)->create();
        foreach ($followed as $f) {
            UserRelationship::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $this->user->id,
                'related_user_id' => $f->id,
                'type' => RelationshipType::Follow->value,
            ]);
        }

        // Seed feed data by creating a game by a followed user
        $firstFollowed = $followed[0];
        Game::factory()->create([
            'owner_id' => $firstFollowed->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(3),
        ]);

        $this->assertTrue($this->service->shouldShowCommunityPulse($this->user));
    }

    // ── Explorer counts hosted + participated systems ───

    public function test_explorer_counts_hosted_and_participated_systems(): void
    {
        $uid = \Illuminate\Support\Str::uuid()->toString();

        // Host 3 different systems
        for ($i = 0; $i < 3; $i++) {
            $sys = GameSystem::factory()->create([
                'name' => ['en' => "Host {$uid} {$i}"],
            ]);
            Game::factory()->create([
                'owner_id' => $this->user->id,
                'game_system_id' => $sys->id,
                'status' => GameStatus::Completed->value,
                'date_time' => now()->subDays(10),
            ]);
        }

        // Participate in 2 more different systems
        $host = User::factory()->create();
        for ($i = 0; $i < 2; $i++) {
            $sys = GameSystem::factory()->create([
                'name' => ['en' => "Played {$uid} {$i}"],
            ]);
            $game = Game::factory()->create([
                'owner_id' => $host->id,
                'game_system_id' => $sys->id,
                'status' => GameStatus::Completed->value,
                'date_time' => now()->subDays(10),
            ]);
            GameParticipant::factory()->create([
                'game_id' => $game->id,
                'user_id' => $this->user->id,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        $cards = $this->service->getMilestoneCards($this->user);

        $explorer = collect($cards)->firstWhere('key', 'explorer');
        $this->assertNotNull($explorer, 'Explorer card should be earned with 3 hosted + 2 participated systems');
    }
}
