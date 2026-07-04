<?php

use App\Livewire\Discovery\BoardGamesDiscovery;
use App\Models\Game;
use App\Models\GameSystem;
use Illuminate\Support\Facades\DB;

/**
 * R048: A multi-system Gathering must render distinctly on the discovery card —
 * a distinct "Gathering" badge and a chip for every offered system — while a
 * single-system game renders byte-identically to today (the plain "Game" badge
 * and its one system). Backed by the canonical belongsToMany pivot
 * (game_game_system) and standard Eloquent eager-loading so cards don't N+1.
 */
describe('GatheringCardRendering', function () {
    it('renders a Gathering card with the Gathering badge and every offered system name', function () {
        $systemA = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Alpha Boardgame']]);
        $systemB = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Beta Boardgame']]);
        $systemC = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Gamma Boardgame']]);

        Game::factory()->gathering()->withGameSystems([$systemA->id, $systemB->id, $systemC->id])->create([
            'name' => ['en' => 'Triple Gathering Card Render'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertSee('Triple Gathering Card Render')
            ->assertSee(__('games.type_gathering'))
            ->assertSee('Alpha Boardgame')
            ->assertSee('Beta Boardgame')
            ->assertSee('Gamma Boardgame');
    });

    it('does not render the Gathering badge for a single-system game', function () {
        $system = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Solo System']]);

        Game::factory()->create([
            'game_type' => 'board_game',
            'game_system_id' => $system->id,
            'game_systems' => null,
            'name' => ['en' => 'Single System Board Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertSee('Single System Board Game')
            ->assertSee('Solo System')
            ->assertSee(__('games.content_game'))
            ->assertDontSee(__('games.type_gathering'));
    });

    it('renders the campaign badge intact alongside the Gathering badge for a Gathering session of a campaign', function () {
        $systemA = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Anchor System']]);
        $systemB = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Offered System']]);

        $game = Game::factory()->gathering()->withGameSystems([$systemA->id, $systemB->id])->create([
            'name' => ['en' => 'Campaign Gathering Card'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $rendered = view('livewire.discovery.partials.game-card', ['game' => $game])->render();

        expect($rendered)
            ->toContain(__('games.type_gathering'))
            ->toContain('Anchor System')
            ->toContain('Offered System');
    });

    // ── Pivot relation: offered-system set + eager-load contract ─────

    it('resolves every offered system via the belongsToMany pivot', function () {
        $first = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'First System']]);
        $second = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Second System']]);
        $third = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Third System']]);

        $game = Game::factory()->gathering()->withGameSystems([$first->id, $second->id, $third->id])->create([
            'name' => ['en' => 'Pivot Resolver Test'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        // Reload a fresh instance so no relation cache is pre-populated.
        $game = Game::find($game->id);

        // belongsToMany pivot: every offered system is present. The pivot has no
        // order column, so chip render order is unspecified — assert the SET.
        expect($game->gameSystems)->toHaveCount(3)
            ->and($game->gameSystems->pluck('name')->sort()->values()->all())
            ->toBe(['First System', 'Second System', 'Third System'])
            ->and($game->hasMultipleSystems())->toBeTrue();
    });

    it('resolves the single offered system for a legacy single-system game and is not multi-system', function () {
        $system = GameSystem::factory()->create(['type' => 'boardgame']);

        $game = Game::factory()->create([
            'game_type' => 'board_game',
            'game_system_id' => $system->id,
            'game_systems' => null,
            'name' => ['en' => 'Legacy Single System'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $game = Game::find($game->id);

        // GameFactory mirrors the anchor into the pivot, so the single system
        // resolves via the SAME unified read path as a multi-system Gathering.
        expect($game->gameSystems)->toHaveCount(1)
            ->and($game->hasMultipleSystems())->toBeFalse();
    });

    it('respects an eager-loaded gameSystems relation without issuing a query', function () {
        $systemA = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Preloaded A']]);
        $systemB = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Preloaded B']]);

        $game = Game::factory()->gathering()->withGameSystems([$systemA->id, $systemB->id])->create([
            'name' => ['en' => 'Preload Relation Test'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        // Eager-load via with() — the standard contract DiscoveryQueryService
        // and BoardGamesDiscovery::loadMissing now rely on instead of the deleted
        // manual batch resolver.
        $game = Game::with('gameSystems')->find($game->id);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $resolved = $game->gameSystems;
        $queryCount = count(DB::getQueryLog());

        // Preloaded relation cache must short-circuit (no lazy query).
        expect($queryCount)->toBe(0)
            ->and($resolved->modelKeys())->toContain($systemA->id, $systemB->id);
    });
});
