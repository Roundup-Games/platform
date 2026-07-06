<?php

use App\Livewire\Discovery\AdventuresDiscovery;
use App\Livewire\Discovery\BoardGamesDiscovery;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Honest multi-system match contract for discovery (R048: a Gathering appears
 * in each offered system's feed).
 *
 * Under the cached-anchor model a Gathering whose game_system_id pointed at
 * ttrpg A but which also offered boardgame B was invisible to board-game feeds.
 * S06 replaced that anchor with the game_game_system belongsToMany pivot and
 * rewrote every query site to whereHas('gameSystems', ...), so a Gathering now
 * surfaces in every system it offers. These tests assert the contract across
 * the four discovery surfaces: the shared game_system_id filter, the board-game
 * type scope, the ttrpg type scope, and logged-in recommendations.
 *
 * The component-level game_system_id property is the user-facing filter input
 * (which system's feed to render); it is unrelated to the dropped games column
 * of the same name and is consumed via whereHas('gameSystems', id).
 */
describe('GatheringMatch', function () {
    // ── Shared game_system_id filter (applySharedFilters) ───────────────

    it('shows a multi-system Gathering in each of its offered system feeds', function () {
        $systems = GameSystem::factory()->count(3)->create(['type' => 'boardgame']);
        [$a, $b, $c] = $systems->modelKeys();

        // Decoy Gathering offering a system NOT in {A,B,C} — must never match.
        $decoy = GameSystem::factory()->create(['type' => 'boardgame']);
        Game::factory()->gathering()->withGameSystems([$decoy->id])->create([
            'name' => ['en' => 'Decoy Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Game::factory()->gathering()->withGameSystems([$a, $b, $c])->create([
            'name' => ['en' => 'Three-System Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        // Offers all three systems. Under the pivot model every offered system
        // surfaces the Gathering via whereHas('gameSystems', id).
        foreach ([$a, $b, $c] as $systemId) {
            Livewire\Livewire::test(BoardGamesDiscovery::class)
                ->set('game_system_id', $systemId)
                ->assertSee('Three-System Gathering')
                ->assertDontSee('Decoy Gathering');
        }
    });

    // ── Type scope: BoardGamesDiscovery (applySystemTypeScope) ──────────

    it('shows a ttrpg-offering Gathering that also offers a boardgame on the board-game feed', function () {
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);
        $boardgameSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        Game::factory()->gathering()->withGameSystems([$ttrpgSystem->id, $boardgameSystem->id])->create([
            'name' => ['en' => 'Cross-Type Board Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        // Under the pivot model the type scope is a single
        // whereHas('gameSystems', type => 'boardgame'), so the offered boardgame
        // system surfaces this Gathering on the board-game feed.
        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertSee('Cross-Type Board Gathering');
    });

    // ── Type scope: AdventuresDiscovery (applySystemTypeScope) ──────────

    it('shows a boardgame-offering Gathering that also offers a ttrpg on the adventures feed', function () {
        $boardgameSystem = GameSystem::factory()->create(['type' => 'boardgame']);
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->gathering()->withGameSystems([$boardgameSystem->id, $ttrpgSystem->id])->create([
            'name' => ['en' => 'Cross-Type Adventure Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        // The offered ttrpg system surfaces this Gathering on the adventures
        // feed via whereHas('gameSystems', type => 'ttrpg').
        Livewire\Livewire::test(AdventuresDiscovery::class)
            ->assertSee('Cross-Type Adventure Gathering');
    });

    // ── Recommendations (getRecommendations) ───────────────────────────

    it('includes a Gathering in recommendations when a non-anchor offered system is the only favorite', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $anchorSystem = GameSystem::factory()->create(['type' => 'boardgame']);
        $offeredSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        // User favorites ONLY one of the offered systems (not the first one,
        // which is what the representative-accessor returns).
        $user->favoriteGameSystems()->attach($offeredSystem->id, ['preference_type' => 'favorite']);

        // Gathering offering both systems. Under the cached-anchor model this
        // was invisible when the favorite was not the anchored system; the
        // pivot model matches on the full offered set via whereHas.
        Game::factory()->gathering()->withGameSystems([$anchorSystem->id, $offeredSystem->id])->create([
            'name' => ['en' => 'Multi-System Recommendation Target'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(BoardGamesDiscovery::class);
        $recommendations = $component->viewData('recommendations');

        expect($recommendations)->not->toBeNull('Expected recommendations for a user favoriting a non-anchor offered system');

        $recNames = collect($recommendations)->pluck('name')->toArray();
        expect($recNames)->toContain('Multi-System Recommendation Target');
    });
});
