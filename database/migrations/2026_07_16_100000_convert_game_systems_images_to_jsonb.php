<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert `game_systems.images` from `json` to `jsonb`.
 *
 * `images` is the last `json`-typed column on `game_systems` — its siblings
 * `name` and `description` are already `jsonb` in the schema baseline
 * (`database/schema/pgsql-schema.sql`). PostgreSQL has no equality operator
 * for the `json` type (only `jsonb`), so any query that does `DISTINCT` or
 * `GROUP BY` over the full row throws
 * `SQLSTATE[42883]: could not identify an equality operator for type json`.
 * The known trigger was Filament's default belongsToMany option query
 * (`SELECT DISTINCT "game_systems".* ... LEFT JOIN "game_game_system"`).
 *
 * The user-facing admin "Edit game" 500 that surfaced this was already fixed
 * at the application layer by f145d8d5 (GameResource/CampaignResource now
 * select only `id` + `name->>'en'` via `GameSystem::labelOptions()`, avoiding
 * `*` / `DISTINCT` / the json column entirely). This migration is complementary
 * schema hygiene: it aligns `images` with its `jsonb` siblings and removes the
 * latent landmine so that ANY future `DISTINCT`/`GROUP BY` path over
 * `game_systems` rows is safe, not just the one Filament path patched in app.
 *
 * Guarded by tests/Feature/Database/GameSystemsImagesJsonbTest.php.
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
