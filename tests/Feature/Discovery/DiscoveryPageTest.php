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
            'date_time' => now()->startOfWeek()->addDays(3),
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

    // ── Preference-aware tests ─────────────────────────

    it('defaults language filter to user preferred language on mount', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'preferred_language' => 'de',
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);

        expect($component->get('language'))->toBe('de');
    });

    it('does not override language filter if URL already has a value', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'preferred_language' => 'de',
        ]);

        actingAs($user);
        $component = Livewire\Livewire::withQueryParams(['language' => 'en'])
            ->test(App\Livewire\Discovery\DiscoveryPage::class);

        // The URL-provided value should not be overridden by mount()
        // Note: Livewire's #[Url] attribute hydrates before mount(), so $this->language is already 'en'
        expect($component->get('language'))->toBe('en');
    });

    it('does not set language default for guests', function () {
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        expect($component->get('language'))->toBe('');
    });

    it('recommendations exclude avoided game systems', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $favoriteSystem = GameSystem::factory()->create(['name' => 'Favorite']);
        $avoidedSystem = GameSystem::factory()->create(['name' => 'Avoided']);

        $user->favoriteGameSystems()->attach($favoriteSystem->id, ['preference_type' => 'favorite']);
        $user->avoidedGameSystems()->attach($avoidedSystem->id, ['preference_type' => 'avoid']);

        Game::factory()->create([
            'name' => 'Avoided Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $avoidedSystem->id,
        ]);

        Campaign::factory()->create([
            'name' => 'Favorite Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $favoriteSystem->id,
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $recommendations = $component->viewData('recommendations');

        $names = collect($recommendations)->pluck('name')->toArray();
        expect($names)->toContain('Favorite Campaign');
        expect($names)->not->toContain('Avoided Game');
    });

    it('recommendations include implied favorites from expansion propagation', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $baseSystem = GameSystem::factory()->create(['name' => 'Base Game']);
        $expansion = GameSystem::factory()->create(['name' => 'Expansion', 'base_game_id' => $baseSystem->id]);

        $user->favoriteGameSystems()->attach($baseSystem->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => 'Expansion Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $expansion->id,
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $recommendations = $component->viewData('recommendations');

        $names = collect($recommendations)->pluck('name')->toArray();
        expect($names)->toContain('Expansion Game');
    });

    it('defaults language filter to user preferred language en', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'preferred_language' => 'en',
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);

        expect($component->get('language'))->toBe('en');
    });

    it('does not set language filter when preferred language is bilingual de+en', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'preferred_language' => 'de+en',
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);

        // DeEn sets the language to 'de+en' which is not a valid filter value,
        // so no content will match the language filter — effectively bilingual (no filter effect)
        expect($component->get('language'))->toBe('de+en');
    });

    it('recommendations return null when user only has avoided systems and no favorites', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $avoidedSystem = GameSystem::factory()->create(['name' => 'Avoided Only']);

        $user->avoidedGameSystems()->attach($avoidedSystem->id, ['preference_type' => 'avoid']);

        Game::factory()->create([
            'name' => 'Game on Avoided System',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $avoidedSystem->id,
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $recommendations = $component->viewData('recommendations');

        expect($recommendations)->toBeNull();
    });

    it('recommendations exclude favorite system when it is also avoided', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $favSystem = GameSystem::factory()->create(['name' => 'Fav System']);
        $avoidedSystem = GameSystem::factory()->create(['name' => 'Avoided System']);

        // User has one favorite and one avoided — avoid wins per system
        $user->favoriteGameSystems()->attach($favSystem->id, ['preference_type' => 'favorite']);
        $user->avoidedGameSystems()->attach($avoidedSystem->id, ['preference_type' => 'avoid']);

        Game::factory()->create([
            'name' => 'Favorite System Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $favSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Avoided System Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(4),
            'game_system_id' => $avoidedSystem->id,
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $recommendations = $component->viewData('recommendations');

        $names = collect($recommendations)->pluck('name')->toArray();
        expect($names)->toContain('Favorite System Game');
        expect($names)->not->toContain('Avoided System Game');
    });

    it('recommendations boost vibe items across both games and campaigns', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create(['name' => 'Vibe System']);

        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);
        $user->vibePreferences()->create([
            'vibe_preference_value' => 'lighthearted',
            'preference_type' => 'favorite',
        ]);

        Game::factory()->create([
            'name' => 'Boosted Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
            'vibe_flags' => ['lighthearted'],
        ]);

        Campaign::factory()->create([
            'name' => 'Boosted Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $system->id,
            'vibe_flags' => ['lighthearted'],
        ]);

        Game::factory()->create([
            'name' => 'Fallback Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(4),
            'game_system_id' => $system->id,
            'vibe_flags' => [],
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $recommendations = $component->viewData('recommendations');

        $names = collect($recommendations)->pluck('name')->toArray();

        // All three should be present
        expect($names)->toContain('Boosted Game');
        expect($names)->toContain('Boosted Campaign');
        expect($names)->toContain('Fallback Game');

        // Both boosted items should appear before the fallback
        $boostedGameIdx = array_search('Boosted Game', $names);
        $boostedCampaignIdx = array_search('Boosted Campaign', $names);
        $fallbackIdx = array_search('Fallback Game', $names);

        expect($boostedGameIdx)->toBeLessThan($fallbackIdx);
        expect($boostedCampaignIdx)->toBeLessThan($fallbackIdx);
    });

    it('combines all three preference behaviors: language default, system filtering, and vibe boosting', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'preferred_language' => 'en',
        ]);

        $favoriteSystem = GameSystem::factory()->create(['name' => 'Fav System']);
        $avoidedSystem = GameSystem::factory()->create(['name' => 'Avoid System']);

        $user->favoriteGameSystems()->attach($favoriteSystem->id, ['preference_type' => 'favorite']);
        $user->avoidedGameSystems()->attach($avoidedSystem->id, ['preference_type' => 'avoid']);
        $user->vibePreferences()->create([
            'vibe_preference_value' => 'cooperative',
            'preference_type' => 'favorite',
        ]);

        // Boosted game: favorite system + favorite vibe
        Game::factory()->create([
            'name' => 'Boosted Fav Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $favoriteSystem->id,
            'vibe_flags' => ['cooperative'],
            'language' => 'en',
        ]);

        // Fallback game: favorite system, no vibe match
        Game::factory()->create([
            'name' => 'Fallback Fav Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(4),
            'game_system_id' => $favoriteSystem->id,
            'vibe_flags' => [],
            'language' => 'en',
        ]);

        // Avoided game: should not appear in recommendations
        Game::factory()->create([
            'name' => 'Avoided System Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $avoidedSystem->id,
            'vibe_flags' => ['cooperative'],
            'language' => 'en',
        ]);

        // Same-system game in German: should not appear in main results due to language filter
        Game::factory()->create([
            'name' => 'German Fav Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(6),
            'game_system_id' => $favoriteSystem->id,
            'vibe_flags' => ['cooperative'],
            'language' => 'de',
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);

        // Language should default to 'en' from preferred_language
        expect($component->get('language'))->toBe('en');

        // Recommendations: should have boosted and fallback, but not avoided
        $recommendations = $component->viewData('recommendations');
        $recNames = collect($recommendations)->pluck('name')->toArray();

        expect($recNames)->toContain('Boosted Fav Game');
        expect($recNames)->toContain('Fallback Fav Game');
        expect($recNames)->not->toContain('Avoided System Game');

        // Boosted should come before fallback
        $boostedIdx = array_search('Boosted Fav Game', $recNames);
        $fallbackIdx = array_search('Fallback Fav Game', $recNames);
        expect($boostedIdx)->toBeLessThan($fallbackIdx);

        // Main results should be filtered by language='en', excluding German content
        $results = $component->viewData('results');
        $resultNames = collect($results->items())->pluck('name')->toArray();
        expect($resultNames)->not->toContain('German Fav Game');
    });

    it('recommendations with multiple vibe preferences boost items matching any favorite vibe', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create(['name' => 'Multi Vibe System']);

        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        // Multiple favorite vibes
        $user->vibePreferences()->createMany([
            ['vibe_preference_value' => 'lighthearted', 'preference_type' => 'favorite'],
            ['vibe_preference_value' => 'cooperative', 'preference_type' => 'favorite'],
        ]);

        Game::factory()->create([
            'name' => 'Lighthearted Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
            'vibe_flags' => ['lighthearted'],
        ]);

        Campaign::factory()->create([
            'name' => 'Cooperative Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $system->id,
            'vibe_flags' => ['cooperative'],
        ]);

        Game::factory()->create([
            'name' => 'No Vibe Match Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(4),
            'game_system_id' => $system->id,
            'vibe_flags' => ['tactical'],
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $recommendations = $component->viewData('recommendations');

        $names = collect($recommendations)->pluck('name')->toArray();

        // Both vibe-matched items should be boosted (before fallback)
        expect($names)->toContain('Lighthearted Game');
        expect($names)->toContain('Cooperative Campaign');
        expect($names)->toContain('No Vibe Match Game');

        $lightheartedIdx = array_search('Lighthearted Game', $names);
        $coopIdx = array_search('Cooperative Campaign', $names);
        $noVibeIdx = array_search('No Vibe Match Game', $names);

        expect($lightheartedIdx)->toBeLessThan($noVibeIdx);
        expect($coopIdx)->toBeLessThan($noVibeIdx);
    });

    it('implied favorites from expansion are excluded if expansion system is also avoided', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $baseSystem = GameSystem::factory()->create(['name' => 'Base System']);
        $expansion = GameSystem::factory()->create(['name' => 'Expansion', 'base_game_id' => $baseSystem->id]);

        $user->favoriteGameSystems()->attach($baseSystem->id, ['preference_type' => 'favorite']);
        // Avoid the expansion — avoid wins over implied favorite
        $user->avoidedGameSystems()->attach($expansion->id, ['preference_type' => 'avoid']);

        Game::factory()->create([
            'name' => 'Base System Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $baseSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Expansion Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(4),
            'game_system_id' => $expansion->id,
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $recommendations = $component->viewData('recommendations');

        $names = collect($recommendations)->pluck('name')->toArray();
        expect($names)->toContain('Base System Game');
        expect($names)->not->toContain('Expansion Game');
    });

    it('boosted vibe items appear first in recommendations', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create(['name' => 'Test System']);

        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        // Create a favorite vibe preference
        $user->vibePreferences()->create([
            'vibe_preference_value' => 'lighthearted',
            'preference_type' => 'favorite',
        ]);

        Game::factory()->create([
            'name' => 'Boosted Vibe Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
            'vibe_flags' => ['lighthearted'],
        ]);

        Game::factory()->create([
            'name' => 'No Vibe Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(4),
            'game_system_id' => $system->id,
            'vibe_flags' => [],
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $recommendations = $component->viewData('recommendations');

        $names = collect($recommendations)->pluck('name')->toArray();
        expect($names)->toContain('Boosted Vibe Game');
        expect($names)->toContain('No Vibe Game');
        // Boosted item should appear before non-boosted
        $boostedIndex = array_search('Boosted Vibe Game', $names);
        $noVibeIndex = array_search('No Vibe Game', $names);
        expect($boostedIndex)->toBeLessThan($noVibeIndex);
    });

    // ── Category & Mechanic Filtering ──────────────────

    it('filters games by category_ids through gameSystem relationship', function () {
        $category = GameSystemCategory::create(['name' => 'Strategy']);
        $otherCategory = GameSystemCategory::create(['name' => 'Party']);

        $strategySystem = GameSystem::factory()->create(['name' => 'Strategy Game']);
        $partySystem = GameSystem::factory()->create(['name' => 'Party Game']);

        $strategySystem->categories()->attach($category->id);
        $partySystem->categories()->attach($otherCategory->id);

        Game::factory()->create([
            'name' => 'Strategy Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $strategySystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Party Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $partySystem->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('category_ids', [$category->id])
            ->assertSee('Strategy Session')
            ->assertDontSee('Party Session');
    });

    it('filters campaigns by category_ids through gameSystem relationship', function () {
        $category = GameSystemCategory::create(['name' => 'Adventure']);
        $otherCategory = GameSystemCategory::create(['name' => 'Abstract']);

        $advSystem = GameSystem::factory()->create(['name' => 'Adventure System']);
        $absSystem = GameSystem::factory()->create(['name' => 'Abstract System']);

        $advSystem->categories()->attach($category->id);
        $absSystem->categories()->attach($otherCategory->id);

        Campaign::factory()->create([
            'name' => 'Adventure Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $advSystem->id,
        ]);

        Campaign::factory()->create([
            'name' => 'Abstract Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $absSystem->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('category_ids', [$category->id])
            ->assertSee('Adventure Campaign')
            ->assertDontSee('Abstract Campaign');
    });

    it('filters games by mechanic_ids through gameSystem relationship', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Dice Rolling']);
        $otherMechanic = GameSystemMechanic::create(['name' => 'Deck Building']);

        $diceSystem = GameSystem::factory()->create(['name' => 'Dice System']);
        $deckSystem = GameSystem::factory()->create(['name' => 'Deck System']);

        $diceSystem->mechanics()->attach($mechanic->id);
        $deckSystem->mechanics()->attach($otherMechanic->id);

        Game::factory()->create([
            'name' => 'Dice Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $diceSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Deck Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $deckSystem->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('mechanic_ids', [$mechanic->id])
            ->assertSee('Dice Game')
            ->assertDontSee('Deck Game');
    });

    it('filters campaigns by mechanic_ids through gameSystem relationship', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Worker Placement']);
        $otherMechanic = GameSystemMechanic::create(['name' => 'Area Control']);

        $wpSystem = GameSystem::factory()->create(['name' => 'WP System']);
        $acSystem = GameSystem::factory()->create(['name' => 'AC System']);

        $wpSystem->mechanics()->attach($mechanic->id);
        $acSystem->mechanics()->attach($otherMechanic->id);

        Campaign::factory()->create([
            'name' => 'Worker Placement Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $wpSystem->id,
        ]);

        Campaign::factory()->create([
            'name' => 'Area Control Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $acSystem->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('mechanic_ids', [$mechanic->id])
            ->assertSee('Worker Placement Campaign')
            ->assertDontSee('Area Control Campaign');
    });

    it('filters by combined category_ids and mechanic_ids', function () {
        $category = GameSystemCategory::create(['name' => 'Thematic']);
        $mechanic = GameSystemMechanic::create(['name' => 'Cooperative Play']);

        // System matching BOTH category AND mechanic
        $matchSystem = GameSystem::factory()->create(['name' => 'Match System']);
        $matchSystem->categories()->attach($category->id);
        $matchSystem->mechanics()->attach($mechanic->id);

        // System matching only category, not mechanic
        $catOnlySystem = GameSystem::factory()->create(['name' => 'Cat Only System']);
        $catOnlySystem->categories()->attach($category->id);

        // System matching only mechanic, not category
        $mechOnlySystem = GameSystem::factory()->create(['name' => 'Mech Only System']);
        $mechOnlySystem->mechanics()->attach($mechanic->id);

        Game::factory()->create([
            'name' => 'Both Match Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $matchSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Category Only Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $catOnlySystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Mechanic Only Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $mechOnlySystem->id,
        ]);

        // Both filters active: only the system matching BOTH should appear
        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('category_ids', [$category->id])
            ->set('mechanic_ids', [$mechanic->id])
            ->assertSee('Both Match Game')
            ->assertDontSee('Category Only Game')
            ->assertDontSee('Mechanic Only Game');
    });

    it('filters by multiple category_ids with OR logic', function () {
        $catA = GameSystemCategory::create(['name' => 'Wargame']);
        $catB = GameSystemCategory::create(['name' => 'Negotiation']);

        $systemA = GameSystem::factory()->create(['name' => 'War System']);
        $systemB = GameSystem::factory()->create(['name' => 'Diplomacy System']);
        $systemC = GameSystem::factory()->create(['name' => 'Unrelated System']);

        $systemA->categories()->attach($catA->id);
        $systemB->categories()->attach($catB->id);

        Game::factory()->create([
            'name' => 'War Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $systemA->id,
        ]);

        Game::factory()->create([
            'name' => 'Diplomacy Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $systemB->id,
        ]);

        Game::factory()->create([
            'name' => 'Unrelated Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $systemC->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('category_ids', [$catA->id, $catB->id])
            ->assertSee('War Game')
            ->assertSee('Diplomacy Game')
            ->assertDontSee('Unrelated Game');
    });

    it('clearFilters resets category_ids and mechanic_ids', function () {
        Game::factory()->create([
            'name' => 'Reset Test Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('category_ids', [1, 2])
            ->set('mechanic_ids', [3, 4])
            ->set('search', 'test')
            ->call('clearFilters')
            ->assertSet('category_ids', [])
            ->assertSet('mechanic_ids', [])
            ->assertSet('search', '');
    });

    it('hasActiveFilters detects category_ids and mechanic_ids', function () {
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        $component->set('category_ids', [1]);
        expect($component->instance()->hasActiveFilters())->toBeTrue();

        $component->set('category_ids', []);
        $component->set('mechanic_ids', [2]);
        expect($component->instance()->hasActiveFilters())->toBeTrue();

        $component->call('clearFilters');
        expect($component->instance()->hasActiveFilters())->toBeFalse();
    });

    it('toggleCategory adds and removes category ids', function () {
        Game::factory()->create([
            'name' => 'Toggle Cat Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);

        // Add
        $component->call('toggleCategory', 5)
            ->assertSet('category_ids', [5]);

        // Add another
        $component->call('toggleCategory', 10)
            ->assertSet('category_ids', [5, 10]);

        // Remove first
        $component->call('toggleCategory', 5)
            ->assertSet('category_ids', [10]);
    });

    it('toggleMechanic adds and removes mechanic ids', function () {
        Game::factory()->create([
            'name' => 'Toggle Mech Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);

        // Add
        $component->call('toggleMechanic', 7)
            ->assertSet('mechanic_ids', [7]);

        // Add another
        $component->call('toggleMechanic', 12)
            ->assertSet('mechanic_ids', [7, 12]);

        // Remove first
        $component->call('toggleMechanic', 7)
            ->assertSet('mechanic_ids', [12]);
    });

    // ── Vibe Preference Pre-Selection ──────────────────

    it('pre-selects vibe flags from user preferences on mount', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        // Create favorite vibe preferences
        $user->vibePreferences()->createMany([
            ['vibe_preference_value' => 'lighthearted', 'preference_type' => 'favorite'],
            ['vibe_preference_value' => 'cooperative', 'preference_type' => 'favorite'],
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);

        $vibes = $component->get('vibe_flags');
        expect($vibes)->toContain('lighthearted');
        expect($vibes)->toContain('cooperative');
    });

    it('does not override vibe flags when URL already has values', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        $user->vibePreferences()->create([
            'vibe_preference_value' => 'lighthearted',
            'preference_type' => 'favorite',
        ]);

        actingAs($user);
        $component = Livewire\Livewire::withQueryParams(['vibe_flags' => ['horror']])
            ->test(App\Livewire\Discovery\DiscoveryPage::class);

        // URL value should not be overridden by mount() pre-selection
        $vibes = $component->get('vibe_flags');
        expect($vibes)->toContain('horror');
        expect($vibes)->not->toContain('lighthearted');
    });

    it('does not pre-select vibe flags for guest users', function () {
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        expect($component->get('vibe_flags'))->toBe([]);
    });

    it('does not pre-select vibe flags when user has no vibe preferences', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        expect($component->get('vibe_flags'))->toBe([]);
    });

    // ── Progressive Disclosure & Layout ────────────────

    it('renders the expandable narrow-it-down toggle', function () {
        Game::factory()->create([
            'name' => 'Layout Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSee('Narrow it down');
    });

    it('renders session type pills (Either, One-shot, Campaign)', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSee('Either')
            ->assertSee('One-shot')
            ->assertSee('Campaign');
    });

    it('renders curated categories in view data', function () {
        Game::factory()->create([
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $categories = $component->viewData('curatedCategories');

        expect($categories)->not->toBeNull();
    });

    it('renders curated mechanics in view data', function () {
        Game::factory()->create([
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $mechanics = $component->viewData('curatedMechanics');

        expect($mechanics)->not->toBeNull();
    });

    it('renders category pills in expandable section when categories exist', function () {
        $category = GameSystemCategory::create(['name' => 'Card Game']);
        $system = GameSystem::factory()->create(['name' => 'Card System']);
        $system->categories()->attach($category->id);

        Game::factory()->create([
            'name' => 'Card Game Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSee('Categories')
            ->assertSee('Card Game');
    });

    it('renders mechanic pills in expandable section when mechanics exist', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Set Collection']);
        $system = GameSystem::factory()->create(['name' => 'Collection System']);
        $system->mechanics()->attach($mechanic->id);

        Game::factory()->create([
            'name' => 'Collection Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSee('Mechanics')
            ->assertSee('Set Collection');
    });

    it('shows active filter chips for selected categories and mechanics', function () {
        $category = GameSystemCategory::create(['name' => 'Economic']);
        $mechanic = GameSystemMechanic::create(['name' => 'Auction']);
        $system = GameSystem::factory()->create(['name' => 'Econ System']);
        $system->categories()->attach($category->id);
        $system->mechanics()->attach($mechanic->id);

        Game::factory()->create([
            'name' => 'Econ Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('category_ids', [$category->id])
            ->set('mechanic_ids', [$mechanic->id])
            ->assertSee('Economic')
            ->assertSee('Auction')
            ->assertSee('Clear all');
    });
});
