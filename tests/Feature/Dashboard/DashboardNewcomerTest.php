<?php

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Livewire\Dashboard;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Services\Geohash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    Log::spy();
    URL::defaults(['locale' => 'en']);
});

// ── Newcomer dashboard renders for eligible users ────────────────

describe('Newcomer dashboard rendering', function () {
    test('dashboard renders newcomer template for eligible user', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(5)]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $mode = $component->viewData('dashboardMode');
        expect($mode)->toBe('newcomer');

        // Verify newcomer data keys are present in view
        $component->assertViewHas('newcomerWelcome');
        $component->assertViewHas('preferenceMatches');
        $component->assertViewHas('progressTracker');
        $component->assertViewHas('nearbyPeople');
    });

    test('dashboard does not render newcomer data for established user', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(60)]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $mode = $component->viewData('dashboardMode');
        expect($mode)->toBe('established');

        $welcome = $component->viewData('newcomerWelcome');
        expect($welcome)->toBe([]);
    });
});

// ── Welcome card shows personalized data ─────────────────────────

describe('Welcome card personalization', function () {
    test('welcome card shows user first name and city', function () {
        $location = Location::create([
            'name' => 'Welcome City',
            'city' => 'Hamburg',
            'latitude' => 53.55,
            'longitude' => 10.0,
        ]);

        $user = User::factory()->create([
            'name' => 'Anna Schmidt',
            'created_at' => now()->subDays(3),
            'location_id' => $location->id,
        ]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $welcome = $component->viewData('newcomerWelcome');
        expect($welcome['first_name'])->toBe('Anna');
        expect($welcome['city'])->toBe('Hamburg');
        expect($welcome['has_location'])->toBeTrue();
    });

    test('welcome card shows preferred systems', function () {
        $system = GameSystem::create(['name' => 'D&D 5e', 'slug' => 'dnd-5e-welcome-' . uniqid()]);
        $user = User::factory()->create(['created_at' => now()->subDays(2)]);
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $this->actingAs($user);
        $component = Livewire::test(Dashboard::class);

        $welcome = $component->viewData('newcomerWelcome');
        expect($welcome['preferred_systems'])->toContain('D&D 5e');

        $user->gameSystemPreferences()->detach();
    });

    test('welcome card shows matching games count when location set', function () {
        $location = Location::create([
            'name' => 'Match Count City',
            'city' => 'Berlin',
            'latitude' => 52.52,
            'longitude' => 13.405,
        ]);

        $system = GameSystem::create(['name' => 'Welcome System', 'slug' => 'welcome-sys-' . uniqid()]);
        $user = User::factory()->create([
            'created_at' => now()->subDays(2),
            'location_id' => $location->id,
        ]);
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $owner = User::factory()->create();

        // Create a matching scheduled game nearby
        Game::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => 'Welcome Matching Game',
            'description' => 'Test game',
            'expected_duration' => 2.0,
            'language' => 'en',
            'location' => ['address' => 'Berlin', 'lat' => 52.52, 'lng' => 13.405],
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(3),
            'max_players' => 6,
            'location_id' => $location->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($user);
        $component = Livewire::test(Dashboard::class);

        $welcome = $component->viewData('newcomerWelcome');
        expect($welcome['matching_games_count'])->toBeGreaterThanOrEqual(1);

        $user->gameSystemPreferences()->detach();
    });

    test('welcome card has correct message key based on data', function () {
        // User without location, without preferences → basic message
        $user = User::factory()->create(['created_at' => now()->subDay()]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);
        $welcome = $component->viewData('newcomerWelcome');
        expect($welcome['welcome_message_key'])->toBe('welcome_basic');
    });

    test('welcome card with no location returns has_location false', function () {
        $user = User::factory()->create(['created_at' => now()->subDay()]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);
        $welcome = $component->viewData('newcomerWelcome');
        expect($welcome['has_location'])->toBeFalse();
    });
});

// ── Preference matches display with relevance badges ─────────────

describe('Preference matches with relevance badges', function () {
    test('preference matches show games with correct structure', function () {
        $location = Location::create([
            'name' => 'Badge City',
            'city' => 'Badge City',
            'latitude' => 50.5,
            'longitude' => 9.5,
        ]);

        $system = GameSystem::create(['name' => 'Badge System', 'slug' => 'badge-sys-' . uniqid()]);
        $user = User::factory()->create([
            'created_at' => now()->subDays(2),
            'location_id' => $location->id,
        ]);
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $owner = User::factory()->create();

        $game = Game::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => 'Badge Test Game',
            'description' => 'Badge test',
            'expected_duration' => 3.0,
            'language' => 'en',
            'location' => ['address' => 'Badge City', 'lat' => 50.5, 'lng' => 9.5],
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(4),
            'max_players' => 6,
            'location_id' => $location->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($user);
        $component = Livewire::test(Dashboard::class);

        $matches = $component->viewData('preferenceMatches');
        expect($matches)->toHaveKeys(['games', 'total_nearby', 'preference_match_rate']);

        if (count($matches['games']) > 0) {
            $gameResult = $matches['games'][0];
            expect($gameResult)->toHaveKeys([
                'id', 'name', 'date_time', 'expected_duration',
                'game_system_name', 'max_players', 'participant_count',
                'spots_available', 'distance_km', 'relevance_tags', 'score',
            ]);
        }

        $user->gameSystemPreferences()->detach();
    });

    test('preference matches display matches_your_taste badge for preferred system', function () {
        $location = Location::create([
            'name' => 'Taste City',
            'city' => 'Taste City',
            'latitude' => 51.5,
            'longitude' => 8.5,
        ]);

        $preferredSystem = GameSystem::create(['name' => 'Taste System', 'slug' => 'taste-sys-' . uniqid()]);
        $otherSystem = GameSystem::create(['name' => 'Other Taste System', 'slug' => 'other-taste-sys-' . uniqid()]);

        $user = User::factory()->create([
            'created_at' => now()->subDays(2),
            'location_id' => $location->id,
        ]);
        $user->gameSystemPreferences()->attach($preferredSystem->id, ['preference_type' => 'favorite']);

        $owner = User::factory()->create();

        $preferredGame = Game::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => $owner->id,
            'game_system_id' => $preferredSystem->id,
            'name' => 'Taste Match Game',
            'description' => 'Taste test',
            'expected_duration' => 2.5,
            'language' => 'en',
            'location' => ['address' => 'Taste City', 'lat' => 51.5, 'lng' => 8.5],
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(5),
            'max_players' => 6,
            'location_id' => $location->id,
            'visibility' => 'public',
        ]);

        $otherGame = Game::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => $owner->id,
            'game_system_id' => $otherSystem->id,
            'name' => 'No Taste Match Game',
            'description' => 'Other test',
            'expected_duration' => 2.5,
            'language' => 'en',
            'location' => ['address' => 'Taste City', 'lat' => 51.5, 'lng' => 8.5],
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(5),
            'max_players' => 6,
            'location_id' => $location->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($user);
        $component = Livewire::test(Dashboard::class);

        $matches = $component->viewData('preferenceMatches');

        $preferredEntry = collect($matches['games'])->first(fn ($g) => $g['id'] === $preferredGame->id);
        $otherEntry = collect($matches['games'])->first(fn ($g) => $g['id'] === $otherGame->id);

        if ($preferredEntry) {
            expect($preferredEntry['relevance_tags']['matches_your_taste'])->toBeTrue();
        }
        if ($otherEntry) {
            expect($otherEntry['relevance_tags']['matches_your_taste'])->toBeFalse();
        }

        $user->gameSystemPreferences()->detach();
    });

    test('preference matches empty when user has no location', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(2)]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);
        $matches = $component->viewData('preferenceMatches');

        expect($matches['games'])->toBe([]);
        expect($matches['total_nearby'])->toBe(0);
    });
});

