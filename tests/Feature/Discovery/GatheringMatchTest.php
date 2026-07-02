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
 * Before this slice, discovery matched only on the cached anchor
 * (game_system_id), so a Gathering anchored to ttrpg A but offering boardgame B
 * was invisible to board-game feeds. These tests assert the fix across the four
 * surfaces touched by T02: the shared game_system_id filter, the board-game type
 * scope, the ttrpg type scope, and logged-in recommendations.
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

        // Anchored to A. The anchor passes today; B and C only pass after the
        // whereJsonContains('game_systems') OR is added to the shared filter.
        foreach ([$a, $b, $c] as $systemId) {
            Livewire\Livewire::test(BoardGamesDiscovery::class)
                ->set('game_system_id', $systemId)
                ->assertSee('Three-System Gathering')
                ->assertDontSee('Decoy Gathering');
        }
    });

    // ── Type scope: BoardGamesDiscovery (applySystemTypeScope) ──────────

    it('shows a ttrpg-anchored Gathering that also offers a boardgame on the board-game feed', function () {
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);
        $boardgameSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        Game::factory()->gathering()->withGameSystems([$ttrpgSystem->id, $boardgameSystem->id])->create([
            'name' => ['en' => 'Cross-Type Board Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        // Anchor is ttrpg, so the old anchor-only type scope hid this Gathering
        // from the board-game feed. After the fix, the offered boardgame system
        // surfaces it via orWhereJsonContains.
        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertSee('Cross-Type Board Gathering');
    });

    // ── Type scope: AdventuresDiscovery (applySystemTypeScope) ──────────

    it('shows a boardgame-anchored Gathering that also offers a ttrpg on the adventures feed', function () {
        $boardgameSystem = GameSystem::factory()->create(['type' => 'boardgame']);
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);

        Game::factory()->gathering()->withGameSystems([$boardgameSystem->id, $ttrpgSystem->id])->create([
            'name' => ['en' => 'Cross-Type Adventure Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        // Anchor is boardgame, so the old anchor-only type scope hid this
        // Gathering from adventures. After the fix, the offered ttrpg system
        // surfaces it via orWhereJsonContains on the games query.
        Livewire\Livewire::test(AdventuresDiscovery::class)
            ->assertSee('Cross-Type Adventure Gathering');
    });

    // ── Recommendations (getRecommendations) ───────────────────────────

    it('includes a Gathering in recommendations when a non-anchor offered system is the only favorite', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $anchorSystem = GameSystem::factory()->create(['type' => 'boardgame']);
        $offeredSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        // User favorites ONLY the non-anchor offered system.
        $user->favoriteGameSystems()->attach($offeredSystem->id, ['preference_type' => 'favorite']);

        // Gathering anchored to a non-favorited system but offering the favorite.
        // The old anchor-only whereIn('game_system_id', [offered]) hid it; the fix
        // adds orWhereJsonContains('game_systems', offered) so it surfaces.
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
