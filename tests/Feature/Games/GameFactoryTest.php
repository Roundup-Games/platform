<?php

use App\Enums\GameType;
use App\Models\Game;
use App\Models\GameSystem;

// S06 retired the games.game_system_id anchor + game_systems JSON array +
// saving-event sync, replacing them with the belongsToMany gameSystems pivot.
// These tests assert the factory produces games whose offered systems are
// correctly attached through that pivot, and that the getGameSystemIdAttribute
// bridge accessor (representative system) stays consistent with the pivot set.
//
// ->fresh() after create() is needed because the default factory's afterCreating
// callback touches $game->gameSystems (caching the pre-sync empty collection on
// the in-memory model); a fresh reload reads the populated pivot cleanly.

// ── Default (single-system) state ─────────────────────────────────────────

it('defaults to a single-system board game with one offered system', function () {
    $game = Game::factory()->create()->fresh();

    expect($game->game_type)->toBe(GameType::BoardGame)
        ->and($game->gameSystems)->toHaveCount(1)
        ->and($game->game_system_id)->toBe($game->gameSystems->first()->id);
});

it('persists the offered system across reload', function () {
    $game = Game::factory()->create()->fresh();

    expect($game->gameSystems)->toHaveCount(1)
        ->and($game->game_system_id)->toBe($game->gameSystems->first()->id);
});

// ── Gathering state ───────────────────────────────────────────────────────

it('produces a multi-system Gathering offering two systems', function () {
    $game = Game::factory()->gathering()->create()->fresh();

    expect($game->game_type)->toBe(GameType::Gathering)
        ->and($game->gameSystems)->toHaveCount(2)
        ->and($game->game_system_id)->toBe($game->gameSystems->first()->id);
});

it('persists the Gathering offering across reload', function () {
    $game = Game::factory()->gathering()->create()->fresh();

    expect($game->gameSystems)->toHaveCount(2)
        ->and($game->game_system_id)->toBe($game->gameSystems->first()->id);
});

// ── withGameSystems helper ────────────────────────────────────────────────

it('applies an explicit multi-system set via withGameSystems', function () {
    $ids = GameSystem::factory()->count(3)->create()->modelKeys();

    $game = Game::factory()->gathering()->withGameSystems($ids)->create()->fresh();

    $offered = $game->gameSystems->modelKeys();
    $expected = $ids;
    sort($offered);
    sort($expected);

    // The exact set offered (order-independent — pivot order is not guaranteed
    // to match input order) plus a representative accessor consistent with it.
    expect($offered)->toBe($expected)
        ->and($game->game_system_id)->toBe($game->gameSystems->first()->id);
});
