<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->unsignedInteger('confirmation_attempts')->nullable()->after('waitlisted_at');
        });
    }

    public function down(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropColumn('confirmation_attempts');
        });
    }
};
