<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->timestamp('confirmation_expires_at')->nullable()->after('attendance_status');
            $table->timestamp('waitlisted_at')->nullable()->after('confirmation_expires_at');
        });

        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->timestamp('benched_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->dropColumn('benched_at');
        });

        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropColumn(['confirmation_expires_at', 'waitlisted_at']);
        });
    }
};
