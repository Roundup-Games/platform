<?php

use App\Enums\GameType;
use App\Models\Game;
use App\Models\GameSystem;
use Illuminate\Support\Facades\Log;

/**
 * S01 data-model contract tests for multi-system Gatherings.
 *
 * These are storage-layer contracts (enum, anchor sync, drift detection, JSON
 * containment) — the foundation S02-S05 build on. They deliberately bypass the
 * participant/subscription machinery in CreatesGameInstances/CreatesUsers and
 * drive the Game model directly through GameFactory, which is the precise tool
 * for asserting the game_systems / game_system_id invariant.
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

// ── 2. Anchor sync (critical path) ────────────────────────────────────────

it('persists a multi-system Gathering and syncs the anchor to game_systems[0]', function () {
    $ids = GameSystem::factory()->count(3)->create()->modelKeys();
    [$a, $b, $c] = $ids;

    $game = Game::factory()->gathering()->withGameSystems([$a, $b, $c])->create();

    // In-memory state is correct...
    expect($game->game_systems)->toBe([$a, $b, $c])
        ->and($game->game_system_id)->toBe($a);

    // ...and so is the persisted state (reloaded from the DB).
    $reloaded = $game->fresh();

    expect($reloaded->game_systems)->toBe([$a, $b, $c])
        ->and($reloaded->game_system_id)->toBe($a);
})->group('smoke');

// ── 3. Legacy single-system path unaffected ──────────────────────────────

it('leaves game_system_id unchanged when game_systems is null (single-system path)', function () {
    $game = Game::factory()->create(); // board_game, game_systems = null

    expect($game->game_type)->toBe(GameType::BoardGame)
        ->and($game->game_systems)->toBeNull();

    $originalAnchor = $game->game_system_id;

    // Touch the model and re-save to prove the saving event is a no-op for the
    // single-system path: the anchor is not clobbered by the null array.
    $game->save();

    expect($game->fresh()->game_system_id)->toBe($originalAnchor);
});

// ── 4. Drift detection ────────────────────────────────────────────────────

it('logs a drift warning and corrects the anchor when game_system_id is out of sync', function () {
    Log::spy();

    $systems = GameSystem::factory()->count(3)->create();
    [$a, $b, $c] = $systems->modelKeys();

    // Array is [A, B] but the anchor is manually seeded with C (drift). The
    // create() override is applied after withGameSystems(), so the factory
    // yields a model whose anchor disagrees with its array's first element.
    $game = Game::factory()->gathering()->withGameSystems([$a, $b])->create([
        'game_system_id' => $c,
    ]);

    // The saving event must correct the anchor back to the array's first element.
    expect($game->fresh()->game_system_id)->toBe($a);

    Log::shouldHaveReceived('warning')
        ->with('game.game_systems.anchor_drift_detected', Mockery::on(function ($ctx) use ($c, $a) {
            return is_array($ctx)
                && ($ctx['old_anchor'] ?? null) === $c
                && ($ctx['new_anchor'] ?? null) === $a;
        }))
        ->once();
});

it('does not log a drift warning when the anchor already matches game_systems[0]', function () {
    Log::spy();

    $ids = GameSystem::factory()->count(2)->create()->modelKeys();
    [$a, $b] = $ids;

    Game::factory()->gathering()->withGameSystems([$a, $b])->create();

    Log::shouldNotHaveReceived('warning');
});

// ── 5. JSON containment against real pgsql ────────────────────────────────

it('finds a multi-system gathering via whereJsonContains on pgsql', function () {
    $systems = GameSystem::factory()->count(3)->create();
    [$a, $b, $c] = $systems->modelKeys();

    $target = Game::factory()->gathering()->withGameSystems([$a, $b, $c])->create();
    // Decoy that contains A and C but NOT B — must be excluded by the query.
    Game::factory()->gathering()->withGameSystems([$a, $c])->create();
    // Decoy single-system game — null array, never matches.
    Game::factory()->create();

    $found = Game::whereJsonContains('game_systems', $b)->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->is($target))->toBeTrue();
});