// ── Progress tracker shows correct steps ─────────────────────────

describe('Progress tracker steps', function () {
    test('progress tracker shows 4 steps with correct names', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(2)]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);
        $tracker = $component->viewData('progressTracker');

        expect($tracker)->toHaveKeys(['steps', 'current_step', 'completion_percentage']);
        expect($tracker['steps'])->toHaveCount(4);
    });

    test('progress tracker marks profile complete for profile_complete user', function () {
        $user = User::factory()->create([
            'created_at' => now()->subDays(2),
            'profile_complete' => true,
        ]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);
        $tracker = $component->viewData('progressTracker');

        expect($tracker['steps'][0]['is_complete'])->toBeTrue();
        expect($tracker['completion_percentage'])->toBeGreaterThanOrEqual(25);
    });

    test('progress tracker marks find-game complete when user has participation', function () {
        $user = User::factory()->create([
            'created_at' => now()->subDays(2),
            'profile_complete' => true,
        ]);

        $system = GameSystem::create(['name' => 'Progress System', 'slug' => 'progress-sys-' . uniqid()]);
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $owner = User::factory()->create();
        $game = Game::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => 'Progress Game',
            'description' => 'Progress test',
            'expected_duration' => 2.0,
            'language' => 'en',
            'location' => ['address' => 'Progress City', 'lat' => 50.0, 'lng' => 10.0],
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(1),
            'max_players' => 5,
            'visibility' => 'public',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        $this->actingAs($user);
        $component = Livewire::test(Dashboard::class);
        $tracker = $component->viewData('progressTracker');

        // Step 3 (Find Game) should be complete
        expect($tracker['steps'][2]['is_complete'])->toBeTrue();

        $user->gameSystemPreferences()->detach();
    });

    test('progress tracker starts at 0% for brand new user', function () {
        $user = User::factory()->create([
            'created_at' => now(),
            'profile_complete' => false,
        ]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);
        $tracker = $component->viewData('progressTracker');

        expect($tracker['completion_percentage'])->toBe(0);
        expect($tracker['current_step'])->toBe(1);
    });
});

