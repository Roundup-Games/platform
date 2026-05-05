<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use function Pest\Laravel\{actingAs};

describe('GameListing', function () {
    it('lists public scheduled upcoming games', function () {
        $game = Game::factory()->create([
            'name' => 'Epic Adventure',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->assertSee('Epic Adventure');
    })->group('smoke');

    it('hides private games from guests', function () {
        Game::factory()->create([
            'name' => 'Secret Session',
            'visibility' => 'private',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->assertDontSee('Secret Session');
    })->group('smoke');

    it('hides protected games from strangers', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $stranger = User::factory()->create(['profile_complete' => true]);

        Game::factory()->create([
            'name' => 'Protected Game',
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'owner_id' => $owner->id,
        ]);

        actingAs($stranger);
        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->assertDontSee('Protected Game');
    });

    it('shows protected games to friends of the owner', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $friend = User::factory()->create(['profile_complete' => true]);

        // Mutual follow = friendship
        \App\Models\UserRelationship::follow($owner, $friend);
        \App\Models\UserRelationship::follow($friend, $owner);

        Game::factory()->create([
            'name' => 'Friends Only Game',
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'owner_id' => $owner->id,
        ]);

        actingAs($friend);
        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->assertSee('Friends Only Game');
    });

    it('hides canceled games', function () {
        Game::factory()->create([
            'name' => 'Canceled Game',
            'visibility' => 'public',
            'status' => 'canceled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->assertDontSee('Canceled Game');
    });

    it('hides completed games', function () {
        Game::factory()->create([
            'name' => 'Completed Game',
            'visibility' => 'public',
            'status' => 'completed',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->assertDontSee('Completed Game');
    });

    it('hides past games', function () {
        Game::factory()->create([
            'name' => 'Past Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->subDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->assertDontSee('Past Game');
    });

    it('searches by name', function () {
        Game::factory()->create(['name' => 'Dragon Slayer', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['name' => 'Castle Siege', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->set('search', 'Dragon')
            ->assertSee('Dragon Slayer')
            ->assertDontSee('Castle Siege');
    });

    it('searches by description', function () {
        Game::factory()->create(['name' => 'Game A', 'description' => 'A thrilling dungeon crawl experience', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['name' => 'Game B', 'description' => 'A relaxing farming simulation', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->set('search', 'dungeon crawl')
            ->assertSee('Game A')
            ->assertDontSee('Game B');
    });

    it('escapes SQL wildcards in search', function () {
        Game::factory()->create(['name' => 'Real Game', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['name' => '100% Fun Game', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->set('search', '100%')
            ->assertDontSee('Real Game');
    });

    it('filters by game system', function () {
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);
        Game::factory()->create(['name' => 'D&D Game', 'game_system_id' => $system->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['name' => 'Other Game', 'game_system_id' => GameSystem::factory()->create(['name' => 'Pathfinder'])->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->set('game_system_id', $system->id)
            ->assertSee('D&D Game')
            ->assertDontSee('Other Game');
    });

    it('filters by experience level', function () {
        Game::factory()->create(['name' => 'Beginner Game', 'experience_level' => 'beginner', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['name' => 'Advanced Game', 'experience_level' => 'advanced', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->set('experience_level', 'beginner')
            ->assertSee('Beginner Game')
            ->assertDontSee('Advanced Game');
    });

    it('filters by vibe flags using JSON_CONTAINS', function () {
        Game::factory()->create(['name' => 'Cozy Game', 'vibe_flags' => ['cooperative', 'lighthearted'], 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['name' => 'Hardcore Game', 'vibe_flags' => ['competitive', 'rules-heavy'], 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->call('toggleVibeFlag', 'cooperative')
            ->assertSee('Cozy Game')
            ->assertDontSee('Hardcore Game');
    });

    it('filters by multiple vibe flags (AND logic)', function () {
        Game::factory()->create(['name' => 'Coop Light', 'vibe_flags' => ['cooperative', 'lighthearted'], 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['name' => 'Coop Only', 'vibe_flags' => ['cooperative'], 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->call('toggleVibeFlag', 'cooperative')
            ->call('toggleVibeFlag', 'lighthearted')
            ->assertSee('Coop Light')
            ->assertDontSee('Coop Only');
    });

    it('toggles vibe flags off', function () {
        $component = Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->call('toggleVibeFlag', 'cooperative')
            ->assertSet('vibe_flags', ['cooperative'])
            ->call('toggleVibeFlag', 'cooperative')
            ->assertSet('vibe_flags', []);
    });

    it('filters by language', function () {
        Game::factory()->create(['name' => 'English Game', 'language' => 'en', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['name' => 'German Game', 'language' => 'de', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->set('language', 'de')
            ->assertSee('German Game')
            ->assertDontSee('English Game');
    });

    it('filters by date this_week', function () {
        Game::factory()->create(['name' => 'This Week Game', 'date_time' => min(now()->endOfWeek()->subHour(), now()->addDays(5)), 'visibility' => 'public', 'status' => 'scheduled']);
        Game::factory()->create(['name' => 'Next Month Game', 'date_time' => now()->addMonths(2), 'visibility' => 'public', 'status' => 'scheduled']);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->set('date', 'this_week')
            ->assertSee('This Week Game')
            ->assertDontSee('Next Month Game');
    });

    it('filters by date this_month', function () {
        Game::factory()->create(['name' => 'This Month Game', 'date_time' => now()->addDays(3), 'visibility' => 'public', 'status' => 'scheduled']);
        Game::factory()->create(['name' => 'Far Future Game', 'date_time' => now()->addMonths(3), 'visibility' => 'public', 'status' => 'scheduled']);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->set('date', 'this_month')
            ->assertSee('This Month Game')
            ->assertDontSee('Far Future Game');
    });

    it('filters free games', function () {
        Game::factory()->create(['name' => 'Free Game', 'price' => 0, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['name' => 'Paid Game', 'price' => 10, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->set('price', 'free')
            ->assertSee('Free Game')
            ->assertDontSee('Paid Game');
    });

    it('filters paid games', function () {
        Game::factory()->create(['name' => 'Free Game', 'price' => 0, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['name' => 'Paid Game', 'price' => 10, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->set('price', 'paid')
            ->assertSee('Paid Game')
            ->assertDontSee('Free Game');
    });

    it('filters by complexity range', function () {
        Game::factory()->create(['name' => 'Simple Game', 'complexity' => 1.5, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['name' => 'Complex Game', 'complexity' => 4.5, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->set('complexity_min', '4.0')
            ->assertSee('Complex Game')
            ->assertDontSee('Simple Game');
    });

    it('clears all filters', function () {
        Game::factory()->create(['name' => 'Beginner Game', 'experience_level' => 'beginner', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['name' => 'Advanced Game', 'experience_level' => 'advanced', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(3)]);

        Livewire\Livewire::test(App\Livewire\Games\GameListing::class)
            ->set('experience_level', 'beginner')
            ->assertDontSee('Advanced Game')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('game_system_id', null)
            ->assertSet('experience_level', '')
            ->assertSet('vibe_flags', [])
            ->assertSet('language', '')
            ->assertSet('date', '')
            ->assertSet('price', '')
            ->assertSet('complexity_min', null)
            ->assertSet('complexity_max', null)
            ->assertSee('Beginner Game')
            ->assertSee('Advanced Game');
    });

    it('paginates results at 12 per page', function () {
        Game::factory()->count(15)->create(['visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);

        $component = Livewire\Livewire::test(App\Livewire\Games\GameListing::class);
        $games = $component->viewData('games');

        expect($games->count())->toBe(12);
        expect($games->hasMorePages())->toBeTrue();
    });

});
