<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\User;
use function Pest\Laravel\{actingAs};

describe('AdventuresDiscovery', function () {
    // ── Core rendering ──────────────────────────────────

    it('shows campaigns and games scoped to TTRPG game systems only', function () {
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->create([
            'name' => ['en' => 'Public TTRPG Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $ttrpgSystem->id,
        ]);

        Campaign::factory()->create([
            'name' => ['en' => 'Public TTRPG Campaign'],
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
            'name' => ['en' => 'Board Game Session'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $boardgameSystem->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'TTRPG Session'],
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
            'name' => ['en' => 'Narrative Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $narrativeSystem->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Tactical Game'],
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
            'name' => ['en' => 'Narrative Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $narrativeSystem->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Horror Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(4),
            'game_system_id' => $horrorSystem->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Tactical Game'],
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
            'name' => ['en' => 'Active Campaign'],
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $system->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'One-shot Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
            'campaign_id' => null,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Campaign Session'],
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
            'name' => ['en' => 'Active Campaign'],
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $system->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'One-shot Game'],
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
            'name' => ['en' => 'Session Zero Included'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
            'safety_rules' => ['tools' => ['session-zero', 'x-card']],
        ]);

        Game::factory()->create([
            'name' => ['en' => 'No Session Zero'],
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

    it('filters by session zero (name-based matching)', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        // Game whose name starts with "Session Zero" — should match
        Game::factory()->create([
            'name' => ['en' => 'Session Zero: Character Creation'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
            'safety_rules' => ['tools' => ['x-card']],
        ]);

        // Game whose name starts with "Session 0" — should match
        Game::factory()->create([
            'name' => ['en' => 'Session 0: World Building'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(4),
            'game_system_id' => $system->id,
            'safety_rules' => ['tools' => []],
        ]);

        // Campaign whose first session is named "Session Zero" — should match
        $campaign = Campaign::factory()->create([
            'name' => ['en' => 'Midnight Campaign'],
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $system->id,
            'safety_rules' => ['tools' => []],
        ]);
        Game::factory()->create([
            'name' => ['en' => 'Session Zero for Midnight'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $system->id,
            'campaign_id' => $campaign->id,
        ]);

        // Regular game — should NOT match
        Game::factory()->create([
            'name' => ['en' => 'Regular D&D Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(6),
            'game_system_id' => $system->id,
            'safety_rules' => ['tools' => []],
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class)
            ->set('session_zero', true)
            ->assertSee('Session Zero: Character Creation')
            ->assertSee('Session 0: World Building')
            ->assertSee('Midnight Campaign')
            ->assertDontSee('Regular D&D Game');
    });

    // ── Sorting ─────────────────────────────────────────

    it('campaigns appear before one-shot games by default', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        Campaign::factory()->create([
            'name' => ['en' => 'Campaign Alpha'],
            'visibility' => 'public',
            'status' => 'active',
            'game_system_id' => $system->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'One-shot Beta'],
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
            'name' => ['en' => 'Recommended TTRPG'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $ttrpgSystem->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Boardgame System Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $boardgameSystem->id,
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class);
        $recommendations = $component->viewData('recommendations');

        expect($recommendations)->not->toBeEmpty('Expected recommendations for TTRPG+boardgame user');

        $recNames = collect($recommendations)->pluck('name')->toArray();
        expect($recNames)->toContain('Recommended TTRPG');
        expect($recNames)->not->toContain('Boardgame System Game');
    });

    it('excludes boardgame systems from recommendations', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $boardgameSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        $user->favoriteGameSystems()->attach($boardgameSystem->id, ['preference_type' => 'favorite']);

        Game::factory()->create([
            'name' => ['en' => 'Boardgame Only Game'],
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

    it('clearFilters resets all filters', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->create([
            'name' => ['en' => 'Test Adventure'],
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
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('play_styles', [])
            ->assertSet('session_type', '');
    });

    it('hasActiveFilters detects adventure-specific filters', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->create([
            'name' => ['en' => 'Test'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Discovery\AdventuresDiscovery::class);
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        $component->set('session_type', 'campaign');
        expect($component->instance()->hasActiveFilters())->toBeTrue();
    });

});
