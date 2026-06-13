<?php

use App\Enums\AttendanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->enum('attendance_status', AttendanceStatus::values())->nullable()->after('status');
        });

        Log::info('Added attendance_status column to game_participants.');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropColumn('attendance_status');
        });
    }
};
