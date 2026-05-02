<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Migrate the game_systems table from auto-increment integer PK to UUID v7.
 *
 * Also migrates all FK columns that reference game_systems.id:
 *   - games.game_system_id
 *   - campaigns.game_system_id
 *   - game_system_category.game_system_id (composite PK)
 *   - game_system_mechanic.game_system_id (composite PK)
 *   - user_game_system_preferences.game_system_id (composite PK)
 *   - game_system_family.game_system_id (composite PK)
 *   - game_system_designer.game_system_id (composite PK)
 *   - game_system_publisher.game_system_id (composite PK)
 *   - game_system_requests.game_system_id
 *   - bgg_sync_logs.game_system_id
 *   - game_systems.base_game_id (self-referencing)
 *
 * Strategy:
 *   1. Add temporary `uuid` column to game_systems.
 *   2. Backfill with UUID v7 values.
 *   3. Drop existing FK constraints.
 *   4. Map old integer FK values to new UUID values across all referencing tables.
 *   5. Drop old `id` PK, rename `uuid` to `id` as primary.
 *   6. Drop and re-add all FK columns as uuid type with restored FK constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Add uuid column to game_systems
        Schema::table('game_systems', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // Step 2: Backfill with UUID v7 values
        $systems = DB::table('game_systems')->get();
        foreach ($systems as $system) {
            DB::table('game_systems')
                ->where('id', $system->id)
                ->update(['uuid' => (string) Str::orderedUuid()]);
        }

        // Build the old-id → new-uuid map
        $idMap = DB::table('game_systems')->pluck('uuid', 'id');

        // Step 3: Drop all FK constraints from referencing tables

        // Pivot tables (composite PKs — drop FK first, then the column)
        Schema::table('game_system_category', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
        });
        Schema::table('game_system_mechanic', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
        });
        Schema::table('user_game_system_preferences', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
        });
        Schema::table('game_system_family', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
        });
        Schema::table('game_system_designer', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
        });
        Schema::table('game_system_publisher', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
        });

        // Entity tables
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
        });
        Schema::table('game_system_requests', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
        });
        Schema::table('bgg_sync_logs', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
        });
        // Self-referencing FK
        Schema::table('game_systems', function (Blueprint $table) {
            $table->dropForeign(['base_game_id']);
        });

        // Step 4: Map old integer FK values to new UUID values
        foreach ($idMap as $oldId => $newUuid) {
            // Pivot tables
            DB::table('game_system_category')->where('game_system_id', $oldId)->update(['game_system_id' => $newUuid]);
            DB::table('game_system_mechanic')->where('game_system_id', $oldId)->update(['game_system_id' => $newUuid]);
            DB::table('user_game_system_preferences')->where('game_system_id', $oldId)->update(['game_system_id' => $newUuid]);
            DB::table('game_system_family')->where('game_system_id', $oldId)->update(['game_system_id' => $newUuid]);
            DB::table('game_system_designer')->where('game_system_id', $oldId)->update(['game_system_id' => $newUuid]);
            DB::table('game_system_publisher')->where('game_system_id', $oldId)->update(['game_system_id' => $newUuid]);

            // Entity tables
            DB::table('games')->where('game_system_id', $oldId)->update(['game_system_id' => $newUuid]);
            DB::table('campaigns')->where('game_system_id', $oldId)->update(['game_system_id' => $newUuid]);
            DB::table('game_system_requests')->where('game_system_id', $oldId)->update(['game_system_id' => $newUuid]);
            DB::table('bgg_sync_logs')->where('game_system_id', $oldId)->update(['game_system_id' => $newUuid]);

            // Self-referencing
            DB::table('game_systems')->where('base_game_id', $oldId)->update(['base_game_id' => $newUuid]);
        }

        // Step 5: Drop old id PK, rename uuid to id
        Schema::table('game_systems', function (Blueprint $table) {
            $table->dropPrimary('id');
            $table->dropColumn('id');
        });
        Schema::table('game_systems', function (Blueprint $table) {
            $table->uuid('id')->primary()->first();
        });

        // Copy UUID values from uuid column to new id column
        DB::statement('UPDATE game_systems SET id = uuid');
        Schema::table('game_systems', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        // Step 6: Drop and re-add all FK columns as uuid type

        // Pivot: game_system_category (composite PK)
        Schema::table('game_system_category', function (Blueprint $table) {
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_system_category', function (Blueprint $table) {
            $table->uuid('game_system_id')->first();
            $table->foreign('game_system_id')->references('id')->on('game_systems')->cascadeOnDelete();
            // Re-establish composite primary key
            $table->primary(['game_system_id', 'game_system_category_id']);
        });

        // Pivot: game_system_mechanic (composite PK)
        Schema::table('game_system_mechanic', function (Blueprint $table) {
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_system_mechanic', function (Blueprint $table) {
            $table->uuid('game_system_id')->first();
            $table->foreign('game_system_id')->references('id')->on('game_systems')->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_mechanic_id']);
        });

        // Pivot: user_game_system_preferences (composite PK)
        Schema::table('user_game_system_preferences', function (Blueprint $table) {
            $table->dropColumn('game_system_id');
        });
        Schema::table('user_game_system_preferences', function (Blueprint $table) {
            $table->uuid('game_system_id')->after('user_id');
            $table->foreign('game_system_id')->references('id')->on('game_systems')->cascadeOnDelete();
            $table->primary(['user_id', 'game_system_id']);
        });

        // Pivot: game_system_family (composite PK)
        Schema::table('game_system_family', function (Blueprint $table) {
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_system_family', function (Blueprint $table) {
            $table->uuid('game_system_id')->first();
            $table->foreign('game_system_id')->references('id')->on('game_systems')->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_family_id']);
        });

        // Pivot: game_system_designer (composite PK)
        Schema::table('game_system_designer', function (Blueprint $table) {
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_system_designer', function (Blueprint $table) {
            $table->uuid('game_system_id')->first();
            $table->foreign('game_system_id')->references('id')->on('game_systems')->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_designer_id']);
        });

        // Pivot: game_system_publisher (composite PK)
        Schema::table('game_system_publisher', function (Blueprint $table) {
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_system_publisher', function (Blueprint $table) {
            $table->uuid('game_system_id')->first();
            $table->foreign('game_system_id')->references('id')->on('game_systems')->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_publisher_id']);
        });

        // games.game_system_id
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('game_system_id');
        });
        Schema::table('games', function (Blueprint $table) {
            $table->uuid('game_system_id')->nullable()->after('campaign_id');
            $table->foreign('game_system_id')->references('id')->on('game_systems')->cascadeOnDelete();
        });

        // campaigns.game_system_id
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('game_system_id');
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->uuid('game_system_id')->nullable()->first();
            $table->foreign('game_system_id')->references('id')->on('game_systems')->cascadeOnDelete();
        });

        // game_system_requests.game_system_id
        Schema::table('game_system_requests', function (Blueprint $table) {
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_system_requests', function (Blueprint $table) {
            $table->uuid('game_system_id')->nullable()->after('status');
            $table->foreign('game_system_id')->references('id')->on('game_systems')->nullOnDelete();
        });

        // bgg_sync_logs.game_system_id
        Schema::table('bgg_sync_logs', function (Blueprint $table) {
            $table->dropColumn('game_system_id');
        });
        Schema::table('bgg_sync_logs', function (Blueprint $table) {
            $table->uuid('game_system_id')->nullable()->after('id');
            $table->foreign('game_system_id')->references('id')->on('game_systems')->nullOnDelete();
        });

        // game_systems.base_game_id (self-referencing)
        Schema::table('game_systems', function (Blueprint $table) {
            $table->dropColumn('base_game_id');
        });
        Schema::table('game_systems', function (Blueprint $table) {
            $table->uuid('base_game_id')->nullable()->after('thumbnail_url');
            $table->foreign('base_game_id')->references('id')->on('game_systems')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Drop FK constraints from all referencing tables
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
            $table->dropColumn('game_system_id');
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_system_category', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
            $table->dropPrimary();
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_system_mechanic', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
            $table->dropPrimary();
            $table->dropColumn('game_system_id');
        });
        Schema::table('user_game_system_preferences', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
            $table->dropPrimary();
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_system_family', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
            $table->dropPrimary();
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_system_designer', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
            $table->dropPrimary();
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_system_publisher', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
            $table->dropPrimary();
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_system_requests', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
            $table->dropColumn('game_system_id');
        });
        Schema::table('bgg_sync_logs', function (Blueprint $table) {
            $table->dropForeign(['game_system_id']);
            $table->dropColumn('game_system_id');
        });
        Schema::table('game_systems', function (Blueprint $table) {
            $table->dropForeign(['base_game_id']);
            $table->dropColumn('base_game_id');
        });

        // Revert game_systems PK to auto-increment
        Schema::table('game_systems', function (Blueprint $table) {
            $table->dropPrimary('id');
            $table->dropColumn('id');
        });
        Schema::table('game_systems', function (Blueprint $table) {
            $table->id()->first();
        });

        // Re-add integer FK columns
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('game_system_id')->nullable()->after('campaign_id')->constrained('game_systems')->cascadeOnDelete();
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('game_system_id')->nullable()->first()->constrained('game_systems')->cascadeOnDelete();
        });
        Schema::table('game_system_category', function (Blueprint $table) {
            $table->foreignId('game_system_id')->first()->constrained('game_systems')->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_category_id']);
        });
        Schema::table('game_system_mechanic', function (Blueprint $table) {
            $table->foreignId('game_system_id')->first()->constrained('game_systems')->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_mechanic_id']);
        });
        Schema::table('user_game_system_preferences', function (Blueprint $table) {
            $table->foreignId('game_system_id')->after('user_id')->constrained('game_systems')->cascadeOnDelete();
            $table->primary(['user_id', 'game_system_id']);
        });
        Schema::table('game_system_family', function (Blueprint $table) {
            $table->foreignId('game_system_id')->first()->constrained('game_systems')->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_family_id']);
        });
        Schema::table('game_system_designer', function (Blueprint $table) {
            $table->foreignId('game_system_id')->first()->constrained('game_systems')->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_designer_id']);
        });
        Schema::table('game_system_publisher', function (Blueprint $table) {
            $table->foreignId('game_system_id')->first()->constrained('game_systems')->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_publisher_id']);
        });
        Schema::table('game_system_requests', function (Blueprint $table) {
            $table->foreignId('game_system_id')->nullable()->after('status')->constrained('game_systems')->nullOnDelete();
        });
        Schema::table('bgg_sync_logs', function (Blueprint $table) {
            $table->foreignId('game_system_id')->nullable()->after('id')->constrained('game_systems')->nullOnDelete();
        });
        Schema::table('game_systems', function (Blueprint $table) {
            $table->foreignId('base_game_id')->nullable()->after('thumbnail_url')->constrained('game_systems')->nullOnDelete();
        });
    }
};
