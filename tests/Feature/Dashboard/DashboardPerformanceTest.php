<?php

use App\Enums\RelationshipType;
use App\Jobs\WarmDashboardCache;
use App\Livewire\Dashboard;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\DashboardCacheService;
use App\Services\DashboardModeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    Log::spy();
    URL::defaults(['locale' => 'en']);
});

describe('Dashboard performance', function () {
    test('warm dashboard renders in under 200ms for user with 100 games and 50 follows', function () {
        // Set up a location for the user
        $location = Location::factory()->create([
            'latitude' => 52.5163,
            'longitude' => 13.3777,
        ]);
        $user = User::factory()->create(['location_id' => $location->id]);
        $gameSystem = GameSystem::factory()->create();

        // Create 80 scheduled games and 20 completed games owned by the user
        Game::factory()->count(80)->create([
            'owner_id' => $user->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(7),
        ]);
        Game::factory()->count(20)->create([
            'owner_id' => $user->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'completed',
            'date_time' => now()->subDays(14),
            'expected_duration' => 3.0,
        ]);

        // Create 50 followed users
        $followed = User::factory()->count(50)->create();
        foreach ($followed as $target) {
            UserRelationship::create([
                'user_id' => $user->id,
                'related_user_id' => $target->id,
                'type' => RelationshipType::Follow->value,
            ]);
        }

        // Warm the cache by visiting once
        $this->actingAs($user);
        Livewire::test(Dashboard::class);

        // Now measure warm-cache render time
        $start = microtime(true);
        Livewire::test(Dashboard::class);
        $elapsed = (microtime(true) - $start) * 1000;

        expect($elapsed)->toBeLessThan(200, "Warm dashboard render took {$elapsed}ms — exceeds 200ms threshold");
    });

    test('cold cache fallback completes in under 2s for user with 100 games and 50 follows', function () {
        // Set up a location for the user
        $location = Location::factory()->create([
            'latitude' => 52.5163,
            'longitude' => 13.3777,
        ]);
        $user = User::factory()->create(['location_id' => $location->id]);
        $gameSystem = GameSystem::factory()->create();

        // Create 80 scheduled and 20 completed games
        Game::factory()->count(80)->create([
            'owner_id' => $user->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(7),
        ]);
        Game::factory()->count(20)->create([
            'owner_id' => $user->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'completed',
            'date_time' => now()->subDays(14),
            'expected_duration' => 3.0,
        ]);

        // Create 50 followed users
        $followed = User::factory()->count(50)->create();
        foreach ($followed as $target) {
            UserRelationship::create([
                'user_id' => $user->id,
                'related_user_id' => $target->id,
                'type' => RelationshipType::Follow->value,
            ]);
        }

        // Flush cache — cold start
        Cache::flush();

        $this->actingAs($user);
        $start = microtime(true);
        $component = Livewire::test(Dashboard::class);
        $elapsed = (microtime(true) - $start) * 1000;

        // Verify data was actually computed
        $contributions = $component->viewData('contributions');
        expect($contributions['hosted']['count'])->toBeGreaterThan(0);

        expect($elapsed)->toBeLessThan(2000, "Cold cache render took {$elapsed}ms — exceeds 2s threshold");
    });

    test('cache warm job completes in under 5s for user with 100 games and 50 follows', function () {
        Queue::fake(); // Prevent actual job dispatch during setup

        // Set up a location for the user
        $location = Location::factory()->create([
            'latitude' => 52.5163,
            'longitude' => 13.3777,
        ]);
        $user = User::factory()->create([
            'location_id' => $location->id,
            'created_at' => now()->subDays(31),
        ]);
        $gameSystem = GameSystem::factory()->create();

        // Create 80 scheduled and 20 completed games
        Game::factory()->count(80)->create([
            'owner_id' => $user->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(7),
        ]);
        Game::factory()->count(20)->create([
            'owner_id' => $user->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'completed',
            'date_time' => now()->subDays(14),
            'expected_duration' => 3.0,
        ]);

        // Create 50 followed users
        $followed = User::factory()->count(50)->create();
        foreach ($followed as $target) {
            UserRelationship::create([
                'user_id' => $user->id,
                'related_user_id' => $target->id,
                'type' => RelationshipType::Follow->value,
            ]);
        }

        // Now execute the warm job synchronously and measure
        $cacheService = app(DashboardCacheService::class);
        $modeService = app(DashboardModeService::class);
        $start = microtime(true);
        $job = new WarmDashboardCache((string) $user->id, 'cache_miss_week');
        $job->handle($cacheService, $modeService);
        $elapsed = (microtime(true) - $start) * 1000;

        // Verify cache was populated
        expect(Cache::get("dashboard:contributions:{$user->id}"))->not->toBeNull();
        expect(Cache::get("dashboard:feed:{$user->id}"))->not->toBeNull();

        expect($elapsed)->toBeLessThan(5000, "Warm job took {$elapsed}ms — exceeds 5s threshold");
    });
});
