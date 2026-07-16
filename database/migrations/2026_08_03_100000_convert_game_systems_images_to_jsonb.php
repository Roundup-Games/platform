<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert `game_systems.images` from `json` to `jsonb`.
 *
 * PostgreSQL has no equality operator for the `json` type, only for `jsonb`.
 * Filament's `gameSystems` multi-select (`->relationship()->preload()`) builds a
 * `SELECT DISTINCT "game_systems".*` query, and the `DISTINCT` over the `json`
 * column fails with `SQLSTATE[42883]: could not identify an equality operator
 * for type json`, 500ing the admin "Edit game" page. The sibling `name` and
 * `description` columns were already converted to `jsonb`
 * (2026_07_26_100000_convert_game_systems_to_jsonb_add_gin_index); this brings
 * `images` in line so any DISTINCT/GROUP BY path over the row works.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE game_systems ALTER COLUMN images TYPE jsonb USING images::jsonb');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE game_systems ALTER COLUMN images TYPE json USING images::json');
    }
};
