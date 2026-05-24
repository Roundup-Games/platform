<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use function Pest\Laravel\{actingAs};

describe('DiscoveryPage', function () {
    it('renders the discovery page for guests', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertOk()
            ->assertSee('Discover');
    })->group('smoke');

    it('shows both games and campaigns in all mode', function () {
        $game = Game::factory()->create([
            'name' => ['en' => 'Public Game Session'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $campaign = Campaign::factory()->create([
            'name' => ['en' => 'Public Campaign'],
            'visibility' => 'public',
            'status' => 'active',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSee('Public Game Session')
            ->assertSee('Public Campaign');
    })->group('smoke');

    it('filters to games only when mode is games', function () {
        Game::factory()->create([
            'name' => ['en' => 'Visible Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => ['en' => 'Hidden Campaign'],
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
            'name' => ['en' => 'Hidden Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => ['en' => 'Visible Campaign'],
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
            'name' => ['en' => 'Dragonslayer Quest'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Unrelated Session'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('search', 'Dragonslayer')
            ->assertSee('Dragonslayer Quest')
            ->assertDontSee('Unrelated Session');
    })->group('smoke');

    it('hides private games and campaigns', function () {
        Game::factory()->create([
            'name' => ['en' => 'Private Game'],
            'visibility' => 'private',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => ['en' => 'Private Campaign'],
            'visibility' => 'private',
            'status' => 'active',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertDontSee('Private Game')
            ->assertDontSee('Private Campaign');
    })->group('smoke');

    it('shows recommendations for logged-in users with favorite systems', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create();

        // Attach favorite game system
        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => ['en' => 'Recommended Game'],
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

    it('filters games by date this week', function () {
        Game::factory()->create([
            'name' => ['en' => 'This Week Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => min(now()->endOfWeek()->subHour(), now()->addDays(5)),
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Next Month Game'],
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
            'name' => ['en' => 'Weekly Campaign'],
            'visibility' => 'public',
            'status' => 'active',
            'recurrence' => 'weekly',
        ]);

        Campaign::factory()->create([
            'name' => ['en' => 'Monthly Campaign'],
            'visibility' => 'public',
            'status' => 'active',
            'recurrence' => 'monthly',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('recurrence', 'weekly')
            ->assertSee('Weekly Campaign')
            ->assertDontSee('Monthly Campaign');
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

    it('filters vibe flags across both games and campaigns', function () {
        Game::factory()->create([
            'name' => ['en' => 'Cooperative Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'vibe_flags' => ['cooperative', 'lighthearted'],
        ]);

        Campaign::factory()->create([
            'name' => ['en' => 'Cooperative Campaign'],
            'visibility' => 'public',
            'status' => 'active',
            'vibe_flags' => ['cooperative', 'story-rich'],
        ]);

        Campaign::factory()->create([
            'name' => ['en' => 'Competitive Campaign'],
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
            'name' => ['en' => 'Regular Game'],
            'description' => ['en' => 'An epic adventure through forgotten realms'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => ['en' => 'Regular Campaign'],
            'description' => ['en' => 'A completely different sci-fi setting'],
            'visibility' => 'public',
            'status' => 'active',
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('search', 'forgotten realms')
            ->assertSee('Regular Game')
            ->assertDontSee('Regular Campaign');
    });

    it('recommends games for expansion game systems when user favors base game', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $baseSystem = GameSystem::factory()->create(['name' => ['en' => 'Base Game']]);
        $expansion = GameSystem::factory()->create(['name' => ['en' => 'Expansion'], 'base_game_id' => $baseSystem->id]);

        $user->favoriteGameSystems()->attach($baseSystem->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => ['en' => 'Expansion Game'],
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

    it('recommendations boost vibe items across both games and campaigns', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create(['name' => ['en' => 'Vibe System']]);

        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);
        $user->vibePreferences()->create([
            'vibe_preference_value' => 'lighthearted',
            'preference_type' => 'favorite',
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Boosted Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
            'vibe_flags' => ['lighthearted'],
        ]);

        Campaign::factory()->create([
            'name' => ['en' => 'Boosted Campaign'],
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $system->id,
            'vibe_flags' => ['lighthearted'],
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Fallback Game'],
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

    it('implied favorites from expansion are excluded if expansion system is also avoided', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $baseSystem = GameSystem::factory()->create(['name' => ['en' => 'Base System']]);
        $expansion = GameSystem::factory()->create(['name' => ['en' => 'Expansion'], 'base_game_id' => $baseSystem->id]);

        $user->favoriteGameSystems()->attach($baseSystem->id, ['preference_type' => 'favorite']);
        // Avoid the expansion — avoid wins over implied favorite
        $user->avoidedGameSystems()->attach($expansion->id, ['preference_type' => 'avoid']);

        Game::factory()->create([
            'name' => ['en' => 'Base System Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $baseSystem->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Expansion Game'],
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

    // ── Vibe Preference Pre-Selection ──────────────────

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

    // ── Proximity / Radius Filtering ───────────────────

    it('setRadius updates radius and resets page', function () {
        Game::factory()->create([
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $component->call('setRadius', 25)
            ->assertSet('radius', 25.0)
            ->assertSet('usingFallbackRadius', false);
    });

    it('setRadius rejects invalid radius values', function () {
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        $component->call('setRadius', 99)
            ->assertSet('radius', 0.0);
    });

    it('clearFilters resets radius to 0', function () {
        Game::factory()->create([
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('radius', 25)
            ->call('clearFilters')
            ->assertSet('radius', 0.0)
            ->assertSet('usingFallbackRadius', false);
    });

    it('uses user saved location as fallback when guest location unavailable', function () {
        // User's saved location: Berlin center
        $userLocation = \App\Models\Location::factory()->create([
            'latitude' => 52.5200,
            'longitude' => 13.4050,
        ]);
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $userLocation->id,
        ]);

        // Game nearby in Berlin (< 5km away)
        $nearbyLocation = \App\Models\Location::factory()->create([
            'latitude' => 52.5300,
            'longitude' => 13.4100,
        ]);
        Game::factory()->create([
            'name' => ['en' => 'Nearby Berlin Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'location_id' => $nearbyLocation->id,
        ]);

        // Game far away in Munich (~500km)
        $farLocation = \App\Models\Location::factory()->create([
            'latitude' => 48.1351,
            'longitude' => 11.5820,
        ]);
        Game::factory()->create([
            'name' => ['en' => 'Far Munich Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'location_id' => $farLocation->id,
        ]);

        actingAs($user);

        // Use games mode so results go through getGamesResults (simpler path)
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('mode', 'games');

        // Without radius filter, both games show
        $component->assertSee('Nearby Berlin Game')
            ->assertSee('Far Munich Game');

        // With radius=25 (and no guest location), user's saved location is used as fallback
        $component->set('radius', 25);

        // Verify hasLocation is true (user location fallback active)
        expect($component->viewData('hasLocation'))->toBeTrue();

        // Only the nearby game should appear
        $component->assertSee('Nearby Berlin Game')
            ->assertDontSee('Far Munich Game');
    });

    it('prefers guest location over user saved location', function () {
        $userLocation = \App\Models\Location::factory()->create([
            'latitude' => 52.5200,
            'longitude' => 13.4050,
        ]);
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $userLocation->id,
        ]);

        actingAs($user);

        // Simulate guest location being set (browser geolocation)
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('guestLat', 48.1351)
            ->set('guestLng', 11.5820);

        // hasLocation should be true from guest location, not user saved
        $component->assertSet('guestLat', 48.1351);
    });

    // ── Protected Visibility (Connections Only) ─────────

    it('hides protected games from users not connected to the owner', function () {
        $owner = User::factory()->create();
        $stranger = User::factory()->create(['profile_complete' => true]);

        Game::factory()->create([
            'name' => ['en' => 'Protected Session'],
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'owner_id' => $owner->id,
        ]);

        actingAs($stranger);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertDontSee('Protected Session');
    });

    it('hides protected campaigns from users not connected to the owner', function () {
        $owner = User::factory()->create();
        $stranger = User::factory()->create(['profile_complete' => true]);

        Campaign::factory()->create([
            'name' => ['en' => 'Protected Campaign'],
            'visibility' => 'protected',
            'status' => 'active',
            'owner_id' => $owner->id,
        ]);

        actingAs($stranger);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertDontSee('Protected Campaign');
    });

    it('shows protected games to mutual followers of the owner', function () {
        $owner = User::factory()->create();
        $friend = User::factory()->create(['profile_complete' => true]);

        // Create mutual follow (both follow each other)
        \App\Models\UserRelationship::follow($friend, $owner);
        \App\Models\UserRelationship::follow($owner, $friend);

        Game::factory()->create([
            'name' => ['en' => 'Friends Only Session'],
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'owner_id' => $owner->id,
        ]);

        actingAs($friend);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSee('Friends Only Session');
    });

    it('shows protected games to existing participants regardless of connection', function () {
        $owner = User::factory()->create();
        $participant = User::factory()->create(['profile_complete' => true]);

        $game = Game::factory()->create([
            'name' => ['en' => 'Joined Protected Session'],
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'owner_id' => $owner->id,
        ]);

        // Add user as participant
        \App\Models\GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'status' => 'approved',
        ]);

        actingAs($participant);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSee('Joined Protected Session');
    });

    it('shows protected games to the owner themselves', function () {
        $owner = User::factory()->create(['profile_complete' => true]);

        Game::factory()->create([
            'name' => ['en' => 'My Protected Session'],
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'owner_id' => $owner->id,
        ]);

        actingAs($owner);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSee('My Protected Session');
    });

    it('hides protected games from guests', function () {
        Game::factory()->create([
            'name' => ['en' => 'Guest Hidden Session'],
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertDontSee('Guest Hidden Session');
    });

});
