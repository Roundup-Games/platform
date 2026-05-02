<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Migrate the teams table from auto-increment integer PK to UUID v7.
 *
 * Also migrates all FK columns that reference teams.id:
 *   - team_members.team_id
 *   - event_registrations.team_id
 *
 * Note: The Spatie permission tables (model_has_roles.team_id,
 * model_has_permissions.team_id, roles.team_id) are already varchar(36)
 * from a prior migration and store Event UUIDs as scope — they do NOT
 * reference teams.id via FK constraint, so no migration needed there.
 *
 * Note: teams.created_by references users.id (still bigint) — not touched.
 *
 * Strategy:
 *   1. Add temporary `uuid` column to teams.
 *   2. Backfill with UUID v7 values.
 *   3. Drop existing FK constraints from referencing tables.
 *   4. Map old integer FK values to new UUID values across all referencing tables.
 *   5. Drop old `id` PK, rename `uuid` to `id` as primary.
 *   6. Drop and re-add all FK columns as uuid type with restored FK constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Add uuid column to teams
        Schema::table('teams', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // Step 2: Backfill with UUID v7 values
        $teams = DB::table('teams')->get();
        foreach ($teams as $team) {
            DB::table('teams')
                ->where('id', $team->id)
                ->update(['uuid' => (string) Str::orderedUuid()]);
        }

        // Build the old-id → new-uuid map
        $idMap = DB::table('teams')->pluck('uuid', 'id');

        // Step 3: Drop all FK constraints from referencing tables
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });

        // Step 4: Map old integer FK values to new UUID values
        foreach ($idMap as $oldId => $newUuid) {
            DB::table('team_members')->where('team_id', $oldId)->update(['team_id' => $newUuid]);
            DB::table('event_registrations')->where('team_id', $oldId)->update(['team_id' => $newUuid]);
        }

        // Step 5: Drop old id PK, rename uuid to id
        Schema::table('teams', function (Blueprint $table) {
            $table->dropPrimary('id');
            $table->dropColumn('id');
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->uuid('id')->primary()->first();
        });

        // Copy UUID values from uuid column to new id column
        DB::statement('UPDATE teams SET id = uuid');
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        // Step 6: Drop and re-add all FK columns as uuid type

        // team_members.team_id
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropColumn('team_id');
        });
        Schema::table('team_members', function (Blueprint $table) {
            $table->uuid('team_id')->first();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
        });

        // event_registrations.team_id (nullable — teams are optional for individual registrations)
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropColumn('team_id');
        });
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->uuid('team_id')->nullable()->after('event_id');
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Drop FK constraints from all referencing tables
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
        });
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
        });

        // Revert teams PK to auto-increment
        Schema::table('teams', function (Blueprint $table) {
            $table->dropPrimary('id');
            $table->dropColumn('id');
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->id()->first();
        });

        // Re-add integer FK columns
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreignId('team_id')->first()->constrained('teams')->cascadeOnDelete();
        });
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('event_id')->constrained('teams')->nullOnDelete();
        });
    }
};
