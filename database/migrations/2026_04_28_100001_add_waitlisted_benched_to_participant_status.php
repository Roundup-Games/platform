<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * PostgreSQL stores ParticipantStatus values as varchar with CHECK constraints.
     * We must drop and recreate the constraints to include 'waitlisted' and 'benched'.
     */
    public function up(): void
    {
        $newValues = "ARRAY['approved'::character varying, 'rejected'::character varying, 'pending'::character varying, 'waitlisted'::character varying, 'benched'::character varying]";

        // game_participants.status
        DB::statement("ALTER TABLE game_participants DROP CONSTRAINT IF EXISTS game_participants_status_check");
        DB::statement("ALTER TABLE game_participants ADD CONSTRAINT game_participants_status_check CHECK ((status)::text = ANY ({$newValues}::text[]))");

        // campaign_participants.status
        DB::statement("ALTER TABLE campaign_participants DROP CONSTRAINT IF EXISTS campaign_participants_status_check");
        DB::statement("ALTER TABLE campaign_participants ADD CONSTRAINT campaign_participants_status_check CHECK ((status)::text = ANY ({$newValues}::text[]))");

        \Log::info('Expanded participant status CHECK constraints to include waitlisted and benched.');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $oldValues = "ARRAY['approved'::character varying, 'rejected'::character varying, 'pending'::character varying]";

        DB::statement("ALTER TABLE game_participants DROP CONSTRAINT IF EXISTS game_participants_status_check");
        DB::statement("ALTER TABLE game_participants ADD CONSTRAINT game_participants_status_check CHECK ((status)::text = ANY ({$oldValues}::text[]))");

        DB::statement("ALTER TABLE campaign_participants DROP CONSTRAINT IF EXISTS campaign_participants_status_check");
        DB::statement("ALTER TABLE campaign_participants ADD CONSTRAINT campaign_participants_status_check CHECK ((status)::text = ANY ({$oldValues}::text[]))");
    }
};
