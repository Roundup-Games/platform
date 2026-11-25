<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an approved_at timestamp to campaign_participants.
 *
 * approved_at records when a participant transitioned INTO the Approved
 * status — the load-bearing field for the campaign agreement lock (D314/
 * D317): a campaign's Tier-1 fields lock at the first confirmed non-owner
 * member ("has EVER had one"), and the single predicate reads the earliest
 * approved_at among participants whose user_id differs from the owner. It is
 * distinct from created_at (row creation, often as Pending/Invited/Waitlisted)
 * and survives departure — depart/remove never clear it. $timestamps is false
 * on the model so no updated_at exists.
 *
 * Mirrors 7d280544 (game_participants) with ONE deliberate difference in the
 * backfill. Legacy Approved rows are backfilled from created_at, but
 * campaign_participants.created_at was added nullable WITHOUT a backfill
 * (e06ed724, 2026-07-13) — every row older than that carries a NULL
 * created_at, unlike game_participants (whose created_at migration backfilled
 * the pre-existing rows). A bare created_at copy would therefore leave the
 * oldest Approved rows unstamped and their campaigns unlocked after deploy,
 * so the copy falls back to the campaign's own created_at and, as a last
 * resort, to now(). Every legacy Approved row ends up stamped; only the
 * precision of the displayed "locked since" timestamp varies.
 *
 * No index: approved_at is never filtered on alone — the lock predicate
 * filters by the indexed campaign_id relation and takes the earliest value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable();
        });

        // Backfill legacy Approved rows so they carry an approved_at value.
        // created_at first; it is NULL for rows predating that column
        // (e06ed724), so fall back to the campaign's created_at, then to
        // now() — the lock must never fail open on an unstamped legacy row.
        DB::table('campaign_participants')
            ->where('status', 'approved')
            ->whereNull('approved_at')
            ->update([
                'approved_at' => DB::raw(
                    'coalesce('
                    .'campaign_participants.created_at, '
                    .'(select campaigns.created_at from campaigns where campaigns.id = campaign_participants.campaign_id), '
                    .'now()'
                    .')',
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
    }
};
