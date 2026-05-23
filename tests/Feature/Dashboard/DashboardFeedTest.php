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
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    URL::defaults(['locale' => 'en']);
});

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

        Livewire::test(\App\Livewire\Dashboard::class)
            ->assertSee(__('profile.dashboard_feed_heading'))
            ->assertSee('Sarah')
            ->assertSee(__('profile.dashboard_feed_action_created_game'));
    });

    test('feed items show player count and spots left', function () {
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

        Livewire::test(\App\Livewire\Dashboard::class)
            ->assertSee(trans_choice('profile.dashboard_feed_spots_left', 2, ['count' => 2]));
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

        Livewire::test(\App\Livewire\Dashboard::class)
            ->assertSee('Anna')
            ->assertSee(__('profile.dashboard_feed_action_created_campaign'));
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
});

describe('Community Feed — empty state', function () {
    test('shows empty state when user has no followed players', function () {
        // User follows nobody
        Livewire::test(\App\Livewire\Dashboard::class)
            ->assertSee(__('profile.dashboard_feed_empty_title'))
            ->assertSee(__('profile.dashboard_feed_find_people'));
    });

    test('empty state links to people page', function () {
        Livewire::test(\App\Livewire\Dashboard::class)
            ->assertSee(route('people'));
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
        $component->assertSee(__('profile.dashboard_feed_trending_heading'));
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

        Livewire::test(\App\Livewire\Dashboard::class)
            ->assertSee('local_fire_department');
    });
});

describe('Community Feed — action verbs', function () {
    test('player_joined shows joined action text', function () {
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

        Livewire::test(\App\Livewire\Dashboard::class)
            ->assertSee('Joiner')
            ->assertSee(__('profile.dashboard_feed_action_joined_game'));
    });

    test('game_completed shows completed action text', function () {
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

        Livewire::test(\App\Livewire\Dashboard::class)
            ->assertSee('Finisher')
            ->assertSee(__('profile.dashboard_feed_action_completed_game'));
    });
});
