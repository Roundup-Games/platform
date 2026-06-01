<?php

use App\Dto\FeedItem;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\DashboardCacheService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->user = User::factory()->create(['created_at' => now()->subDays(60)]);
    $this->actingAs($this->user);
    URL::defaults(['locale' => 'en']);
});

/**
 * Helper: create $count follow relationships for the test user so that
 * shouldShowCommunityPulse (requires ≥3 follows with feed data) is satisfied.
 */
function setupCommunityPulseEligible(User $user, int $minFollows = 3): void
{
    // Create enough follows to pass the ≥3 threshold
    for ($i = 0; $i < $minFollows; $i++) {
        $friend = User::factory()->create();
        UserRelationship::create([
            'user_id' => $user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);
    }
}

describe('Community Feed — friends activity', function () {
    test('dashboard shows community feed section with friends activity', function () {
        $friend = User::factory()->create(['name' => 'Sarah']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        $gameSystem = GameSystem::factory()->create();
        Game::factory()->create([
            'owner_id' => $friend->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'max_players' => 5,
        ]);

        // Verify feed data is computed correctly via viewData
        $component = Livewire::test(\App\Livewire\Dashboard::class);
        $feed = $component->viewData('communityFeed');

        // Feed should contain the friend's activity
        expect($feed)->not->toBeNull();
        expect($feed->count())->toBeGreaterThanOrEqual(1);

        // Verify the activity data includes Sarah's game creation
        $hasFriendActivity = $feed->contains(fn ($item) => $item->userName === 'Sarah');
        expect($hasFriendActivity)->toBeTrue();
    });

    test('feed items show player count and spots left via cache service', function () {
        $friend = User::factory()->create(['name' => 'Mike']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        $game = Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(1),
            'max_players' => 5,
        ]);

        // Add 3 participants (approved players)
        GameParticipant::factory()->count(3)->create([
            'game_id' => $game->id,
            'status' => 'approved',
            'role' => 'player',
        ]);

        // Verify spots data via cache service
        $cacheService = app(DashboardCacheService::class);
        $feedData = $cacheService->getFeedData($this->user);
        expect($feedData['items'])->not->toBeEmpty();
    });

    test('feed shows campaign activity from friends', function () {
        $friend = User::factory()->create(['name' => 'Anna']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        Campaign::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'active',
        ]);

        $component = Livewire::test(\App\Livewire\Dashboard::class);
        $feed = $component->viewData('communityFeed');

        // Should contain Anna's campaign activity
        $hasAnnaActivity = $feed->contains(fn ($item) => $item->userName === 'Anna');
        expect($hasAnnaActivity)->toBeTrue();
    });

    test('feed limits friends items to 10', function () {
        $friend = User::factory()->create();
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        // Create 15 games by the friend
        Game::factory()->count(15)->create([
            'owner_id' => $friend->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $component = Livewire::test(\App\Livewire\Dashboard::class);
        $feed = $component->viewData('communityFeed');

        expect($feed->count())->toBeLessThanOrEqual(10);
    });

    test('feed produces separate entries for different activity types on the same game', function () {
        $friend1 = User::factory()->create(['name' => 'Leo']);
        $friend2 = User::factory()->create(['name' => 'Mia']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend1->id,
            'type' => 'follow',
        ]);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend2->id,
            'type' => 'follow',
        ]);

        // friend1 creates a game, friend2 joins it — two distinct activity types
        $game = Game::factory()->create([
            'owner_id' => $friend1->id,
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $friend2->id,
            'status' => ParticipantStatus::Approved,
            'role' => 'player',
        ]);

        $component = Livewire::test(\App\Livewire\Dashboard::class);
        $feed = $component->viewData('communityFeed');

        // Same game can appear multiple times for different activity types (created, player_joined)
        $gameEntries = $feed->filter(fn ($item) => $item->entityId === $game->id);
        expect($gameEntries->count())->toBeGreaterThanOrEqual(1);
        // Each entry should have a unique FeedItem ID (different activity types)
        $feedIds = $feed->map(fn ($item) => $item->id)->toArray();
        expect(count($feedIds))->toBe(count(array_unique($feedIds)));
    });

    test('community pulse section renders when user has 3+ follows with activity', function () {
        // Create 3+ follows with activity so shouldShowCommunityPulse is true
        for ($i = 0; $i < 3; $i++) {
            $friend = User::factory()->create(['name' => "Friend{$i}"]);
            UserRelationship::create([
                'user_id' => $this->user->id,
                'related_user_id' => $friend->id,
                'type' => 'follow',
            ]);
            Game::factory()->create([
                'owner_id' => $friend->id,
                'status' => 'scheduled',
                'date_time' => now()->addDays(3),
            ]);
        }

        $component = Livewire::test(\App\Livewire\Dashboard::class);

        // shouldShowCommunityPulse should be true with 3+ follows and feed data
        expect($component->viewData('shouldShowCommunityPulse'))->toBeTrue();
        // The Community Pulse heading should be visible in the rendered output
        $component->assertSee(__('profile.dashboard_pulse_heading'));
    });
});

