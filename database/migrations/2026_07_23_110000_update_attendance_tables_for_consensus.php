<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // attendance_reports: add reason, drop dispute columns
        Schema::table('attendance_reports', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('quarantined');
        });

        Schema::table('attendance_reports', function (Blueprint $table) {
            $table->dropColumn([
                'dispute_reason',
                'disputed_at',
                'dispute_resolution',
                'dispute_resolved_at',
            ]);
        });

        // game_participants: drop attendance_dispute_reason, add attendance_disputed_at
        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropColumn('attendance_dispute_reason');
        });

        Schema::table('game_participants', function (Blueprint $table) {
            $table->timestamp('attendance_disputed_at')->nullable()->after('attendance_weight');
        });

        Log::info('Updated attendance tables for consensus: added reason to reports, dropped dispute columns, added disputed_at to participants.');
    }

    public function down(): void
    {
        // game_participants: reverse changes
        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropColumn('attendance_disputed_at');
        });

        Schema::table('game_participants', function (Blueprint $table) {
            $table->text('attendance_dispute_reason')->nullable()->after('attendance_weight');
        });

        // attendance_reports: reverse changes
        Schema::table('attendance_reports', function (Blueprint $table) {
            $table->string('dispute_reason')->nullable()->after('quarantined');
            $table->timestamp('disputed_at')->nullable()->after('dispute_reason');
            $table->string('dispute_resolution')->nullable()->after('disputed_at');
            $table->timestamp('dispute_resolved_at')->nullable()->after('dispute_resolution');
        });

        Schema::table('attendance_reports', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
