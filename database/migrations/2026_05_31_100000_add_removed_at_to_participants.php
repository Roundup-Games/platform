<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add removed_by and removed_at columns to participant tables.
     *
     * When a host removes an approved participant, the record is set to
     * status='removed' (not hard-deleted) so the roster history is preserved.
     * These columns record who initiated the removal and when.
     *
     * Also expands the CHECK constraint on status to include 'removed'.
     */
    public function up(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->uuid('removed_by')->nullable()->after('short_link_id');
            $table->foreign('removed_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable()->after('removed_by');
        });

        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->uuid('removed_by')->nullable()->after('short_link_id');
            $table->foreign('removed_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable()->after('removed_by');
        });

        // Expand CHECK constraints to include 'removed' status
        $newValues = "ARRAY['approved'::character varying, 'rejected'::character varying, 'pending'::character varying, 'waitlisted'::character varying, 'benched'::character varying, 'removed'::character varying]";

        DB::statement("ALTER TABLE game_participants DROP CONSTRAINT IF EXISTS game_participants_status_check");
        DB::statement("ALTER TABLE game_participants ADD CONSTRAINT game_participants_status_check CHECK ((status)::text = ANY ({$newValues}::text[]))");

        DB::statement("ALTER TABLE campaign_participants DROP CONSTRAINT IF EXISTS campaign_participants_status_check");
        DB::statement("ALTER TABLE campaign_participants ADD CONSTRAINT campaign_participants_status_check CHECK ((status)::text = ANY ({$newValues}::text[]))");
    }

    public function down(): void
    {
        // Pre-clean: any rows with status='removed' will violate the reverted
        // CHECK constraint. Reset them to 'rejected' so rollback succeeds.
        DB::table('game_participants')->where('status', 'removed')->update(['status' => 'rejected']);
        DB::table('campaign_participants')->where('status', 'removed')->update(['status' => 'rejected']);

        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropForeign(['removed_by']);
            $table->dropColumn(['removed_by', 'removed_at']);
        });

        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->dropForeign(['removed_by']);
            $table->dropColumn(['removed_by', 'removed_at']);
        });

        // Revert CHECK constraints
        $oldValues = "ARRAY['approved'::character varying, 'rejected'::character varying, 'pending'::character varying, 'waitlisted'::character varying, 'benched'::character varying]";

        DB::statement("ALTER TABLE game_participants DROP CONSTRAINT IF EXISTS game_participants_status_check");
        DB::statement("ALTER TABLE game_participants ADD CONSTRAINT game_participants_status_check CHECK ((status)::text = ANY ({$oldValues}::text[]))");

        DB::statement("ALTER TABLE campaign_participants DROP CONSTRAINT IF EXISTS campaign_participants_status_check");
        DB::statement("ALTER TABLE campaign_participants ADD CONSTRAINT campaign_participants_status_check CHECK ((status)::text = ANY ({$oldValues}::text[]))");
    }
};
