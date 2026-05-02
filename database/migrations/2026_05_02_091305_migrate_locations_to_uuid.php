<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Migrate the locations table from auto-increment integer PK to UUID v7.
 *
 * Also migrates all FK columns that reference locations.id:
 *   - games.location_id
 *   - events.location_id
 *   - users.location_id
 *   - campaigns.location_id
 *
 * Strategy:
 *   1. Add temporary `uuid` column to locations.
 *   2. Backfill with UUID v7 values.
 *   3. Drop existing FK constraints and the old `id` column.
 *   4. Rename `uuid` to `id` and set it as primary.
 *   5. Change all FK columns from bigint to varchar(36) and re-add FK constraints.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Add uuid column to locations
        Schema::table('locations', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // Step 2: Backfill with UUID v7 values
        $locations = DB::table('locations')->get();
        foreach ($locations as $location) {
            DB::table('locations')
                ->where('id', $location->id)
                ->update(['uuid' => (string) Str::orderedUuid()]);
        }

        // Step 3: Drop FK constraints from referencing tables, then drop old id
        // We must drop FKs before we can change the referenced column.

        // Drop games.location_id FK
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        // Drop events.location_id FK
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        // Drop users.location_id FK
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        // Drop campaigns.location_id FK
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        // Step 4: Update FK values to UUID before dropping old id column.
        // Map old int IDs to new UUID values.
        $idMap = DB::table('locations')->pluck('uuid', 'id');

        // Update games FK values
        foreach ($idMap as $oldId => $newUuid) {
            DB::table('games')->where('location_id', $oldId)->update(['location_id' => $newUuid]);
            DB::table('events')->where('location_id', $oldId)->update(['location_id' => $newUuid]);
            DB::table('users')->where('location_id', $oldId)->update(['location_id' => $newUuid]);
            DB::table('campaigns')->where('location_id', $oldId)->update(['location_id' => $newUuid]);
        }

        // Step 5: Drop the old id primary key, rename uuid to id
        Schema::table('locations', function (Blueprint $table) {
            $table->dropPrimary('id');
            $table->dropColumn('id');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->uuid('id')->primary()->first();
        });

        // Copy UUID values from the uuid column to the new id column
        DB::statement('UPDATE locations SET id = uuid');
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        // Step 6: Change FK columns from bigint to varchar(36) and re-add FK constraints
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
        Schema::table('games', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->after('language');
            $table->foreign('location_id')
                ->references('id')->on('locations')
                ->nullOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->after('postal_code');
            $table->foreign('location_id')
                ->references('id')->on('locations')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->after('location');
            $table->foreign('location_id')
                ->references('id')->on('locations')
                ->nullOnDelete();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->after('game_system_id');
            $table->foreign('location_id')
                ->references('id')->on('locations')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * NOTE: This down() is intentionally minimal. Reverting a UUID migration
     * is complex and lossy (original integer IDs are gone). This exists
     * primarily for schema rollback during development.
     */
    public function down(): void
    {
        // Drop FK constraints
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });

        // Revert locations PK to auto-increment
        Schema::table('locations', function (Blueprint $table) {
            $table->dropPrimary('id');
            $table->dropColumn('id');
        });
        Schema::table('locations', function (Blueprint $table) {
            $table->id()->first();
        });

        // Re-add integer FK columns
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('language')->constrained('locations')->nullOnDelete();
        });
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('postal_code')->constrained('locations')->nullOnDelete();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('location')->constrained('locations')->nullOnDelete();
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('game_system_id')->constrained('locations')->nullOnDelete();
        });
    }
};
