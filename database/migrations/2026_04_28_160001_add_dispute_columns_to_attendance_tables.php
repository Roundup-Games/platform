<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add dispute columns to attendance_reports
        Schema::table('attendance_reports', function (Blueprint $table) {
            $table->string('dispute_reason')->nullable()->after('quarantined');
            $table->timestamp('disputed_at')->nullable()->after('dispute_reason');
            $table->string('dispute_resolution')->nullable()->after('disputed_at');
            $table->timestamp('dispute_resolved_at')->nullable()->after('dispute_resolution');
        });

        // Add dispute reason to game_participants
        Schema::table('game_participants', function (Blueprint $table) {
            $table->string('attendance_dispute_reason')->nullable()->after('attendance_weight');
        });

        Log::info('Added dispute columns to attendance_reports and game_participants.');
    }

    public function down(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropColumn('attendance_dispute_reason');
        });

        Schema::table('attendance_reports', function (Blueprint $table) {
            $table->dropColumn(['dispute_reason', 'disputed_at', 'dispute_resolution', 'dispute_resolved_at']);
        });
    }
};
