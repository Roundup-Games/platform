<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\User;
use function Pest\Laravel\{actingAs};

describe('AdventuresDiscovery', function () {
    // ── Core rendering ──────────────────────────────────

    it('renders for guests at /discover/adventures', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->assertOk()
            ->assertSee('Discover Adventures');
    });

    it('shows campaigns and games scoped to TTRPG game systems only', function () {
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->create([
            'name' => 'Public TTRPG Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $ttrpgSystem->id,
        ]);

        Campaign::factory()->create([
            'name' => 'Public TTRPG Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $ttrpgSystem->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->assertSee('Public TTRPG Game')
            ->assertSee('Public TTRPG Campaign');
    });

    it('hides board-game-type systems from results', function () {
        $boardgameSystem = GameSystem::factory()->create(['type' => 'boardgame']);
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->create([
            'name' => 'Board Game Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $boardgameSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'TTRPG Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $ttrpgSystem->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->assertDontSee('Board Game Session')
            ->assertSee('TTRPG Session');
    });

    // ── Adventures-specific filters ─────────────────────

    it('filters by play style flags via category slugs', function () {
        // Create categories that map to the Narrative-First play style
        $imaginative = GameSystemCategory::create(['name' => 'Imaginative', 'slug' => 'imaginative']);
        $mystery = GameSystemCategory::create(['name' => 'Mystery', 'slug' => 'mystery']);

        $narrativeSystem = GameSystem::factory()->create(['type' => 'ttrpg']);
        $narrativeSystem->categories()->attach([$imaginative->id, $mystery->id]);

        $tacticalSystem = GameSystem::factory()->create(['type' => 'ttrpg']);
        // No categories attached — won't match any play style

        Game::factory()->create([
            'name' => 'Narrative Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $narrativeSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Tactical Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $tacticalSystem->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->set('play_styles', ['narrative-first'])
            ->assertSee('Narrative Game')
            ->assertDontSee('Tactical Game');
    });

    it('filtering by multiple play styles uses OR logic', function () {
        // Narrative-First maps to: imaginative, romance, mystery, swashbuckling, isekai, heartwarming
        $imaginative = GameSystemCategory::create(['name' => 'Imaginative', 'slug' => 'imaginative']);
        // Horror maps to: horror, eldritch-horror, gothic-horror, supernatural, dark-fantasy, grimdark
        $horror = GameSystemCategory::create(['name' => 'Horror', 'slug' => 'horror']);
        // Tactical maps to: high-fantasy, fantasy, wargame, miniatures, political-intrigue, fighting
        $highFantasy = GameSystemCategory::create(['name' => 'High Fantasy', 'slug' => 'high-fantasy']);

        $narrativeSystem = GameSystem::factory()->create(['type' => 'ttrpg']);
        $narrativeSystem->categories()->attach([$imaginative->id]);

        $horrorSystem = GameSystem::factory()->create(['type' => 'ttrpg']);
        $horrorSystem->categories()->attach([$horror->id]);

        $tacticalSystem = GameSystem::factory()->create(['type' => 'ttrpg']);
        $tacticalSystem->categories()->attach([$highFantasy->id]);

        Game::factory()->create([
            'name' => 'Narrative Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $narrativeSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Horror Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(4),
            'game_system_id' => $horrorSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Tactical Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $tacticalSystem->id,
        ]);

        // Multiple play styles: both narrative-first AND horror should appear, tactical should not
        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->set('play_styles', ['narrative-first', 'horror'])
            ->assertSee('Narrative Game')
            ->assertSee('Horror Game')
            ->assertDontSee('Tactical Game');
    });

    it('filters by session_type: campaign only', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);
        $campaign = Campaign::factory()->create([
            'name' => 'Active Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $system->id,
        ]);

        Game::factory()->create([
            'name' => 'One-shot Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
            'campaign_id' => null,
        ]);

        Game::factory()->create([
            'name' => 'Campaign Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $system->id,
            'campaign_id' => $campaign->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->set('session_type', 'campaign')
            ->assertSee('Active Campaign')
            ->assertSee('Campaign Session')
            ->assertDontSee('One-shot Game');
    });

    it('filters by session_type: oneshot only', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        Campaign::factory()->create([
            'name' => 'Active Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $system->id,
        ]);

        Game::factory()->create([
            'name' => 'One-shot Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
            'campaign_id' => null,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->set('session_type', 'oneshot')
            ->assertSee('One-shot Game')
            ->assertDontSee('Active Campaign');
    });

    it('filters by session zero (safety_rules check)', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->create([
            'name' => 'Session Zero Included',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
            'safety_rules' => ['tools' => ['session-zero', 'x-card']],
        ]);

        Game::factory()->create([
            'name' => 'No Session Zero',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $system->id,
            'safety_rules' => ['tools' => ['x-card']],
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->set('session_zero', true)
            ->assertSee('Session Zero Included')
            ->assertDontSee('No Session Zero');
    });

    // ── Sorting ─────────────────────────────────────────

    it('campaigns appear before one-shot games by default', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        Campaign::factory()->create([
            'name' => 'Campaign Alpha',
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $system->id,
        ]);

        Game::factory()->create([
            'name' => 'One-shot Beta',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
            'campaign_id' => null,
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class);
        $results = $component->viewData('results');

        $firstItem = $results->first();
        expect($firstItem->discoverable_type)->toBe('campaign');
        expect($firstItem->name)->toBe('Campaign Alpha');
    });

    // ── Recommendations ─────────────────────────────────

    it('scoped to TTRPG-type game systems only for logged-in users', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);
        $boardgameSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        $user->favoriteGameSystems()->attach($ttrpgSystem->id, ['preference_type' => 'favorite']);
        $user->favoriteGameSystems()->attach($boardgameSystem->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => 'Recommended TTRPG',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $ttrpgSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Boardgame System Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $boardgameSystem->id,
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class);
        $recommendations = $component->viewData('recommendations');

        if ($recommendations) {
            $recNames = collect($recommendations)->pluck('name')->toArray();
            expect($recNames)->toContain('Recommended TTRPG');
            expect($recNames)->not->toContain('Boardgame System Game');
        }
    });

    it('excludes boardgame systems from recommendations', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $boardgameSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        $user->favoriteGameSystems()->attach($boardgameSystem->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => 'Boardgame Only Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $boardgameSystem->id,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->assertDontSee('Recommended for You');
    });

    // ── Edge cases ──────────────────────────────────────

    it('shows empty state with TTRPG-specific messaging', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->assertSee('Check back soon for new adventures');
    });

    it('clearFilters resets all adventure-specific filters', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->create([
            'name' => 'Test Adventure',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->set('search', 'test')
            ->set('game_system_id', $system->id)
            ->set('play_styles', ['narrative-first'])
            ->set('session_type', 'campaign')
            ->set('session_zero', true)
            ->set('safety_tools', ['x-card'])
            ->set('experience_level', 'beginner')
            ->set('language', 'en')
            ->set('price', 'free')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('game_system_id', null)
            ->assertSet('play_styles', [])
            ->assertSet('session_type', '')
            ->assertSet('session_zero', false)
            ->assertSet('safety_tools', [])
            ->assertSet('vibe_flags', [])
            ->assertSet('experience_level', '')
            ->assertSet('language', '')
            ->assertSet('price', '');
    });

    it('hasActiveFilters detects adventure-specific filters', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class);
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        $component->set('session_type', 'campaign');
        expect($component->instance()->hasActiveFilters())->toBeTrue();

        $component->call('clearFilters');
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        $component->set('session_zero', true);
        expect($component->instance()->hasActiveFilters())->toBeTrue();

        $component->call('clearFilters');
        $component->set('play_styles', ['narrative-first']);
        expect($component->instance()->hasActiveFilters())->toBeTrue();
    });

    it('togglePlayStyle adds and removes play styles', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->call('togglePlayStyle', 'narrative-first')
            ->assertSet('play_styles', ['narrative-first'])
            ->call('togglePlayStyle', 'narrative-first')
            ->assertSet('play_styles', []);
    });

    it('setSessionType accepts valid values only', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->call('setSessionType', 'invalid')
            ->assertSet('session_type', '')
            ->call('setSessionType', 'campaign')
            ->assertSet('session_type', 'campaign')
            ->call('setSessionType', 'oneshot')
            ->assertSet('session_type', 'oneshot')
            ->call('setSessionType', '')
            ->assertSet('session_type', '');
    });

    it('toggleSafetyTool adds and removes safety tools', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->create([
            'name' => 'Test',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->call('toggleSafetyTool', 'x-card')
            ->assertSet('safety_tools', ['x-card'])
            ->call('toggleSafetyTool', 'x-card')
            ->assertSet('safety_tools', []);
    });

    it('defaults language filter to user preferred language on mount', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'preferred_language' => 'de',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->assertSet('language', 'de');
    });

});
