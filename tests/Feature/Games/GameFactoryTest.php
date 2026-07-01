<?php

use App\Enums\GameType;
use App\Models\Game;
use App\Models\GameSystem;

// ── Default (single-system) state ─────────────────────────────────────────

it('defaults to a single-system board game with a null game_systems array', function () {
    $game = Game::factory()->create();

    expect($game->game_type)->toBe(GameType::BoardGame)
        ->and($game->game_systems)->toBeNull()
        ->and($game->game_system_id)->not->toBeNull();
});

it('leaves the legacy anchor unchanged when game_systems is null', function () {
    // The saving event must not touch game_system_id for single-system games.
    $game = Game::factory()->create();

    expect($game->fresh()->game_system_id)->toBe($game->game_system_id);
});

// ── Gathering state ───────────────────────────────────────────────────────

it('produces a multi-system Gathering whose anchor matches the first element', function () {
    $game = Game::factory()->gathering()->create();

    expect($game->game_type)->toBe(GameType::Gathering)
        ->and($game->game_systems)->toBeArray()
        ->and($game->game_systems)->toHaveCount(2)
        ->and($game->game_system_id)->toBe($game->game_systems[0]);
});

it('keeps the anchor synced to game_systems[0] after reload (no drift)', function () {
    // The Game::saving event guarantees anchor == array[0]; reloading from the
    // DB confirms the persisted state, not just the in-memory factory output.
    $game = Game::factory()->gathering()->create();

    expect($game->fresh()->game_system_id)->toBe($game->game_systems[0]);
});

// ── withGameSystems helper ────────────────────────────────────────────────

it('applies an explicit multi-system set via withGameSystems', function () {
    $ids = GameSystem::factory()->count(3)->create()->modelKeys();

    $game = Game::factory()->gathering()->withGameSystems($ids)->create();

    expect($game->game_systems)->toBe($ids)
        ->and($game->game_system_id)->toBe($ids[0]);
});
