<?php

use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

describe('DiscoveryPage', function () {
    it('renders the discovery page for guests', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertOk()
            ->assertSee('Discover');
    });

    it('shows both games and campaigns in all mode', function () {
        $game = Game::factory()->create([
            'name' => 'Public Game Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $campaign = Campaign::factory()->create([
            'name' => 'Public Campaign',
            'visibility' => 'public',
            'status' => 'active',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSee('Public Game Session')
            ->assertSee('Public Campaign');
    });

    it('filters to games only when mode is games', function () {
        Game::factory()->create([
            'name' => 'Visible Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => 'Hidden Campaign',
            'visibility' => 'public',
            'status' => 'active',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('mode', 'games')
            ->assertSee('Visible Game')
            ->assertDontSee('Hidden Campaign');
    });

    it('filters to campaigns only when mode is campaigns', function () {
        Game::factory()->create([
            'name' => 'Hidden Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => 'Visible Campaign',
            'visibility' => 'public',
            'status' => 'active',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('mode', 'campaigns')
            ->assertSee('Visible Campaign')
            ->assertDontSee('Hidden Game');
    });

    it('filters by search query', function () {
        Game::factory()->create([
            'name' => 'Dragonslayer Quest',
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

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('search', 'Dragonslayer')
            ->assertSee('Dragonslayer Quest')
            ->assertDontSee('Unrelated Session');
    });

    it('filters by game system', function () {
        $system1 = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $system2 = GameSystem::factory()->create(['name' => 'Pathfinder']);

        Game::factory()->create([
            'name' => 'D&D Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system1->id,
        ]);

        Game::factory()->create([
            'name' => 'Pathfinder Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $system2->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('game_system_id', $system1->id)
            ->assertSee('D&D Game')
            ->assertDontSee('Pathfinder Game');
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

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('vibe_flags', ['lighthearted'])
            ->assertSee('Chill Game')
            ->assertDontSee('Intense Game');
    });

    it('hides private games and campaigns', function () {
        Game::factory()->create([
            'name' => 'Private Game',
            'visibility' => 'private',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => 'Private Campaign',
            'visibility' => 'private',
            'status' => 'active',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertDontSee('Private Game')
            ->assertDontSee('Private Campaign');
    });

    it('shows protected items to authenticated users', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        Game::factory()->create([
            'name' => 'Protected Game',
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => 'Protected Campaign',
            'visibility' => 'protected',
            'status' => 'active',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSee('Protected Game')
            ->assertSee('Protected Campaign');
    });

    it('shows recommendations for logged-in users with favorite systems', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create();

        // Attach favorite game system
        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => 'Recommended Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSee('Recommended for You')
            ->assertSee('Recommended Game');
    });

    it('does not show recommendations for guests', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertDontSee('Recommended for You');
    });

    it('clears all filters', function () {
        Game::factory()->create([
            'name' => 'Test Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('search', 'test')
            ->set('game_system_id', 999)
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('game_system_id', null)
            ->assertSet('vibe_flags', []);
    });

    it('is accessible via route', function () {
        get('/en/discover')->assertOk();
    });

    it('toggles vibe flags', function () {
        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->call('toggleVibeFlag', 'lighthearted')
            ->assertSet('vibe_flags', ['lighthearted'])
            ->call('toggleVibeFlag', 'lighthearted')
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

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
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

        Campaign::factory()->create([
            'name' => 'Paid Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'price_per_session' => 15.00,
        ]);

        Campaign::factory()->create([
            'name' => 'Free Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'price_per_session' => 0,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('price', 'paid')
            ->assertSee('Paid Campaign')
            ->assertDontSee('Free Game')
            ->assertDontSee('Free Campaign');
    });

    it('filters games by date upcoming', function () {
        Game::factory()->create([
            'name' => 'Future Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(10),
        ]);

        // Past game should not appear at all (filtered by base query date_time > now)
        // but we also test the date filter specifically
        Game::factory()->create([
            'name' => 'Tomorrow Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('date', 'upcoming')
            ->assertSee('Future Game')
            ->assertSee('Tomorrow Game');
    });

    it('filters games by date this week', function () {
        Game::factory()->create([
            'name' => 'This Week Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(2),
        ]);

        Game::factory()->create([
            'name' => 'Next Month Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addMonths(2),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('date', 'this_week')
            ->assertSee('This Week Game')
            ->assertDontSee('Next Month Game');
    });

    it('filters campaigns by recurrence', function () {
        Campaign::factory()->create([
            'name' => 'Weekly Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'recurrence' => 'weekly',
        ]);

        Campaign::factory()->create([
            'name' => 'Monthly Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'recurrence' => 'monthly',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('recurrence', 'weekly')
            ->assertSee('Weekly Campaign')
            ->assertDontSee('Monthly Campaign');
    });

    it('filters by experience level', function () {
        Game::factory()->create([
            'name' => 'Beginner Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'experience_level' => 'beginner',
        ]);

        Campaign::factory()->create([
            'name' => 'Advanced Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'experience_level' => 'advanced',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('experience_level', 'beginner')
            ->assertSee('Beginner Game')
            ->assertDontSee('Advanced Campaign');
    });

    it('filters by language', function () {
        Game::factory()->create([
            'name' => 'English Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'language' => 'en',
        ]);

        Campaign::factory()->create([
            'name' => 'German Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'de',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('language', 'de')
            ->assertSee('German Campaign')
            ->assertDontSee('English Game');
    });

    it('filters by complexity range', function () {
        Game::factory()->create([
            'name' => 'Simple Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'complexity' => 1.5,
        ]);

        Campaign::factory()->create([
            'name' => 'Complex Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'complexity' => 4.5,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('complexity_min', '4.0')
            ->set('complexity_max', '5.0')
            ->assertSee('Complex Campaign')
            ->assertDontSee('Simple Game');
    });

    it('shows empty state when no results match', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('search', 'zzz-nothing-matches-this')
            ->assertSee('No results found');
    });

    it('shows empty state with different text when no content exists at all', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSee('Check back soon');
    });

    it('paginates results', function () {
        // Create 13 games to exceed per-page limit of 12
        Game::factory()->count(13)->create([
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(fake()->numberBetween(1, 30)),
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $results = $component->viewData('results');

        // First page should have 12 items
        expect($results->count())->toBe(12);
        expect($results->total())->toBe(13);

        // Page 2 should have 1 item
        $component = Livewire\Livewire::withQueryParams(['page' => 2])
            ->test(App\Livewire\Discovery\DiscoveryPage::class);
        $results = $component->viewData('results');
        expect($results->count())->toBe(1);
    });

    it('resets page when changing mode via setMode', function () {
        Game::factory()->count(13)->create([
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(fake()->numberBetween(1, 30)),
        ]);

        // Start on page 2 in 'all' mode, then switch to 'games'
        // After setMode, the component resets page so we see 12 results
        $component = Livewire\Livewire::withQueryParams(['page' => 2])
            ->test(App\Livewire\Discovery\DiscoveryPage::class);

        // Confirm we're on page 2 with 1 result
        expect($component->viewData('results')->count())->toBe(1);

        // Switch mode — should reset to page 1
        $component->call('setMode', 'games');
        expect($component->viewData('results')->count())->toBe(12);
    });

    it('hides protected items from guests', function () {
        Game::factory()->create([
            'name' => 'Protected Game for Guest',
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => 'Protected Campaign for Guest',
            'visibility' => 'protected',
            'status' => 'active',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertDontSee('Protected Game for Guest')
            ->assertDontSee('Protected Campaign for Guest');
    });

    it('does not show games with non-scheduled status', function () {
        Game::factory()->create([
            'name' => 'Completed Game',
            'visibility' => 'public',
            'status' => 'completed',
            'date_time' => now()->addDays(3),
        ]);

        Game::factory()->create([
            'name' => 'Canceled Game',
            'visibility' => 'public',
            'status' => 'canceled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertDontSee('Completed Game')
            ->assertDontSee('Canceled Game');
    });

    it('does not show campaigns with non-active status', function () {
        Campaign::factory()->create([
            'name' => 'Cancelled Campaign',
            'visibility' => 'public',
            'status' => 'cancelled',
        ]);

        Campaign::factory()->create([
            'name' => 'Completed Campaign',
            'visibility' => 'public',
            'status' => 'completed',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertDontSee('Cancelled Campaign')
            ->assertDontSee('Completed Campaign');
    });

    it('recommendations only include items matching favorite systems', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $favoriteSystem = GameSystem::factory()->create(['name' => 'Favorite System']);
        $otherSystem = GameSystem::factory()->create(['name' => 'Other System']);

        $user->favoriteGameSystems()->attach($favoriteSystem->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => 'Matching Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $favoriteSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Non-Matching Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $otherSystem->id,
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $recommendations = $component->viewData('recommendations');

        // Recommendations should only contain the matching game
        $names = collect($recommendations)->pluck('name')->toArray();
        expect($names)->toContain('Matching Game');
        expect($names)->not->toContain('Non-Matching Game');
    });

    it('does not show recommendations for users without favorite systems', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        Game::factory()->create([
            'name' => 'Some Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $recommendations = $component->viewData('recommendations');

        expect($recommendations)->toBeNull();
    });

    it('filters vibe flags across both games and campaigns', function () {
        Game::factory()->create([
            'name' => 'Cooperative Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'vibe_flags' => ['cooperative', 'lighthearted'],
        ]);

        Campaign::factory()->create([
            'name' => 'Cooperative Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'vibe_flags' => ['cooperative', 'story-rich'],
        ]);

        Campaign::factory()->create([
            'name' => 'Competitive Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'vibe_flags' => ['competitive', 'tactical'],
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('vibe_flags', ['cooperative'])
            ->assertSee('Cooperative Game')
            ->assertSee('Cooperative Campaign')
            ->assertDontSee('Competitive Campaign');
    });

    it('searches across both games and campaigns by description', function () {
        Game::factory()->create([
            'name' => 'Regular Game',
            'description' => 'An epic adventure through forgotten realms',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => 'Regular Campaign',
            'description' => 'A completely different sci-fi setting',
            'visibility' => 'public',
            'status' => 'active',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('search', 'forgotten realms')
            ->assertSee('Regular Game')
            ->assertDontSee('Regular Campaign');
    });

    it('hasActiveFilters returns correct state', function () {
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);

        // No filters active
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        // Set a filter
        $component->set('search', 'test');
        expect($component->instance()->hasActiveFilters())->toBeTrue();

        // Clear all filters
        $component->call('clearFilters');
        expect($component->instance()->hasActiveFilters())->toBeFalse();
    });

    it('renders for authenticated users', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertOk()
            ->assertSee('Discover');
    });

    it('renders correct discoverable_type on games in all mode', function () {
        Game::factory()->create([
            'name' => 'Typed Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => 'Typed Campaign',
            'visibility' => 'public',
            'status' => 'active',
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $results = $component->viewData('results');

        $game = $results->first(fn ($item) => $item->name === 'Typed Game');
        $campaign = $results->first(fn ($item) => $item->name === 'Typed Campaign');

        expect($game->discoverable_type)->toBe('game');
        expect($campaign->discoverable_type)->toBe('campaign');
    });
});
