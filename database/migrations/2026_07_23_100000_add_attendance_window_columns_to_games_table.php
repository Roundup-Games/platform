<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->timestamp('attendance_window_opens_at')->nullable()->after('date_time');
            $table->timestamp('attendance_window_closes_at')->nullable()->after('attendance_window_opens_at');
            $table->timestamp('attendance_resolved_at')->nullable()->after('attendance_window_closes_at');
            $table->enum('attendance_resolution_method', ['early_consensus', 'timeout', 'manual'])->nullable()->after('attendance_resolved_at');

            // Index for scheduler: find games whose window has closed but aren't resolved yet
            $table->index(
                ['attendance_window_closes_at', 'attendance_resolved_at'],
                'games_unresolved_window_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex('games_unresolved_window_idx');
            $table->dropColumn([
                'attendance_window_opens_at',
                'attendance_window_closes_at',
                'attendance_resolved_at',
                'attendance_resolution_method',
            ]);
        });
    }
};
