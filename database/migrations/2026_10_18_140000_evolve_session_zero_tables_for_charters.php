<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign-charter foundation (M070, docs/spec/campaign-charter-concept.md §3):
 * a charter is a session_zero_surveys row of kind='charter' owned by a
 * campaign, with immutable version snapshots in a new table.
 *
 *  - session_zero_survey_versions — append-only content snapshots, one row
 *    per published version. Like badge_events, rows are immutable history:
 *    no update path is ever written, and S02 signing pins confirmations to
 *    version rows (which is why this table exists from day one).
 *    unique(survey, version_number) is the integrity floor. No index on
 *    published_at: versions are read via their survey, never by date.
 *  - session_zero_surveys evolution — kind discriminates legacy surveys
 *    ('survey', behavior untouched) from charters ('charter'); charters
 *    carry campaign_id, and ownership derives from campaign->owner_id, so
 *    gm_profile_id becomes nullable (many campaign owners have no GMProfile
 *    row). current_version points at the published snapshot; status gains
 *    'draft' for the authoring lifecycle.
 *  - Partial unique index session_zero_surveys_charter_campaign_unique —
 *    at most ONE charter per campaign at the database level. The
 *    application-level check narrows the race, but the index is the
 *    unconditional backstop (pattern:
 *    2026_10_06_120000_add_active_registration_unique_index.php).
 *  - Composite (kind, status) index for charter listings (S04 workspace).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_zero_surveys', function (Blueprint $table): void {
            // 'survey' = legacy session-zero survey; 'charter' = campaign
            // charter. CHECK constraint added below via raw DDL.
            $table->string('kind')->default('survey');
            // Charters attach to a campaign; legacy surveys keep game_id
            // and leave this null.
            $table->foreignUuid('campaign_id')->nullable()->constrained('campaigns')->cascadeOnDelete();
            // Published snapshot pointer; null while the charter is a draft.
            $table->unsignedInteger('current_version')->nullable();
            // Charter ownership derives from campaign->owner_id; legacy
            // surveys keep their GMProfile anchor.
            $table->foreignUuid('gm_profile_id')->nullable()->change();
            // Charter listings (S04): "charters with status X".
            $table->index(['kind', 'status']);
        });

        // CHECK constraints via raw DDL (codebase pattern:
        // 2026_08_19_100000_add_custom_to_campaigns_recurrence_check.php).
        DB::statement(
            'ALTER TABLE session_zero_surveys ADD CONSTRAINT session_zero_surveys_kind_check'
            ." CHECK (kind = ANY (ARRAY['survey', 'charter']::varchar[]))"
        );
        // Legacy rows only ever hold 'active'/'archived' (verified against
        // the codebase before this migration), so widening with 'draft' is
        // safe with no backfill.
        DB::statement(
            'ALTER TABLE session_zero_surveys ADD CONSTRAINT session_zero_surveys_status_check'
            ." CHECK (status = ANY (ARRAY['draft', 'active', 'archived']::varchar[]))"
        );

        // Raw DDL: Laravel's Blueprint does not compile partial index
        // predicates (see 2026_10_06_120000 for the rationale). At most one
        // kind='charter' row per campaign — the race backstop for concurrent
        // charter starts. Legacy surveys are exempt (null campaign_id).
        DB::statement(
            'CREATE UNIQUE INDEX session_zero_surveys_charter_campaign_unique'
            .' ON session_zero_surveys (campaign_id)'
            ." WHERE kind = 'charter'"
        );

        Schema::create('session_zero_survey_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_zero_survey_id')->constrained('session_zero_surveys')->cascadeOnDelete();
            // 1-based, unique per survey.
            $table->unsignedInteger('version_number');
            // Full charter content snapshot (CharterContent schema v1 in
            // T02); jsonb so S03 can diff and views can key into it.
            $table->jsonb('content');
            // Bumped only on a breaking content reshaping; tolerant
            // rendering keeps old snapshots readable (concept §6).
            $table->unsignedInteger('content_schema')->default(1);
            // GM-facing amendment note (S03); null for v1 publishes.
            $table->text('changelog')->nullable();
            // Material edits reset participant agreement (S02/S03).
            $table->boolean('is_material')->default(false);
            $table->timestamp('published_at');
            // Append-only audit trail (like badge_events): no code path
            // ever updates a version row.
            $table->timestamps();

            $table->unique(['session_zero_survey_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_zero_survey_versions');

        // DROP INDEX (not ALTER TABLE DROP CONSTRAINT): standalone unique
        // index, not a table constraint (cf. 2026_10_06_120000).
        DB::statement('DROP INDEX IF EXISTS session_zero_surveys_charter_campaign_unique');

        DB::statement('ALTER TABLE session_zero_surveys DROP CONSTRAINT IF EXISTS session_zero_surveys_kind_check');
        DB::statement('ALTER TABLE session_zero_surveys DROP CONSTRAINT IF EXISTS session_zero_surveys_status_check');

        // Charter rows must go BEFORE the kind column drop and the NOT
        // NULL restore: their gm_profile_id is null by design (ownership
        // derives from the campaign), dropIfExists above only removed
        // the VERSION rows, and nothing else in this down() touches the
        // survey rows themselves — without this delete the SET NOT NULL
        // below fails mid-rollback ("column contains null values") AFTER
        // kind/campaign_id/current_version were already dropped, leaving
        // the migration half-applied. Versioned confirmations were
        // already deleted by 2026_10_19's down() (rollback order runs it
        // first). Destructive by design: charter data is
        // forward-ledger-only; the point is a completable, consistent
        // rollback, never charter preservation.
        DB::table('session_zero_surveys')->where('kind', 'charter')->delete();

        Schema::table('session_zero_surveys', function (Blueprint $table): void {
            $table->dropIndex(['kind', 'status']);
            $table->dropColumn(['kind', 'campaign_id', 'current_version']);
            // Restore the legacy NOT NULL — valid now that every
            // surviving row is a legacy survey with its GMProfile anchor.
            $table->foreignUuid('gm_profile_id')->nullable(false)->change();
        });
    }
};
