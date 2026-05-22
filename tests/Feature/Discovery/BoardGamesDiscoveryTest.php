<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Models\User;
use function Pest\Laravel\{actingAs};

describe('BoardGamesDiscovery', function () {
    it('shows only games — no campaigns appear', function () {
        Game::factory()->create([
            'name' => ['en' => 'Public Board Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => ['en' => 'Hidden Campaign'],
            'visibility' => 'public',
            'status' => 'active',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSee('Public Board Game')
            ->assertDontSee('Hidden Campaign');
    });

    it('filters by game system', function () {
        $system1 = GameSystem::factory()->create(['name' => ['en' => 'Ticket to Ride']]);
        $system2 = GameSystem::factory()->create(['name' => ['en' => 'Wingspan']]);

        Game::factory()->create([
            'name' => ['en' => 'TTR Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system1->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Wingspan Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $system2->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('game_system_id', $system1->id)
            ->assertSee('TTR Game')
            ->assertDontSee('Wingspan Game');
    });

    it('shows recommendations scoped to boardgame systems for logged-in users', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $boardgameSystem = GameSystem::factory()->create(['type' => 'boardgame']);
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);

        $user->favoriteGameSystems()->attach($boardgameSystem->id, ['preference_type' => 'favorite']);
        $user->favoriteGameSystems()->attach($ttrpgSystem->id, ['preference_type' => 'favorite']);

        // Only create a boardgame system game — recommendations should include it
        Game::factory()->create([
            'name' => ['en' => 'Recommended Board Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $boardgameSystem->id,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSee('Recommended for You')
            ->assertSee('Recommended Board Game');
    });

    it('recommendations exclude ttrpg systems even when favorited', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);

        $user->favoriteGameSystems()->attach($ttrpgSystem->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => ['en' => 'TTRPG Only Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $ttrpgSystem->id,
        ]);

        actingAs($user);
        // With only a ttrpg system favorited, no recommendations should appear
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertDontSee('Recommended for You');
    });

    it('filters by experience level', function () {
        Game::factory()->create([
            'name' => ['en' => 'Beginner Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'experience_level' => 'beginner',
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Expert Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'experience_level' => 'expert',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('experience_level', 'beginner')
            ->assertSee('Beginner Game')
            ->assertDontSee('Expert Game');
    });

    it('filters by language', function () {
        Game::factory()->create([
            'name' => ['en' => 'English Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'language' => 'en',
        ]);

        Game::factory()->create([
            'name' => ['en' => 'German Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'language' => 'de',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('language', 'en')
            ->assertSee('English Game')
            ->assertDontSee('German Game');
    });

    it('filters by complexity range', function () {
        Game::factory()->create([
            'name' => ['en' => 'Light Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'complexity' => 1.5,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Heavy Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'complexity' => 4.5,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('complexity_max', '2')
            ->assertSee('Light Game')
            ->assertDontSee('Heavy Game');
    });

    it('recommendations only include items matching favorite boardgame systems', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $favSystem = GameSystem::factory()->create(['type' => 'boardgame']);
        $otherSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        $user->favoriteGameSystems()->attach($favSystem->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => ['en' => 'Fav System Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $favSystem->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Other System Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $otherSystem->id,
        ]);

        actingAs($user);
        // Verify recommendations contain only the favorite system game
        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $recommendations = $component->viewData('recommendations');
        expect($recommendations)->not->toBeNull();
        $recNames = collect($recommendations)->pluck('name')->toArray();
        expect($recNames)->toContain('Fav System Game');
        expect($recNames)->not->toContain('Other System Game');
    });

    it('recommendations exclude avoided game systems', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $favSystem = GameSystem::factory()->create(['type' => 'boardgame']);
        $avoidSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        $user->favoriteGameSystems()->attach($favSystem->id, ['preference_type' => 'favorite']);
        $user->favoriteGameSystems()->attach($avoidSystem->id, ['preference_type' => 'avoid']);

        Game::factory()->create([
            'name' => ['en' => 'Fav Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $favSystem->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Avoid Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $avoidSystem->id,
        ]);

        actingAs($user);
        // Avoided system game should not appear in recommendations
        // but may still appear in main results
        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $recommendations = $component->viewData('recommendations');
        expect($recommendations)->not->toBeEmpty('Expected recommendations for user with fav+avoid systems');

        $recNames = collect($recommendations)->pluck('name')->toArray();
        expect($recNames)->toContain('Fav Game');
        expect($recNames)->not->toContain('Avoid Game');
    });

    it('recommendations do not include campaigns even when system matches', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);

        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => ['en' => 'Recommended Board Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Campaign::factory()->create([
            'name' => ['en' => 'Hidden Campaign'],
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $system->id,
        ]);

        actingAs($user);
        // Recommendations should only contain games, not campaigns
        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $recommendations = $component->viewData('recommendations');
        expect($recommendations)->not->toBeEmpty('Expected recommendations for user with favorite boardgame system');

        $types = collect($recommendations)->pluck('discoverable_type')->toArray();
        expect($types)->not->toContain('campaign');
        expect($types)->toContain('game');
    });

    it('filters games by category_ids through gameSystem relationship', function () {
        $category = GameSystemCategory::create(['name' => 'Strategy']);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $system->categories()->attach($category->id);
        $otherSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        Game::factory()->create([
            'name' => ['en' => 'Categorized Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Uncategorized Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $otherSystem->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('category_ids', [$category->id])
            ->assertSee('Categorized Game')
            ->assertDontSee('Uncategorized Game');
    });

    it('filters games by mechanic_ids through gameSystem relationship', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Dice Rolling']);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $system->mechanics()->attach($mechanic->id);
        $otherSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        Game::factory()->create([
            'name' => ['en' => 'Mechanic Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'No Mechanic Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $otherSystem->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('mechanic_ids', [$mechanic->id])
            ->assertSee('Mechanic Game')
            ->assertDontSee('No Mechanic Game');
    });

    it('filters by combined category_ids and mechanic_ids', function () {
        $category = GameSystemCategory::create(['name' => 'Thematic']);
        $mechanic = GameSystemMechanic::create(['name' => 'Cooperative Play']);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $system->categories()->attach($category->id);
        $system->mechanics()->attach($mechanic->id);

        $otherSystem = GameSystem::factory()->create(['type' => 'boardgame']);
        $otherSystem->categories()->attach($category->id);

        Game::factory()->create([
            'name' => ['en' => 'Both Filters Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Category Only Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $otherSystem->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('category_ids', [$category->id])
            ->set('mechanic_ids', [$mechanic->id])
            ->assertSee('Both Filters Game')
            ->assertDontSee('Category Only Game');
    });

});
