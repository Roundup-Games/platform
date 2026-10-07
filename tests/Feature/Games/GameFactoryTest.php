<?php

use App\Enums\GameType;
use App\Models\Event;
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

// ── event() state (M063/S05: host a table at an event) ───────────────────

it('links a game to an event via the event() state', function () {
    $event = Event::factory()->create();
    $game = Game::factory()->event($event)->create();

    expect($game->event_id)->toBe($event->id)
        ->and($game->event->is($event))->toBeTrue();
});

it('creates an event implicitly when event() gets no argument', function () {
    $game = Game::factory()->gathering()->event()->create();

    expect($game->event_id)->not->toBeNull()
        ->and($game->event)->toBeInstanceOf(Event::class)
        ->and($game->game_type)->toBe(GameType::Gathering);
});

it('leaves standalone games unlinked (nullable event_id)', function () {
    $game = Game::factory()->create();

    expect($game->event_id)->toBeNull()
        ->and($game->event)->toBeNull();
});

it('lists event tables ordered by start time via Event::tables()', function () {
    $event = Event::factory()->create();

    $late = Game::factory()->event($event)->create(['date_time' => now()->addDays(2)]);
    $early = Game::factory()->event($event)->create(['date_time' => now()->addDay()]);
    $standalone = Game::factory()->create();

    $tables = $event->tables()->get();

    expect($tables)->toHaveCount(2)
        ->and($tables->pluck('id')->all())->toBe([$early->id, $late->id])
        ->and($tables->pluck('id'))->not->toContain($standalone->id);
});

it('counts tables via withCount aggregate and query fallback', function () {
    $event = Event::factory()->create();
    Game::factory()->event($event)->count(3)->create();

    // Fallback path: no aggregate selected -> count query.
    expect($event->tablesCount())->toBe(3)
        // Aggregate path: withCount selects tables_count -> no extra query.
        ->and(Event::withCount('tables')->findOrFail($event->id)->tablesCount())->toBe(3);
});
