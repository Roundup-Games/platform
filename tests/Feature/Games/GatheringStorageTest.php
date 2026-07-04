<?php

use App\Enums\GameType;
use App\Models\Game;
use App\Models\GameSystem;

/**
 * S06 data-model contract tests for multi-system Gatherings.
 *
 * Asserts the belongsToMany pivot (game_game_system) that replaced the former
 * cached game_system_id anchor + game_systems JSON array. These are
 * storage-layer contracts (enum, pivot persistence, single-system default,
 * pivot membership query) — the foundation downstream slices build on. They
 * deliberately bypass the participant/subscription machinery in
 * CreatesGameInstances/CreatesUsers and drive the Game model directly through
 * GameFactory, which is the precise tool for asserting the pivot invariant.
 *
 * The former "anchor sync" and "drift detection" contracts are obsolete under
 * the pivot model: there is no denormalized anchor to keep in sync, so no saving
 * event and no drift to detect. Those tests were removed when the anchor column
 * was dropped in T06.
 *
 * Test isolation comes from the blanket DatabaseTransactions wrapper applied to
 * every Feature/ test in tests/Pest.php (Testcontainers-backed pgsql). No
 * per-file RefreshDatabase is needed.
 */

// ── 1. Enum exists and is castable ────────────────────────────────────────

it('exposes a Gathering case on the GameType enum and casts it on the model', function () {
    expect(GameType::Gathering->value)->toBe('gathering')
        ->and(GameType::tryFrom('gathering'))->toBe(GameType::Gathering)
        ->and(GameType::values())->toContain('gathering');

    $game = Game::factory()->gathering()->create();

    expect($game->game_type)->toBeInstanceOf(GameType::class)
        ->and($game->game_type)->toBe(GameType::Gathering);
});

// ── 2. Pivot persistence (critical path) ─────────────────────────────────

it('persists a multi-system Gathering and exposes every offered system via the pivot', function () {
    $ids = GameSystem::factory()->count(3)->create()->modelKeys();
    [$a, $b, $c] = $ids;

    $game = Game::factory()->gathering()->withGameSystems([$a, $b, $c])->create();

    // Every offered system is reachable through the belongsToMany relation...
    expect($game->gameSystems->modelKeys())->toEqualCanonicalizing([$a, $b, $c])
        ->and($game->hasMultipleSystems())->toBeTrue();

    // ...and survives a reload from the DB (pivot rows are committed, not a
    // transient in-memory state).
    $reloaded = $game->fresh();

    expect($reloaded->gameSystems->modelKeys())->toEqualCanonicalizing([$a, $b, $c])
        ->and($reloaded->hasMultipleSystems())->toBeTrue();
})->group('smoke');

// ── 3. Single-system default unaffected ──────────────────────────────────

it('attaches exactly one system to a single-system board_game and keeps it stable across re-saves', function () {
    $game = Game::factory()->create(); // board_game, factory attaches one default system

    // The factory's default-attach afterCreating callback lazy-loads the
    // relation to check isNotEmpty() before syncing, which caches an empty
    // Collection in memory. Reload to assert the persisted pivot state.
    $reloaded = $game->fresh()->load('gameSystems');

    expect($game->game_type)->toBe(GameType::BoardGame)
        ->and($reloaded->gameSystems)->toHaveCount(1)
        ->and($reloaded->hasMultipleSystems())->toBeFalse();

    // The pivot is the source of truth: re-saving the model (which under the
    // old model would have fired the anchor-sync saving event) neither drops
    // nor duplicates pivot rows, because there is no saving event anymore.
    $originalId = $reloaded->gameSystems->first()->id;
    $game->save();

    expect($game->fresh()->load('gameSystems')->gameSystems)->toHaveCount(1)
        ->and($game->fresh()->load('gameSystems')->gameSystems->first()->id)->toBe($originalId);
});

// ── 4. Pivot membership query (discovery shape) ──────────────────────────

it('finds a multi-system gathering via whereHas on the pivot', function () {
    $systems = GameSystem::factory()->count(3)->create();
    [$a, $b, $c] = $systems->modelKeys();

    $target = Game::factory()->gathering()->withGameSystems([$a, $b, $c])->create();
    // Decoy that offers A and C but NOT B — must be excluded by the query.
    Game::factory()->gathering()->withGameSystems([$a, $c])->create();
    // Decoy single-system game — its factory-default system is never B, so it
    // never matches.
    Game::factory()->create();

    // Mirrors DiscoveryQueryService::applySharedFilters: a game surfaces in a
    // system's feed iff that system is in its pivot set.
    $found = Game::whereHas('gameSystems', fn ($q) => $q->where('game_systems.id', $b))->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->is($target))->toBeTrue();
});
