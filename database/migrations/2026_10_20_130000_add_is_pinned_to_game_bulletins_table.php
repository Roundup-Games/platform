<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pinned-bulletin flag on game_bulletins (M071 S03, D219): the one-shot
 * Epilogue bulletin posted automatically on completion is pinned — exempt
 * from the Game::updated completion/cancel expiry sweep and sorted first
 * on the board (T02). No index: game_bulletins is a tiny per-game table
 * and the pinned lookups are already scoped by game_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_bulletins', function (Blueprint $table): void {
            $table->boolean('is_pinned')->default(false)->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('game_bulletins', function (Blueprint $table): void {
            $table->dropColumn(['is_pinned']);
        });
    }
};
