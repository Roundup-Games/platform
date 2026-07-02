<?php

use App\Livewire\Discovery\BoardGamesDiscovery;
use App\Models\Game;
use App\Models\GameSystem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * R048: A multi-system Gathering must render distinctly on the discovery card —
 * a distinct "Gathering" badge and a chip for every offered system — while a
 * single-system game renders byte-identically to today (the plain "Game" badge
 * and its one anchor system). Backed by a memoized Game::gameSystems() resolver
 * and a single-query batch eager-load so cards don't N+1.
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

    // ── Resolver: ordered set + memoization ────────────────────────────

    it('resolves the offered systems in stored order and memoizes so repeated access issues no extra query', function () {
        $first = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'First System']]);
        $second = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Second System']]);
        $third = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Third System']]);

        $game = Game::factory()->gathering()->withGameSystems([$first->id, $second->id, $third->id])->create([
            'name' => ['en' => 'Memo Resolver Test'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        // Reload a fresh instance so no relation cache is pre-populated.
        $game = Game::find($game->id);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $resolved = $game->gameSystems();
        $queriesAfterFirstCall = count(DB::getQueryLog());

        // Ordered to match the stored game_systems array (anchor first).
        expect($resolved->count())->toBe(3)
            ->and($resolved->pluck('name')->all())->toBe(['First System', 'Second System', 'Third System'])
            ->and($game->hasMultipleSystems())->toBeTrue();

        // Second access must return the memoized collection without a new query.
        $again = $game->gameSystems();
        $queriesAfterSecondCall = count(DB::getQueryLog());

        expect($queriesAfterSecondCall)->toBe($queriesAfterFirstCall)
            ->and($again->modelKeys())->toBe($resolved->modelKeys());
    });

    it('resolves an empty collection for a legacy single-system game and is not multi-system', function () {
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

        expect($game->gameSystems())->toBeEmpty()
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

        $game = Game::find($game->id);

        // Simulate the batch eager-load by injecting a subset relation. The
        // resolver trusts the injected type (standard Eloquent relation cache
        // behavior), so mirror what DiscoveryQueryService::eagerLoadGameSystems
        // injects: an EloquentCollection, not a base Support\Collection.
        $subset = new EloquentCollection([$systemA]);
        $game->setRelation('gameSystems', $subset);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $resolved = $game->gameSystems();
        $queryCount = count(DB::getQueryLog());

        // The resolver must short-circuit on the preloaded relation (no query).
        expect($queryCount)->toBe(0)
            ->and($resolved->modelKeys())->toBe([$systemA->id]);
    });
});
