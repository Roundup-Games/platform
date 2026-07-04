<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create the canonical game ↔ game_system many-to-many pivot (S06/T01).
 *
 * This is the first half of replacing the cached game_system_id anchor +
 * game_systems JSON-array pattern with a referentially-integral pivot. The
 * migration is strictly additive: it creates the pivot table and backfills it
 * from the existing columns, but does NOT touch games.game_system_id or
 * games.game_systems (those are retired in T06). Both read paths coexist
 * until the model layer migrates in T02-T05.
 *
 * Backfill is two-step so the pivot ends up with exactly one row per offered
 * system per game:
 *   1. The cached anchor (games.game_system_id) — covers every single-system
 *      board_book/ttrpg AND the anchor of every multi-system Gathering.
 *   2. The expanded games.game_systems JSON array — adds the remaining
 *      systems of multi-system Gatherings, with ON CONFLICT DO NOTHING
 *      deduping the anchor that step (1) already inserted.
 *
 * Both INSERTs carry ON CONFLICT DO NOTHING so the migration is safely
 * re-runnable (MEM662/MEM663 idempotent-backfill pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_game_system', function (Blueprint $table) {
            $table->uuid('game_id');
            $table->uuid('game_system_id');

            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->foreign('game_system_id')->references('id')->on('game_systems')->cascadeOnDelete();
            $table->primary(['game_id', 'game_system_id']);

            // The composite PK covers lookups by game_id (forward direction);
            // add a standalone index on game_system_id for the reverse join
            // (GameSystem::games(), whereHas('gameSystems')) that T03 introduces.
            $table->index('game_system_id');
        });

        // Step 1 — every cached anchor (single-system games + Gathering anchors).
        DB::statement(<<<'SQL'
            INSERT INTO game_game_system (game_id, game_system_id)
            SELECT id, game_system_id
            FROM games
            WHERE game_system_id IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);

        // Step 2 — remaining systems of multi-system Gatherings (anchor deduped).
        // jsonb_array_elements_text yields text; cast to uuid to match the FK column.
        DB::statement(<<<'SQL'
            INSERT INTO game_game_system (game_id, game_system_id)
            SELECT g.id, sys::uuid
            FROM games g, jsonb_array_elements_text(g.game_systems) AS sys
            WHERE g.game_systems IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);
    }

    public function down(): void
    {
        // The pivot is derived data; dropping it is the correct rollback.
        // The legacy game_system_id / game_systems columns remain intact.
        Schema::dropIfExists('game_game_system');
    }
};
