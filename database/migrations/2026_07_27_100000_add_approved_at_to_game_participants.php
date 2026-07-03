<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an approved_at timestamp to game_participants.
 *
 * approved_at records when a participant transitioned INTO the Approved
 * status — the load-bearing field for capacity-demotion LIFO ordering. It is
 * distinct from created_at (row creation, often as Waitlisted/Invited) and
 * cannot be derived from waitlisted_at/confirmation_expires_at (cleared
 * inconsistently). $timestamps is false on the model so no updated_at exists.
 *
 * Game-only (Campaign is out of scope for the capacity-adjustment feature).
 *
 * Legacy Approved rows are backfilled to created_at — their relative ordering
 * does not matter going forward, only newly-stamped rows need precise ordering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('created_at');
        });

        // Backfill legacy Approved rows so they carry an approved_at value.
        // Ordering of legacy rows is irrelevant going forward — only newly-
        // stamped rows (post-migration) need precise approved_at ordering for
        // the LIFO demotion rule.
        DB::table('game_participants')
            ->where('status', 'approved')
            ->whereNull('approved_at')
            ->update(['approved_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
    }
};
