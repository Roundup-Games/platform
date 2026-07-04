<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Create the canonical campaign ↔ game_system many-to-many pivot (S06/T01).
 *
 * Second half of the S06 pivot migration. campaign_game_system becomes the
 * recurring DEFAULT offering (the template); game_game_system is each
 * spawned session's own offering (independently editable — copy-on-write
 * semantics land in T05). This migration is strictly additive: it creates the
 * pivot and backfills it from the existing campaigns.game_system_id anchor,
 * but does NOT drop that column (retired in T06).
 *
 * Campaigns are single-system only (no game_systems JSON array), so the
 * backfill is a single INSERT mirroring step (1) of the games pivot.
 * ON CONFLICT DO NOTHING keeps the migration safely re-runnable.
 *
 * This migration also emits the one-time structured backfill log
 * `game_system.pivot_backfill` summarizing the per-table row counts across
 * both new pivots. It runs second (110000 > 100000) so both pivots are
 * populated when it fires and the summary reflects the final state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_game_system', function (Blueprint $table) {
            $table->uuid('campaign_id');
            $table->uuid('game_system_id');

            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('game_system_id')->references('id')->on('game_systems')->cascadeOnDelete();
            $table->primary(['campaign_id', 'game_system_id']);

            // Standalone index for the reverse join (CampaignListing, whereHas
            // on the system side) that T03 introduces.
            $table->index('game_system_id');
        });

        // Backfill from the existing campaigns.game_system_id anchor.
        DB::statement(<<<'SQL'
            INSERT INTO campaign_game_system (campaign_id, game_system_id)
            SELECT id, game_system_id
            FROM campaigns
            WHERE game_system_id IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);

        // One-time structured backfill log. Both pivots exist at this point
        // (the games pivot migration at 100000 runs first), so the counts
        // reflect the final backfilled state. Wrapped so a logging failure
        // never blocks the schema work.
        try {
            $gameRows = (int) DB::table('game_game_system')->count();
            $campaignRows = (int) DB::table('campaign_game_system')->count();

            Log::info('game_system.pivot_backfill', [
                'game_game_system' => $gameRows,
                'campaign_game_system' => $campaignRows,
            ]);
        } catch (Throwable $e) {
            // Logging is best-effort; never abort a successful migration over it.
        }
    }

    public function down(): void
    {
        // The pivot is derived data; dropping it is the correct rollback.
        // The legacy campaigns.game_system_id column remains intact.
        Schema::dropIfExists('campaign_game_system');
    }
};
