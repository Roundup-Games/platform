<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a promoted_manually boolean flag to game_participants.
 *
 * promoted_manually distinguishes host-intentional overrides (capacity
 * exceeded on purpose) from normal confirmations. The capacity-demotion
 * LIFO rule EXEMPTS manually-promoted players — the host explicitly decided
 * to keep them. Set to true ONLY in WaitlistService::manuallyPromote().
 *
 * Game-only (Campaign is out of scope for the capacity-adjustment feature).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->boolean('promoted_manually')->default(false)->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropColumn('promoted_manually');
        });
    }
};
