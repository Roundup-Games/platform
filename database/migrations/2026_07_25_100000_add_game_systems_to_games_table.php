<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Multi-system set (e.g. [uuid, uuid, uuid]) for games spanning
            // multiple game systems. Nullable because legacy single-system
            // games have null and continue to rely on game_system_id below.
            // game_system_id is intentionally kept as the cached anchor.
            $table->json('game_systems')->nullable()->after('game_system_id');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('game_systems');
        });
    }
};
