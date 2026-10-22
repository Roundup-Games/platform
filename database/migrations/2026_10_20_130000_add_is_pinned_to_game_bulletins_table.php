<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pinned-bulletin flag on game_bulletins (M071 S03, D219): the one-shot
 * Epilogue bulletin posted automatically on completion is pinned — exempt
 * from the Game::updated completion/cancel expiry sweep and sorted first
 * on the board (T02).
 *
 * The partial unique index is the one-pinned-per-game invariant at the
 * database level: OneShotEpilogueService's exists()-check is check-then-
 * insert, so two concurrent completions of the same game (host click vs
 * AutoCompleteGames) could both pass it. The service converges a lost
 * race to null on the 23505 — the same shape as the charter signature
 * race backstops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_bulletins', function (Blueprint $table): void {
            $table->boolean('is_pinned')->default(false)->after('content');
        });

        DB::statement(
            'create unique index game_bulletins_one_pinned_per_game_unique'
            .' on game_bulletins (game_id) where is_pinned'
        );
    }

    public function down(): void
    {
        Schema::table('game_bulletins', function (Blueprint $table): void {
            $table->dropColumn(['is_pinned']);
        });
    }
};
