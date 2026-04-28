<?php

use App\Enums\AttendanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Attendance reports table — for grief tracking and dispute resolution
        Schema::create('attendance_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('game_id');
            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reported_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', AttendanceStatus::values());
            $table->float('weight_applied')->default(1.0);
            $table->boolean('is_corroborated')->default(false);
            $table->boolean('quarantined')->default(false);
            $table->timestamps();

            $table->index(['game_id', 'reported_id']);
            $table->index(['reporter_id', 'created_at']);
        });

        // Add reporting columns to game_participants for attendance tracking
        Schema::table('game_participants', function (Blueprint $table) {
            $table->foreignId('attendance_reported_by')->nullable()->after('attendance_status')->constrained('users')->nullOnDelete();
            $table->timestamp('attendance_reported_at')->nullable()->after('attendance_reported_by');
            $table->float('attendance_weight')->nullable()->after('attendance_reported_at');
        });

        Log::info('Created attendance_reports table and added reporting columns to game_participants.');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropForeign(['attendance_reported_by']);
            $table->dropColumn(['attendance_reported_by', 'attendance_reported_at', 'attendance_weight']);
        });

        Schema::dropIfExists('attendance_reports');
    }
};