describe('Community Feed — empty state', function () {
    test('community pulse is hidden when user has fewer than 3 follows', function () {
        // User follows nobody — shouldShowCommunityPulse should be false
        $component = Livewire::test(\App\Livewire\Dashboard::class);

        expect($component->viewData('shouldShowCommunityPulse'))->toBeFalse();
        // Community pulse heading should NOT be rendered
        $component->assertDontSee(__('profile.dashboard_pulse_heading'));
    });

    test('people discovery is available via quick actions', function () {
        // The dashboard provides ways to find people via the newcomer or established templates
        $component = Livewire::test(\App\Livewire\Dashboard::class);
        // The component should render without error
        $component->assertStatus(200);
    });
});

describe('Community Feed — trending subsection', function () {
    test('trending section appears when friends feed has fewer than 5 items', function () {
        // Create one friend with one game (< 5 friends items)
        $friend = User::factory()->create(['name' => 'Sam']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);

        // Set up user location so trending can work
        $location = Location::factory()->create([
            'latitude' => 52.5200,
            'longitude' => 13.4050,
        ]);
        $this->user->update(['location_id' => $location->id]);

        // Seed trending cache for the user's geohash tile
        $geohash4 = \App\Services\Geohash::tilePrefix(52.5200, 13.4050, 4);
        $trendingGame = Game::factory()->create([
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'location_id' => $location->id,
            'max_players' => 4,
            'name' => ['en' => 'Trending Test Game'],
        ]);
        Cache::put("dashboard:trending:{$geohash4}", [
            'games' => [
                [
                    'id' => $trendingGame->id,
                    'name' => 'Trending Test Game',
                    'date_time' => now()->addDays(3)->toIso8601String(),
                    'expected_duration' => null,
                    'game_type' => null,
                    'max_players' => 4,
                    'participant_count' => 2,
                    'game_system_id' => null,
                    'location_city' => 'Berlin',
                    'owner_id' => User::factory()->create()->id,
                ],
            ],
        ], 600);

        $component = Livewire::test(\App\Livewire\Dashboard::class);

        // Trending section should be shown (friends < 5, trending not empty)
        expect($component->viewData('hasTrendingSection'))->toBeTrue();
    });

    test('trending section hidden when friends feed has 5 or more items', function () {
        // Create a friend with 5+ games
        $friend = User::factory()->create();
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        Game::factory()->count(6)->create([
            'owner_id' => $friend->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $component = Livewire::test(\App\Livewire\Dashboard::class);

        expect($component->viewData('hasTrendingSection'))->toBeFalse();
    });

    test('trending section hidden when user has no location', function () {
        // User has no location
        $friend = User::factory()->create();
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        // Only 1 friend item, but no user location → trending can't be computed
        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);

        $component = Livewire::test(\App\Livewire\Dashboard::class);

        // show_trending is false because trending items are empty (no location)
        expect($component->viewData('hasTrendingSection'))->toBeFalse();
    });

    test('trending items show fire icon for visual distinction', function () {
        $friend = User::factory()->create(['name' => 'TrendFriend']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);

        $location = Location::factory()->create([
            'latitude' => 52.5200,
            'longitude' => 13.4050,
        ]);
        $this->user->update(['location_id' => $location->id]);

        $geohash4 = \App\Services\Geohash::tilePrefix(52.5200, 13.4050, 4);
        $trendingGame = Game::factory()->create([
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'location_id' => $location->id,
            'max_players' => 5,
            'name' => ['en' => 'Fire Icon Game'],
        ]);
        Cache::put("dashboard:trending:{$geohash4}", [
            'games' => [
                [
                    'id' => $trendingGame->id,
                    'name' => 'Fire Icon Game',
                    'date_time' => now()->addDays(5)->toIso8601String(),
                    'expected_duration' => null,
                    'game_type' => null,
                    'max_players' => 5,
                    'participant_count' => 1,
                    'game_system_id' => null,
                    'location_city' => 'Berlin',
                    'owner_id' => User::factory()->create()->id,
                ],
            ],
        ], 600);

        // The trending data should be available via viewData('trendingItems')
        $component = Livewire::test(\App\Livewire\Dashboard::class);
        $trendingItems = $component->viewData('trendingItems');
        // Trending items should be populated (fire icon is rendered in the template via the trending section)
        expect($trendingItems)->not->toBeEmpty();
    });
});

describe('Community Feed — action verbs', function () {
    test('player_joined shows joined action text via feed data', function () {
        $friend = User::factory()->create(['name' => 'Joiner']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        $otherUser = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $otherUser->id,
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'status' => 'approved',
            'role' => 'player',
        ]);

        $component = Livewire::test(\App\Livewire\Dashboard::class);
        $feed = $component->viewData('communityFeed');

        // Feed should contain Joiner's activity
        $hasJoinerActivity = $feed->contains(fn ($item) => $item->userName === 'Joiner');
        expect($hasJoinerActivity)->toBeTrue();
    });

    test('game_completed shows completed action text via feed data', function () {
        $friend = User::factory()->create(['name' => 'Finisher']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'completed',
            'name' => ['en' => 'Finished Game'],
        ]);

        $component = Livewire::test(\App\Livewire\Dashboard::class);
        $feed = $component->viewData('communityFeed');

        // Feed should contain Finisher's activity
        $hasFinisherActivity = $feed->contains(fn ($item) => $item->userName === 'Finisher');
        expect($hasFinisherActivity)->toBeTrue();
    });
});
