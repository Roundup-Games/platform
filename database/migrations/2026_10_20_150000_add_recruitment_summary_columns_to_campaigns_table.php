<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment summary columns (M071/S05, docs/spec/
 * campaign-journal-concept.md §4, R082): the durable home for the
 * spoiler-safe "Campaign so far" block a GM publishes to the public
 * campaign page.
 *
 * Three SEPARATE nullable columns, deliberately NOT markers embedded in
 * the translatable description: removal is a column clear (fully
 * reversible), staleness is simple arithmetic (completed sessions minus
 * the stamp), and the CampaignsPage edit modal — which edits the
 * description — can never clobber or fragment the block.
 *
 *  - recruitment_summary (text): the GM-authored plain-text block. NOT
 *    translatable by design: one locale-independent block per campaign —
 *    the GM authors it in the language prospective players should read;
 *    per-locale copies would drift and double the spoiler-checklist
 *    burden (see the Campaign model docblock).
 *  - recruitment_summary_completed_sessions (integer): the completed-
 *    sessions count stamped at apply time — the staleness baseline.
 *  - recruitment_summary_refreshed_at (timestamp): the apply stamp for
 *    the "Last refreshed after session N" line. Plain timestamp per the
 *    platform-wide convention (the schema has no tz columns;
 *    campaigns.share_token_expires_at precedent).
 *
 * Null = no block published. No CHECK constraints (plain columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->text('recruitment_summary')->nullable();
            $table->integer('recruitment_summary_completed_sessions')->nullable();
            $table->timestamp('recruitment_summary_refreshed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'recruitment_summary',
                'recruitment_summary_completed_sessions',
                'recruitment_summary_refreshed_at',
            ]);
        });
    }
};