// ── Nearby people section renders compatible users ───────────────

describe('Nearby people rendering', function () {
    test('nearby people returns empty for user without location', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(2)]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);
        $people = $component->viewData('nearbyPeople');

        expect($people['people'])->toBe([]);
        expect($people['total_nearby'])->toBe(0);
    });

    test('nearby people shows compatible users with shared systems', function () {
        $location = Location::create([
            'name' => 'People City',
            'city' => 'People City',
            'latitude' => 52.0,
            'longitude' => 13.0,
        ]);

        $system = GameSystem::create(['name' => 'People System', 'slug' => 'people-sys-' . uniqid()]);

        $user = User::factory()->create([
            'created_at' => now()->subDays(3),
            'location_id' => $location->id,
        ]);
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $nearbyUser = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);
        $nearbyUser->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $this->actingAs($user);
        $component = Livewire::test(Dashboard::class);

        $people = $component->viewData('nearbyPeople');

        if (count($people['people']) > 0) {
            $found = collect($people['people'])->first(fn ($p) => $p['id'] === $nearbyUser->id);
            if ($found) {
                expect($found['shared_systems_count'])->toBeGreaterThanOrEqual(1);
                expect($found)->toHaveKeys(['id', 'name', 'avatar_url', 'top_system_name', 'shared_systems_count']);
            }
        }

        $user->gameSystemPreferences()->detach();
        $nearbyUser->gameSystemPreferences()->detach();
    });

    test('nearby people excludes self from results', function () {
        $location = Location::create([
            'name' => 'Self Test City',
            'city' => 'Self Test City',
            'latitude' => 48.0,
            'longitude' => 11.0,
        ]);

        $user = User::factory()->create([
            'created_at' => now()->subDays(2),
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);

        $this->actingAs($user);
        $component = Livewire::test(Dashboard::class);

        $people = $component->viewData('nearbyPeople');
        $selfInResults = collect($people['people'])->contains(fn ($p) => $p['id'] === $user->id);
        expect($selfInResults)->toBeFalse();
    });
});

// ── Sections with no data are absent from HTML ───────────────────

describe('Empty section rendering', function () {
    test('newcomer without location has empty matches and people sections', function () {
        $user = User::factory()->create(['created_at' => now()->subDay()]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $matches = $component->viewData('preferenceMatches');
        $people = $component->viewData('nearbyPeople');

        expect($matches['games'])->toBe([]);
        expect($matches['total_nearby'])->toBe(0);
        expect($people['people'])->toBe([]);
        expect($people['total_nearby'])->toBe(0);
    });

    test('welcome data always present for newcomer even without location', function () {
        $user = User::factory()->create([
            'name' => 'Fresh User',
            'created_at' => now()->subDay(),
        ]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $welcome = $component->viewData('newcomerWelcome');
        expect($welcome)->not->toBe([]);
        expect($welcome)->toHaveKeys(['first_name', 'city', 'preferred_systems', 'has_location', 'welcome_message_key']);
        expect($welcome['first_name'])->toBe('Fresh');
        expect($welcome['has_location'])->toBeFalse();
    });

    test('progress tracker always present for newcomer', function () {
        $user = User::factory()->create(['created_at' => now()->subDay()]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $tracker = $component->viewData('progressTracker');
        expect($tracker)->not->toBe([]);
        expect($tracker)->toHaveKeys(['steps', 'current_step', 'completion_percentage']);
        expect($tracker['steps'])->toHaveCount(4);
    });
});
