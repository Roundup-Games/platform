<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cull the legacy session-zero SURVEY kind (M070 follow-up, user decision
 * 2026-10-23: surveys never saw real usage and charters supersede them).
 *
 * Charters live on the same session_zero_surveys table and stay — after
 * this migration the table is charter-only:
 *
 *  - every kind != 'charter' row is deleted. Cascades take their
 *    confirmations and versions (FK cascadeOnDelete), so no orphans.
 *  - session_zero_surveys.game_id dies: only the retired survey flow and
 *    its Game relations used it; charters are campaign-bound.
 *  - session_zero_surveys.kind dies: with survey rows gone every writer
 *    writes charters (the CHECK constraint goes with the column), so the
 *    discriminator and every kind branch in code simplify away.
 *  - session_zero_surveys.confirmation_count dies: the survey RSVP
 *    counter — charter signing counts its own confirmation rows and this
 *    column was permanently 0 for charters (pinned by test).
 *
 * No data preservation (no real usage existed). down() restores the
 * COLUMNS but cannot resurrect deleted rows — that is accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Delete the legacy survey rows (cascades: confirmations, versions).
        $deleted = DB::table('session_zero_surveys')
            ->whereNot('kind', 'charter')
            ->delete();

        if ($deleted > 0) {
            logger()?->info('Legacy session-zero survey rows culled', ['deleted' => $deleted]);
        }

        // 2. Replace the one-charter-per-campaign partial index: its
        // predicate referenced kind, which is about to disappear. The
        // replacement keeps the same race backstop (charter rows always
        // carry campaign_id; Postgres unique indexes allow multiple
        // NULLs, so nothing else changes).
        DB::statement('DROP INDEX IF EXISTS session_zero_surveys_charter_campaign_unique');
        DB::statement(
            'CREATE UNIQUE INDEX session_zero_surveys_charter_campaign_unique'
            .' ON session_zero_surveys (campaign_id)'
        );

        // 3. Drop the survey-only columns.
        Schema::table('session_zero_surveys', function (Blueprint $table): void {
            $table->dropForeign(['game_id']);
            $table->dropColumn(['game_id', 'kind', 'confirmation_count']);
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS session_zero_surveys_charter_campaign_unique');
        DB::statement(
            'CREATE UNIQUE INDEX session_zero_surveys_charter_campaign_unique'
            .' ON session_zero_surveys (campaign_id)'
            ." WHERE kind = 'charter'"
        );

        Schema::table('session_zero_surveys', function (Blueprint $table): void {
            $table->foreignUuid('game_id')->nullable()->constrained('games')->nullOnDelete();
            $table->string('kind')->default('charter');
            $table->unsignedInteger('confirmation_count')->default(0);
        });

        // Rows deleted by up() are gone for good — documented and accepted.
    }
};
