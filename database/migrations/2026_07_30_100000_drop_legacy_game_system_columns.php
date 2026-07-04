<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the legacy cached-anchor game_system_id columns, the game_systems
     * JSON array, and the GIN containment index.
     *
     * S06 replaced the cached-anchor pattern with a canonical belongsToMany
     * pivot (game_game_system / campaign_game_system). Every read/write site
     * now routes through the pivot relations; these columns and the GIN index
     * are dead weight.
     *
     * Order matters: drop the index BEFORE the column it indexes, then drop
     * FK constraints + columns.
     */
    public function up(): void
    {
        // 1. Drop the GIN containment index on games.game_systems (jsonb).
        DB::statement('DROP INDEX IF EXISTS games_game_systems_gin_idx');

        // 2. Drop games.game_systems (jsonb array) and games.game_system_id.
        Schema::table('games', function (Blueprint $table) {
            if (Schema::hasColumn('games', 'game_systems')) {
                $table->dropColumn('game_systems');
            }
        });
        Schema::table('games', function (Blueprint $table) {
            // Drop the FK + column. The FK name follows Laravel's convention
            // (<table>_<column>_foreign); guard with hasColumn so re-runs are safe.
            if (Schema::hasColumn('games', 'game_system_id')) {
                $table->dropForeign(['game_system_id']);
                $table->dropColumn('game_system_id');
            }
        });

        // 3. Drop campaigns.game_system_id.
        Schema::table('campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('campaigns', 'game_system_id')) {
                $table->dropForeign(['game_system_id']);
                $table->dropColumn('game_system_id');
            }
        });
    }

    public function down(): void
    {
        // Re-add the cached-anchor columns + game_systems JSON array (best-effort
        // shape only; pivot data is NOT copied back — this is a point-of-no-return
        // migration in practice, kept reversible only for schema parity).
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('game_system_id')
                ->nullable()
                ->constrained('game_systems')
                ->cascadeOnDelete()
                ->after('campaign_id');
            $table->json('game_systems')->nullable()->after('game_system_id');
        });
        DB::statement('ALTER TABLE games ALTER COLUMN game_systems TYPE jsonb USING game_systems::jsonb');
        DB::statement('CREATE INDEX games_game_systems_gin_idx ON games USING GIN (game_systems jsonb_path_ops)');

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('game_system_id')
                ->nullable()
                ->constrained('game_systems')
                ->cascadeOnDelete()
                ->after('owner_id');
        });
    }
};
