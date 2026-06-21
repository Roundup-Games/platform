<?php

namespace Tests\Unit\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Services\DashboardNewcomerService;
use App\Services\Geohash;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardNewcomerServiceTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardNewcomerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardNewcomerService(
            new DashboardCacheService,
        );
        Cache::flush();
    }

    // ── computeWelcomeData ──────────────────────────────────────────────

    public function test_compute_welcome_data_returns_first_name(): void
    {
        $user = $this->makeUser(name: 'Jane Doe');

        $result = $this->service->computeWelcomeData($user);

        $this->assertSame('Jane', $result['first_name']);
    }

    public function test_compute_welcome_data_falls_back_to_adventurer_for_empty_name(): void
    {
        $user = $this->makeUser(name: '');

        $result = $this->service->computeWelcomeData($user);

        $this->assertSame('Adventurer', $result['first_name']);
    }

    public function test_compute_welcome_data_returns_city_from_location(): void
    {
        $user = $this->makeUser();
        $location = $this->makeLocation('Berlin', 52.52, 13.405);
        $user->setRelation('linkedLocation', $location);

        $result = $this->service->computeWelcomeData($user);

        $this->assertSame('Berlin', $result['city']);
        $this->assertTrue($result['has_location']);
    }

    public function test_compute_welcome_data_detects_no_location(): void
    {
        $user = $this->makeUser();

        $result = $this->service->computeWelcomeData($user);

        $this->assertNull($result['city']);
        $this->assertFalse($result['has_location']);
    }

    public function test_compute_welcome_data_returns_preferred_systems(): void
    {
        $user = $this->makeUser();
        $system = GameSystem::create(['name' => 'D&D 5e', 'slug' => 'dnd-5e']);
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $result = $this->service->computeWelcomeData($user);

        $this->assertCount(1, $result['preferred_systems']);
        $this->assertSame('D&D 5e', $result['preferred_systems'][0]);

        // Cleanup
        $user->gameSystemPreferences()->detach();
    }

    public function test_compute_welcome_data_limits_preferred_systems_to_three(): void
    {
        $user = $this->makeUser();
        $systems = [];
        for ($i = 1; $i <= 5; $i++) {
            $systems[] = GameSystem::create(['name' => "System $i", 'slug' => "system-$i"]);
        }
        foreach ($systems as $system) {
            $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);
        }

        $result = $this->service->computeWelcomeData($user);

        $this->assertCount(3, $result['preferred_systems']);

        // Cleanup
        $user->gameSystemPreferences()->detach();
    }

    public function test_compute_welcome_data_returns_matching_games_count_zero_without_location(): void
    {
        $user = $this->makeUser();

        $result = $this->service->computeWelcomeData($user);

        $this->assertSame(0, $result['matching_games_count']);
    }

    public function test_welcome_message_key_with_matches(): void
    {
        $user = $this->makeUser();
        $location = $this->makeLocation('Berlin', 52.52, 13.405);
        $user->setRelation('linkedLocation', $location);

        // No preferred systems => no matches
        $result = $this->service->computeWelcomeData($user);

        // Without preferred systems, key should be welcome_with_location
        $this->assertSame('welcome_with_location', $result['welcome_message_key']);
    }

    public function test_welcome_message_key_basic(): void
    {
        $user = $this->makeUser();

        $result = $this->service->computeWelcomeData($user);

        $this->assertSame('welcome_basic', $result['welcome_message_key']);
    }

    public function test_welcome_message_key_with_preferences(): void
    {
        $user = $this->makeUser();
        $system = GameSystem::create(['name' => 'Pathfinder', 'slug' => 'pathfinder']);
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $result = $this->service->computeWelcomeData($user);

        // Has preferences but no location
        $this->assertSame('welcome_with_preferences', $result['welcome_message_key']);

        $user->gameSystemPreferences()->detach();
    }

    // ── computeProgressTracker ──────────────────────────────────────────

    public function test_progress_tracker_starts_at_step_1(): void
    {
        $user = $this->makeUser(profileComplete: false);

        $result = $this->service->computeProgressTracker($user);

        $this->assertSame(1, $result['current_step']);
        $this->assertSame(0, $result['completion_percentage']);
        $this->assertCount(4, $result['steps']);
        $this->assertFalse($result['steps'][0]['is_complete']);
    }

    public function test_progress_tracker_step_1_complete_when_profile_complete(): void
    {
        $user = $this->makeUser(profileComplete: true);

        $result = $this->service->computeProgressTracker($user);

        $this->assertTrue($result['steps'][0]['is_complete']);
        $this->assertSame(25, $result['completion_percentage']);
    }

    public function test_progress_tracker_step_2_complete_when_has_preferences(): void
    {
        $user = $this->makeUser(profileComplete: true);
        $system = GameSystem::create(['name' => 'D&D', 'slug' => 'dnd-test']);
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $result = $this->service->computeProgressTracker($user);

        $this->assertTrue($result['steps'][1]['is_complete']);
        $this->assertSame(50, $result['completion_percentage']);

        $user->gameSystemPreferences()->detach();
    }

    public function test_progress_tracker_step_3_complete_when_has_participation(): void
    {
        $user = $this->makeUser(profileComplete: true);
        $system = GameSystem::create(['name' => 'D&D', 'slug' => 'dnd-test2']);
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        // Create a game and participation
        $game = Game::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $user->id,
            'game_system_id' => $system->id,
            'name' => 'Test Game',
            'description' => 'Test game description',
            'expected_duration' => 2.0,
            'language' => 'en',
            'location' => ['address' => 'Test Address', 'lat' => 52.0, 'lng' => 13.0],
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(1),
            'max_players' => 5,
        ]);

        // Create a second user to be the participant
        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $otherUser->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        $result = $this->service->computeProgressTracker($otherUser);

        $this->assertTrue($result['steps'][2]['is_complete']);

        // Cleanup
        $user->gameSystemPreferences()->detach();
    }

    public function test_progress_tracker_step_4_complete_when_attended_completed_game(): void
    {
        $owner = User::create([
            'name' => 'Game Owner',
            'email' => 'owner-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
        ]);

        $player = $this->makeUser(profileComplete: true);
        $system = GameSystem::create(['name' => 'System X', 'slug' => 'sys-x-'.uniqid()]);
        $player->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $game = Game::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => 'Completed Game',
            'description' => 'Completed game description',
            'expected_duration' => 3.0,
            'language' => 'en',
            'location' => ['address' => 'Test Address', 'lat' => 52.0, 'lng' => 13.0],
            'status' => GameStatus::Completed->value,
            'date_time' => now()->subDays(1),
            'max_players' => 5,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $result = $this->service->computeProgressTracker($player);

        $this->assertTrue($result['steps'][3]['is_complete']);
        $this->assertSame(100, $result['completion_percentage']);

        $player->gameSystemPreferences()->detach();
    }

    public function test_progress_tracker_returns_step_names_and_routes(): void
    {
        $user = $this->makeUser();

        $result = $this->service->computeProgressTracker($user);

        $this->assertSame('Profile', $result['steps'][0]['name']);
        $this->assertSame('profile.edit', $result['steps'][0]['route']);
        $this->assertSame('Preferences', $result['steps'][1]['name']);
        $this->assertSame('preferences.index', $result['steps'][1]['route']);
        $this->assertSame('Find Game', $result['steps'][2]['name']);
        $this->assertSame('games.index', $result['steps'][2]['route']);
        $this->assertSame('Attend Session', $result['steps'][3]['name']);
        $this->assertSame('games.index', $result['steps'][3]['route']);
    }

    // ── computeNearbyPeople ─────────────────────────────────────────────

    public function test_compute_nearby_people_returns_empty_when_no_candidates(): void
    {
        $user = $this->makeUser();

        // Use a geohash in the middle of the ocean
        $result = $this->service->computeNearbyPeople($user, 'zzzz');

        $this->assertCount(0, $result['people']);
        $this->assertSame(0, $result['total_nearby']);
    }

    public function test_compute_nearby_people_finds_nearby_users(): void
    {
        // Create a user in Berlin
        $location = Location::create([
            'name' => 'Berlin Test',
            'city' => 'Berlin',
            'latitude' => 52.52,
            'longitude' => 13.405,
            'geohash_4' => 'u33d',
        ]);

        $nearbyUser = User::create([
            'name' => 'Nearby User',
            'email' => 'nearby-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);

        $system = GameSystem::create(['name' => 'Nearby System', 'slug' => 'nearby-sys-'.uniqid()]);
        $nearbyUser->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $user = $this->makeUser();
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        // Use the geohash for Berlin area
        $geohash4 = Geohash::tilePrefix(52.52, 13.405, 4);

        $result = $this->service->computeNearbyPeople($user, $geohash4);

        $this->assertGreaterThanOrEqual(1, $result['total_nearby']);
        $this->assertGreaterThanOrEqual(1, count($result['people']));

        // Find the nearby user in results
        $found = collect($result['people'])->first(fn ($p) => $p['id'] === $nearbyUser->id);
        $this->assertNotNull($found);
        $this->assertSame('Nearby User', $found['name']);
        $this->assertSame(1, $found['shared_systems_count']);

        // Cleanup
        $nearbyUser->gameSystemPreferences()->detach();
        $user->gameSystemPreferences()->detach();
    }

    public function test_compute_nearby_people_limits_to_six(): void
    {
        $location = Location::create([
            'name' => 'Crowded City',
            'city' => 'Crowded',
            'latitude' => 50.0,
            'longitude' => 10.0,
            'geohash_4' => 'u0zz',
        ]);

        // Create 8 nearby users
        for ($i = 0; $i < 8; $i++) {
            User::create([
                'name' => "Crowded User $i",
                'email' => "crowded-{$i}-".uniqid().'@test.com',
                'password' => bcrypt('password'),
                'profile_complete' => true,
                'location_id' => $location->id,
            ]);
        }

        $user = $this->makeUser();
        $geohash4 = Geohash::tilePrefix(50.0, 10.0, 4);

        $result = $this->service->computeNearbyPeople($user, $geohash4);

        $this->assertLessThanOrEqual(6, count($result['people']));
    }

    // ── computePreferenceWeightedMatches ────────────────────────────────

    public function test_compute_preference_matches_returns_empty_when_no_games(): void
    {
        $user = $this->makeUser();

        $result = $this->service->computePreferenceWeightedMatches($user, 'zzzz');

        $this->assertCount(0, $result['games']);
        $this->assertSame(0, $result['total_nearby']);
        $this->assertSame(0.0, $result['preference_match_rate']);
    }

    public function test_compute_preference_matches_returns_relevance_tags(): void
    {
        // This test creates a full integration scenario
        $location = Location::create([
            'name' => 'Match City',
            'city' => 'Match City',
            'latitude' => 51.0,
            'longitude' => 10.0,
            'geohash_4' => 'u1zz',
        ]);

        $system = GameSystem::create(['name' => 'Match System', 'slug' => 'match-sys-'.uniqid()]);

        $owner = User::create([
            'name' => 'Match Owner',
            'email' => 'match-owner-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
        ]);

        $game = Game::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => 'Match Test Game',
            'description' => 'Match test description',
            'expected_duration' => 2.5,
            'language' => 'en',
            'location' => ['address' => 'Match City', 'lat' => 51.0, 'lng' => 10.0],
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(5),
            'max_players' => 6,
            'location_id' => $location->id,
            'visibility' => 'public',
        ]);

        $user = $this->makeUser();
        $userLocation = $this->makeLocation('User City', 51.0, 10.0);
        $user->setRelation('linkedLocation', $userLocation);
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $geohash4 = Geohash::tilePrefix(51.0, 10.0, 4);

        $result = $this->service->computePreferenceWeightedMatches($user, $geohash4);

        $this->assertGreaterThanOrEqual(1, count($result['games']));

        $matchedGame = collect($result['games'])->first(fn ($g) => $g['id'] === $game->id);
        $this->assertNotNull($matchedGame);
        $this->assertTrue($matchedGame['relevance_tags']['matches_your_taste']);

        $user->gameSystemPreferences()->detach();
    }

    // ── Scoring and relevance tags ──────────────────────────────────────

    public function test_preference_matches_scores_preferred_systems_higher(): void
    {
        $location = Location::create([
            'name' => 'Score City',
            'city' => 'Score City',
            'latitude' => 50.0,
            'longitude' => 10.0,
        ]);

        $preferredSystem = GameSystem::create(['name' => 'Preferred RPG', 'slug' => 'pref-rpg-'.uniqid()]);
        $otherSystem = GameSystem::create(['name' => 'Other RPG', 'slug' => 'other-rpg-'.uniqid()]);

        $owner = User::create([
            'name' => 'Score Owner',
            'email' => 'score-owner-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
        ]);

        // Game matching preferred system
        $preferredGame = Game::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'game_system_id' => $preferredSystem->id,
            'name' => 'Preferred System Game',
            'description' => 'Test game',
            'expected_duration' => 2.0,
            'language' => 'en',
            'location' => ['address' => 'Score City', 'lat' => 50.0, 'lng' => 10.0],
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(5),
            'max_players' => 6,
            'location_id' => $location->id,
            'visibility' => 'public',
        ]);

        // Game NOT matching preferred system
        $otherGame = Game::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'game_system_id' => $otherSystem->id,
            'name' => 'Other System Game',
            'description' => 'Test game',
            'expected_duration' => 2.0,
            'language' => 'en',
            'location' => ['address' => 'Score City', 'lat' => 50.0, 'lng' => 10.0],
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(5),
            'max_players' => 6,
            'location_id' => $location->id,
            'visibility' => 'public',
        ]);

        $user = $this->makeUser();
        $userLocation = $this->makeLocation('User City', 50.0, 10.0);
        $user->setRelation('linkedLocation', $userLocation);
        $user->gameSystemPreferences()->attach($preferredSystem->id, ['preference_type' => 'favorite']);

        $geohash4 = Geohash::tilePrefix(50.0, 10.0, 4);

        $result = $this->service->computePreferenceWeightedMatches($user, $geohash4);

        $this->assertGreaterThanOrEqual(2, count($result['games']));

        $preferredEntry = collect($result['games'])->first(fn ($g) => $g['id'] === $preferredGame->id);
        $otherEntry = collect($result['games'])->first(fn ($g) => $g['id'] === $otherGame->id);

        $this->assertNotNull($preferredEntry);
        $this->assertNotNull($otherEntry);
        $this->assertGreaterThan($otherEntry['score'], $preferredEntry['score'],
            'Game matching preferred system should score higher');

        $user->gameSystemPreferences()->detach();
    }

    public function test_preference_matches_assigns_popular_nearby_tag(): void
    {
        $location = Location::create([
            'name' => 'Popular City',
            'city' => 'Popular City',
            'latitude' => 51.0,
            'longitude' => 10.0,
        ]);

        $system = GameSystem::create(['name' => 'Pop System', 'slug' => 'pop-sys-'.uniqid()]);
        $owner = User::create([
            'name' => 'Pop Owner',
            'email' => 'pop-owner-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
        ]);

        $game = Game::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => 'Popular Game',
            'description' => 'Test',
            'expected_duration' => 2.0,
            'language' => 'en',
            'location' => ['address' => 'Popular City', 'lat' => 51.0, 'lng' => 10.0],
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(5),
            'max_players' => 8,
            'location_id' => $location->id,
            'visibility' => 'public',
        ]);

        // Add 3 approved participants to trigger popular_nearby
        for ($i = 0; $i < 3; $i++) {
            $participant = User::create([
                'name' => "Participant $i",
                'email' => "pop-part-{$i}-".uniqid().'@test.com',
                'password' => bcrypt('password'),
            ]);
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $participant->id,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        $user = $this->makeUser();
        $geohash4 = Geohash::tilePrefix(51.0, 10.0, 4);

        $result = $this->service->computePreferenceWeightedMatches($user, $geohash4);

        $gameResult = collect($result['games'])->first(fn ($g) => $g['id'] === $game->id);
        $this->assertNotNull($gameResult);
        $this->assertTrue($gameResult['relevance_tags']['popular_nearby'],
            'Game with 3+ participants should have popular_nearby tag');
    }

    public function test_preference_matches_assigns_filling_fast_tag(): void
    {
        $location = Location::create([
            'name' => 'Fill City',
            'city' => 'Fill City',
            'latitude' => 49.0,
            'longitude' => 10.0,
        ]);

        $system = GameSystem::create(['name' => 'Fill System', 'slug' => 'fill-sys-'.uniqid()]);
        $owner = User::create([
            'name' => 'Fill Owner',
            'email' => 'fill-owner-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
        ]);

        // max_players = 5, add 3 approved participants → 1 spot left → filling_fast
        $game = Game::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => 'Filling Game',
            'description' => 'Test',
            'expected_duration' => 2.0,
            'language' => 'en',
            'location' => ['address' => 'Fill City', 'lat' => 49.0, 'lng' => 10.0],
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(5),
            'max_players' => 5,
            'location_id' => $location->id,
            'visibility' => 'public',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $participant = User::create([
                'name' => "Fill Part $i",
                'email' => "fill-part-{$i}-".uniqid().'@test.com',
                'password' => bcrypt('password'),
            ]);
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $participant->id,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        $user = $this->makeUser();
        $geohash4 = Geohash::tilePrefix(49.0, 10.0, 4);

        $result = $this->service->computePreferenceWeightedMatches($user, $geohash4);

        $gameResult = collect($result['games'])->first(fn ($g) => $g['id'] === $game->id);
        $this->assertNotNull($gameResult);
        $this->assertTrue($gameResult['relevance_tags']['filling_fast'],
            'Game with ≤2 spots remaining should have filling_fast tag');
    }

    public function test_preference_matches_assigns_starting_soon_tag(): void
    {
        $location = Location::create([
            'name' => 'Soon City',
            'city' => 'Soon City',
            'latitude' => 48.0,
            'longitude' => 10.0,
        ]);

        $system = GameSystem::create(['name' => 'Soon System', 'slug' => 'soon-sys-'.uniqid()]);
        $owner = User::create([
            'name' => 'Soon Owner',
            'email' => 'soon-owner-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
        ]);

        // Game within 3 days → starting_soon
        $game = Game::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => 'Starting Soon Game',
            'description' => 'Test',
            'expected_duration' => 2.0,
            'language' => 'en',
            'location' => ['address' => 'Soon City', 'lat' => 48.0, 'lng' => 10.0],
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(2),
            'max_players' => 6,
            'location_id' => $location->id,
            'visibility' => 'public',
        ]);

        $user = $this->makeUser();
        $geohash4 = Geohash::tilePrefix(48.0, 10.0, 4);

        $result = $this->service->computePreferenceWeightedMatches($user, $geohash4);

        $gameResult = collect($result['games'])->first(fn ($g) => $g['id'] === $game->id);
        $this->assertNotNull($gameResult);
        $this->assertTrue($gameResult['relevance_tags']['starting_soon'],
            'Game within 3 days should have starting_soon tag');
    }

    public function test_nearby_people_sorted_by_compatibility(): void
    {
        $location = Location::create([
            'name' => 'Compat City',
            'city' => 'Compat City',
            'latitude' => 52.0,
            'longitude' => 13.0,
        ]);

        $system1 = GameSystem::create(['name' => 'Compat Sys 1', 'slug' => 'compat-sys1-'.uniqid()]);
        $system2 = GameSystem::create(['name' => 'Compat Sys 2', 'slug' => 'compat-sys2-'.uniqid()]);
        $system3 = GameSystem::create(['name' => 'Compat Sys 3', 'slug' => 'compat-sys3-'.uniqid()]);

        // Viewer likes system1, system2, system3
        $user = $this->makeUser();
        $user->gameSystemPreferences()->attach([
            $system1->id => ['preference_type' => 'favorite'],
            $system2->id => ['preference_type' => 'favorite'],
            $system3->id => ['preference_type' => 'favorite'],
        ]);

        // Candidate A: shares 3 systems (most compatible)
        $candidateA = User::create([
            'name' => 'Three Shared',
            'email' => 'compat-a-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);
        $candidateA->gameSystemPreferences()->attach([
            $system1->id => ['preference_type' => 'favorite'],
            $system2->id => ['preference_type' => 'favorite'],
            $system3->id => ['preference_type' => 'favorite'],
        ]);

        // Candidate B: shares 1 system
        $candidateB = User::create([
            'name' => 'One Shared',
            'email' => 'compat-b-'.uniqid().'@test.com',
            'password' => bcrypt('password'),
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);
        $candidateB->gameSystemPreferences()->attach([
            $system1->id => ['preference_type' => 'favorite'],
        ]);

        $geohash4 = Geohash::tilePrefix(52.0, 13.0, 4);
        $result = $this->service->computeNearbyPeople($user, $geohash4);

        $this->assertGreaterThanOrEqual(2, count($result['people']));

        // Most compatible should come first
        $firstPerson = $result['people'][0];
        $this->assertSame('Three Shared', $firstPerson['name']);
        $this->assertSame(3, $firstPerson['shared_systems_count']);

        // Cleanup
        $user->gameSystemPreferences()->detach();
        $candidateA->gameSystemPreferences()->detach();
        $candidateB->gameSystemPreferences()->detach();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function makeUser(string $name = 'Test User', bool $profileComplete = false): User
    {
        $user = User::create([
            'name' => $name,
            'email' => uniqid().'@test.com',
            'password' => bcrypt('password'),
            'profile_complete' => $profileComplete,
        ]);

        return $user;
    }

    private function makeLocation(string $city, float $lat, float $lng): Location
    {
        $location = new Location;
        $location->city = $city;
        $location->latitude = $lat;
        $location->longitude = $lng;

        return $location;
    }
}
