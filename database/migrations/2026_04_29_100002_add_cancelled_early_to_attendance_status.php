<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Modifies the attendance_status CHECK constraints on both
     * game_participants and attendance_reports tables to include
     * the new 'cancelled_early' value. This represents a participant
     * who cancelled >24h before the game — neutral (0.0 weight)
     * but counted toward the 5-game reliability minimum.
     *
     * PostgreSQL stores enum constraints as CHECK constraints, so we
     * must drop the old constraint and add the new one with raw SQL.
     */
    public function up(): void
    {
        // Update game_participants.attendance_status
        DB::statement("
            ALTER TABLE game_participants
            DROP CONSTRAINT IF EXISTS game_participants_attendance_status_check,
            ADD CONSTRAINT game_participants_attendance_status_check
            CHECK (attendance_status IN ('attended', 'no_show', 'late_cancel', 'excused', 'cancelled_early'))
        ");

        // Update attendance_reports.status
        DB::statement("
            ALTER TABLE attendance_reports
            DROP CONSTRAINT IF EXISTS attendance_reports_status_check,
            ADD CONSTRAINT attendance_reports_status_check
            CHECK (status IN ('attended', 'no_show', 'late_cancel', 'excused', 'cancelled_early'))
        ");
    }

    /**
     * Reverse the migrations.
     *
     * Reverts the constraints to their previous values. Any rows with
     * 'cancelled_early' will need to be handled before rollback.
     */
    public function down(): void
    {
        DB::statement("
            ALTER TABLE game_participants
            DROP CONSTRAINT IF EXISTS game_participants_attendance_status_check,
            ADD CONSTRAINT game_participants_attendance_status_check
            CHECK (attendance_status IN ('attended', 'no_show', 'late_cancel', 'excused'))
        ");

        DB::statement("
            ALTER TABLE attendance_reports
            DROP CONSTRAINT IF EXISTS attendance_reports_status_check,
            ADD CONSTRAINT attendance_reports_status_check
            CHECK (status IN ('attended', 'no_show', 'late_cancel', 'excused'))
        ");
    }
};
