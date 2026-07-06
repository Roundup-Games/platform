<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert games.game_systems from json to jsonb and add a GIN containment
     * index so whereJsonContains() (@>) lookups are index-backed.
     *
     * S01 created the column as `json`; PostgreSQL GIN containment indexes
     * require `jsonb`. This is the deferred index follow-up flagged by S01
     * (see S03 research), mirroring the notifications.data conversion in
     * 2026_06_08_142711_change_notifications_data_to_jsonb.php.
     *
     * The column is null for every existing row, so the type conversion is a
     * trivial pass-through (NULL::jsonb is NULL).
     *
     * jsonb_path_ops is the right opclass: a smaller, faster index that
     * supports exactly the `@>` containment operator Laravel's whereJsonContains
     * emits. The default jsonb_ops opclass is larger and not needed here.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE games ALTER COLUMN game_systems TYPE jsonb USING game_systems::jsonb');

        DB::statement('CREATE INDEX games_game_systems_gin_idx ON games USING GIN (game_systems jsonb_path_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS games_game_systems_gin_idx');

        DB::statement('ALTER TABLE games ALTER COLUMN game_systems TYPE json USING game_systems::json');
    }
};
