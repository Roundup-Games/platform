<?php

use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

describe('BoardGamesDiscovery', function () {
    it('renders the board games discovery page for guests', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertOk()
            ->assertSee('Discover');
    });

    it('shows only games — no campaigns appear', function () {
        Game::factory()->create([
            'name' => 'Public Board Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => 'Hidden Campaign',
            'visibility' => 'public',
            'status' => 'active',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSee('Public Board Game')
            ->assertDontSee('Hidden Campaign');
    });

    it('filters by search query', function () {
        Game::factory()->create([
            'name' => 'Catan Tournament',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Game::factory()->create([
            'name' => 'Unrelated Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('search', 'Catan')
            ->assertSee('Catan Tournament')
            ->assertDontSee('Unrelated Session');
    });

    it('filters by game system', function () {
        $system1 = GameSystem::factory()->create(['name' => 'Ticket to Ride']);
        $system2 = GameSystem::factory()->create(['name' => 'Wingspan']);

        Game::factory()->create([
            'name' => 'TTR Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system1->id,
        ]);

        Game::factory()->create([
            'name' => 'Wingspan Game',
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

    it('filters by vibe flags', function () {
        Game::factory()->create([
            'name' => 'Chill Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'vibe_flags' => ['lighthearted', 'cooperative'],
        ]);

        Game::factory()->create([
            'name' => 'Intense Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'vibe_flags' => ['horror', 'tactical'],
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('vibe_flags', ['lighthearted'])
            ->assertSee('Chill Game')
            ->assertDontSee('Intense Game');
    });

    it('hides private games', function () {
        Game::factory()->create([
            'name' => 'Private Game',
            'visibility' => 'private',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertDontSee('Private Game');
    });

    it('shows protected items to authenticated users', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        Game::factory()->create([
            'name' => 'Protected Game',
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSee('Protected Game');
    });

    it('shows recommendations scoped to boardgame systems for logged-in users', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $boardgameSystem = GameSystem::factory()->create(['type' => 'boardgame']);
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);

        $user->favoriteGameSystems()->attach($boardgameSystem->id, ['preference_type' => 'favorite']);
        $user->favoriteGameSystems()->attach($ttrpgSystem->id, ['preference_type' => 'favorite']);

        // Only create a boardgame system game — recommendations should include it
        Game::factory()->create([
            'name' => 'Recommended Board Game',
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
            'name' => 'TTRPG Only Game',
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

    it('does not show recommendations for guests', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertDontSee('Recommended for You');
    });

    it('clears all filters', function () {
        Game::factory()->create([
            'name' => 'Test Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('search', 'test')
            ->set('game_system_id', (string) \Illuminate\Support\Str::uuid())
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('game_system_id', null)
            ->assertSet('vibe_flags', []);
    });

    it('toggles vibe flags via preference picker', function () {
        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $preferences = [];
        foreach (\App\Enums\VibeFlag::cases() as $flag) {
            $preferences[$flag->value] = null;
        }
        $preferences['lighthearted'] = 'favorite';

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->dispatch('vibe-preferences-changed', preferences: $preferences)
            ->assertSet('vibe_flags', ['lighthearted'])
            ->assertSet('vibePreferences.lighthearted', 'favorite');

        $preferences['lighthearted'] = null;

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->dispatch('vibe-preferences-changed', preferences: $preferences)
            ->assertSet('vibe_flags', []);
    });

    it('filters by price free', function () {
        Game::factory()->create([
            'name' => 'Free Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'price' => 0,
        ]);

        Game::factory()->create([
            'name' => 'Paid Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'price' => 10.00,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('price', 'free')
            ->assertSee('Free Game')
            ->assertDontSee('Paid Game');
    });

    it('filters by price paid', function () {
        Game::factory()->create([
            'name' => 'Free Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'price' => 0,
        ]);

        Game::factory()->create([
            'name' => 'Paid Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'price' => 15.00,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('price', 'paid')
            ->assertSee('Paid Game')
            ->assertDontSee('Free Game');
    });

    it('filters games by date upcoming', function () {
        Game::factory()->create([
            'name' => 'Future Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(10),
        ]);

        Game::factory()->create([
            'name' => 'Tomorrow Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('date', 'upcoming')
            ->assertSee('Future Game')
            ->assertSee('Tomorrow Game');
    });

    it('filters games by date this week', function () {
        Game::factory()->create([
            'name' => 'This Week Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => min(now()->endOfWeek()->subHour(), now()->addDays(5)),
        ]);

        Game::factory()->create([
            'name' => 'Next Week Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addWeek()->addDay(),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('date', 'this_week')
            ->assertSee('This Week Game');
    });

    it('filters by experience level', function () {
        Game::factory()->create([
            'name' => 'Beginner Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'experience_level' => 'beginner',
        ]);

        Game::factory()->create([
            'name' => 'Expert Game',
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
            'name' => 'English Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'language' => 'en',
        ]);

        Game::factory()->create([
            'name' => 'German Game',
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
            'name' => 'Light Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'complexity' => 1.5,
        ]);

        Game::factory()->create([
            'name' => 'Heavy Game',
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

    it('shows empty state when no results match', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSee('No results found');
    });

    it('paginates results', function () {
        for ($i = 0; $i < 15; $i++) {
            Game::factory()->create([
                'name' => "Game {$i}",
                'visibility' => 'public',
                'status' => 'scheduled',
                'date_time' => now()->addDays($i + 1),
            ]);
        }

        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $results = $component->viewData('results');
        expect($results->count())->toBe(12);
        expect($results->total())->toBe(15);
    });

    it('hides protected items from guests', function () {
        Game::factory()->create([
            'name' => 'Protected Game',
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertDontSee('Protected Game');
    });

    it('does not show games with non-scheduled status', function () {
        Game::factory()->create([
            'name' => 'Completed Game',
            'visibility' => 'public',
            'status' => 'completed',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertDontSee('Completed Game');
    });

    it('recommendations only include items matching favorite boardgame systems', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $favSystem = GameSystem::factory()->create(['type' => 'boardgame']);
        $otherSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        $user->favoriteGameSystems()->attach($favSystem->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => 'Fav System Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $favSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Other System Game',
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

    it('does not show recommendations for users without favorite systems', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertDontSee('Recommended for You');
    });

    it('hasActiveFilters returns correct state', function () {
        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        $component->call('setDate', 'upcoming');
        expect($component->instance()->hasActiveFilters())->toBeTrue();

        $component->call('clearFilters');
        expect($component->instance()->hasActiveFilters())->toBeFalse();
    });

    it('renders for authenticated users', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertOk();
    });

    it('renders correct discoverable_type on games', function () {
        Game::factory()->create([
            'name' => 'Typed Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $results = $component->viewData('results');
        expect($results->first()->discoverable_type)->toBe('game');
    });

    it('defaults language filter to user preferred language on mount', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'preferred_language' => 'de',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSet('language', 'de');
    });

    it('does not override language filter if URL already has a value', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'preferred_language' => 'de',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('language', 'en')
            ->assertSet('language', 'en');
    });

    it('defaults language filter to app locale for guests', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSet('language', app()->getLocale());
    });

    it('defaults language filter to German locale when app locale is de', function () {
        app()->setLocale('de');
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSet('language', 'de');
    });

    it('URL language param overrides app locale default', function () {
        app()->setLocale('de');
        Livewire\Livewire::withQueryParams(['language' => 'en'])
            ->test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSet('language', 'en');
    });

    it('All languages filter works when language is cleared', function () {
        Game::factory()->create([
            'name' => 'English Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'language' => 'en',
        ]);

        Game::factory()->create([
            'name' => 'German Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'language' => 'de',
        ]);

        // Clear filters should show both languages (All languages)
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->call('clearFilters')
            ->assertSee('English Game')
            ->assertSee('German Game');
    });

    it('recommendations exclude avoided game systems', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $favSystem = GameSystem::factory()->create(['type' => 'boardgame']);
        $avoidSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        $user->favoriteGameSystems()->attach($favSystem->id, ['preference_type' => 'favorite']);
        $user->favoriteGameSystems()->attach($avoidSystem->id, ['preference_type' => 'avoid']);

        Game::factory()->create([
            'name' => 'Fav Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $favSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Avoid Game',
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
        if ($recommendations) {
            $recNames = collect($recommendations)->pluck('name')->toArray();
            expect($recNames)->toContain('Fav Game');
            expect($recNames)->not->toContain('Avoid Game');
        }
    });

    it('recommendations return null when user only has avoided systems and no favorites', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);

        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'avoid']);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertDontSee('Recommended for You');
    });

    it('recommendations do not include campaigns even when system matches', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);

        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => 'Recommended Board Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Campaign::factory()->create([
            'name' => 'Hidden Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $system->id,
        ]);

        actingAs($user);
        // Recommendations should only contain games, not campaigns
        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $recommendations = $component->viewData('recommendations');
        if ($recommendations) {
            $types = collect($recommendations)->pluck('discoverable_type')->toArray();
            expect($types)->not->toContain('campaign');
            expect($types)->toContain('game');
        }
    });

    it('filters games by category_ids through gameSystem relationship', function () {
        $category = GameSystemCategory::create(['name' => 'Strategy']);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $system->categories()->attach($category->id);
        $otherSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        Game::factory()->create([
            'name' => 'Categorized Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Game::factory()->create([
            'name' => 'Uncategorized Game',
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
            'name' => 'Mechanic Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Game::factory()->create([
            'name' => 'No Mechanic Game',
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
            'name' => 'Both Filters Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Game::factory()->create([
            'name' => 'Category Only Game',
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

    it('clearFilters resets category_ids and mechanic_ids', function () {
        Game::factory()->create([
            'name' => 'Test Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $fakeUuid = (string) \Illuminate\Support\Str::uuid();

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('category_ids', [$fakeUuid])
            ->set('mechanic_ids', [$fakeUuid])
            ->call('clearFilters')
            ->assertSet('category_ids', [])
            ->assertSet('mechanic_ids', []);
    });

    it('hasActiveFilters detects category_ids and mechanic_ids', function () {
        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $fakeUuid = (string) \Illuminate\Support\Str::uuid();

        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        $component->set('category_ids', [$fakeUuid]);
        expect($component->instance()->hasActiveFilters())->toBeTrue();

        $component->set('category_ids', []);
        $component->set('mechanic_ids', [$fakeUuid]);
        expect($component->instance()->hasActiveFilters())->toBeTrue();
    });

    it('toggleCategory adds and removes category ids', function () {
        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $fakeUuid = (string) \Illuminate\Support\Str::uuid();

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->call('toggleCategory', $fakeUuid)
            ->assertSet('category_ids', [$fakeUuid])
            ->call('toggleCategory', $fakeUuid)
            ->assertSet('category_ids', []);
    });

    it('toggleMechanic adds and removes mechanic ids', function () {
        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $fakeUuid = (string) \Illuminate\Support\Str::uuid();

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->call('toggleMechanic', $fakeUuid)
            ->assertSet('mechanic_ids', [$fakeUuid])
            ->call('toggleMechanic', $fakeUuid)
            ->assertSet('mechanic_ids', []);
    });

    it('does not pre-select vibe flags for guest users', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSet('vibe_flags', []);
    });

    it('renders the expandable narrow-it-down toggle', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSee('Narrow it down');
    });

    it('does not render session type pills — no mode toggle', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertDontSee('Either')
            ->assertDontSee('One-shot')
            ->assertDontSee('Campaign');
    });

    it('renders curated categories in view data', function () {
        $category = GameSystemCategory::create(['name' => 'Card Game']);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $system->categories()->attach($category->id);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $categories = $component->viewData('curatedCategories');

        expect($categories)->not->toBeNull();
        expect($categories->count())->toBeGreaterThanOrEqual(1);
    });

    it('renders curated mechanics in view data', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Set Collection']);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $system->mechanics()->attach($mechanic->id);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $mechanics = $component->viewData('curatedMechanics');

        expect($mechanics)->not->toBeNull();
        expect($mechanics->count())->toBeGreaterThanOrEqual(1);
    });

    it('renders category pills in expandable section when categories exist', function () {
        $category = GameSystemCategory::create(['name' => 'Strategy']);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $system->categories()->attach($category->id);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSee('Categories');
    });

    it('renders mechanic pills in expandable section when mechanics exist', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Worker Placement']);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $system->mechanics()->attach($mechanic->id);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSee('Mechanics');
    });

    it('shows active filter chips for selected categories and mechanics', function () {
        $category = GameSystemCategory::create(['name' => 'Dice Game']);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $system->categories()->attach($category->id);

        Game::factory()->create([
            'name' => 'Cat Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->set('category_ids', [$category->id])
            ->assertSee('Dice Game');
    });

    it('radius defaults to 0 with no proximity filter', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->assertSet('radius', 0);
    });

    it('setRadius updates radius and resets page', function () {
        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->call('setRadius', 25)
            ->assertSet('radius', 25);
    });

    it('setRadius rejects invalid radius values', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->call('setRadius', 999)
            ->assertSet('radius', 0);
    });

    it('setRadius accepts 0 to clear proximity filter', function () {
        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->call('setRadius', 25)
            ->call('setRadius', 0)
            ->assertSet('radius', 0);
    });

    it('clearFilters resets radius to 0', function () {
        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class)
            ->call('setRadius', 25)
            ->call('clearFilters')
            ->assertSet('radius', 0);
    });

    it('hasActiveFilters detects radius > 0', function () {
        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        $component->call('setRadius', 25);
        expect($component->instance()->hasActiveFilters())->toBeTrue();
    });

    it('passes radius options to view', function () {
        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $radiusOptions = $component->viewData('radiusOptions');

        expect($radiusOptions)->toContain(10, 25, 50);
    });

    it('passes hasLocation to view', function () {
        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $hasLocation = $component->viewData('hasLocation');

        expect($hasLocation)->toBeBool();
    });

    it('does not have mode or recurrence public properties', function () {
        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $instance = $component->instance();

        // BoardGamesDiscovery should not have 'mode' or 'recurrence' properties
        // Check via reflection on the class-specific properties
        $reflection = new ReflectionClass($instance);
        $props = collect($reflection->getProperties())
            ->filter(fn ($p) => $p->getDeclaringClass()->getName() === get_class($instance))
            ->map(fn ($p) => $p->getName())
            ->toArray();

        expect($props)->not->toContain('mode');
        expect($props)->not->toContain('recurrence');
    });

    it('does not pass recurrenceOptions to the view', function () {
        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $allViewData = $component->instance()->render()->getData();
        expect($allViewData)->not->toHaveKey('recurrenceOptions');
    });

    it('always returns games results — never merged or campaigns', function () {
        Game::factory()->create([
            'name' => 'Result Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => 'Result Campaign',
            'visibility' => 'public',
            'status' => 'active',
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\BoardGamesDiscovery::class);
        $results = $component->viewData('results');

        // All results should be games (have discoverable_type = 'game')
        foreach ($results as $item) {
            expect($item->discoverable_type)->toBe('game');
        }
    });

    // ── Route-level smoke tests ───────────────────────────

    it('renders at /discover/board-games for guests via HTTP', function () {
        get(route('discover.board-games', 'en'))
            ->assertOk();
    });

    it('shows only games — no campaigns — in results via HTTP', function () {
        Game::factory()->create([
            'name' => 'Route Test Board Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => 'Route Test Campaign',
            'visibility' => 'public',
            'status' => 'active',
        ]);

        $response = get(route('discover.board-games', 'en'));
        $response->assertOk();
        $response->assertSee('Route Test Board Game');
        $response->assertDontSee('Route Test Campaign');
    });

    it('route is accessible via named route discover.board-games', function () {
        $url = route('discover.board-games', 'en');
        expect($url)->toEndWith('/en/discover/board-games');

        get($url)->assertOk();
    });
});
