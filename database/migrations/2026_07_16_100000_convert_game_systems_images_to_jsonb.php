<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert `game_systems.images` from `json` to `jsonb`.
 *
 * PostgreSQL has no equality operator for the `json` type, only for `jsonb`.
 * When Filament's `gameSystems` multi-select (`->relationship()->preload()`)
 * hydrates the currently-attached options on the admin "Edit game" page, it
 * issues `SELECT DISTINCT "game_systems".* ... LEFT JOIN "game_game_system"`,
 * and the `DISTINCT` over the `json`-typed `images` column fails with
 * `SQLSTATE[42883]: could not identify an equality operator for type json`,
 * 500ing the page. The sibling `name` and `description` columns are already
 * `jsonb` in the schema baseline (`database/schema/pgsql-schema.sql`); this
 * brings `images` in line so any DISTINCT/GROUP BY path over the row works.
 *
 * Reproduced in tests/Feature/Admin/EditGamePageTest.php.
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
