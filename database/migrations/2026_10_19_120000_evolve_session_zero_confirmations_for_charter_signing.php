<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign-charter signing storage (M070, docs/spec/campaign-charter-concept.md §3):
 * session_zero_confirmations grows from a flat (survey, user) confirmation
 * into a version-pinned signature row.
 *
 *  - session_zero_survey_version_id — the immutable version snapshot a
 *    signature pins to (null = legacy survey confirmation, behavior
 *    untouched). cascadeOnDelete: dropping a version (via the survey
 *    cascade) takes its signatures with it, like S01's version snapshots.
 *  - attestations — the read attestation plus the four §8 agreement ticks
 *    (verbatim S01 charter.confirm_* copy), stored as submitted.
 *  - private_answers — §2 two-way private boundary answers; GM-only, never
 *    rendered to other participants.
 *  - status — 'signed' | 'pending_reagreement' (material amendments reset
 *    participant agreement, S03).
 *  - role — 'participant' | 'gm': the GM countersignature lives in the same
 *    table; role is the discriminator.
 *  - Plain index on session_zero_survey_version_id for roster queries (S02:
 *    "who signed this version").
 *  - The legacy unique index on (session_zero_survey_id, user_id) is
 *    NARROWED to rows with a null version pin: versioned signing needs
 *    multiple rows per (survey, user) — one per version (material
 *    amendments reset agreement and participants re-sign the new
 *    snapshot, S03) — and the GM countersignature may share (survey, user)
 *    with a participant signature by the same owner. Legacy rows keep the
 *    exact old semantics: at most one confirmation per (survey, user).
 *  - Partial unique index session_zero_confirmations_version_user_role_unique
 *    on (session_zero_survey_version_id, user_id, role) WHERE the version id
 *    IS NOT NULL — the unconditional race backstop for concurrent
 *    double-sign attempts. Legacy rows (null version) are exempt, governed
 *    solely by the narrowed legacy index (pattern:
 *    2026_10_18_140000_evolve_session_zero_tables_for_charters.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_zero_confirmations', function (Blueprint $table): void {
            // Signature pin; null = legacy survey confirmation.
            $table->foreignUuid('session_zero_survey_version_id')
                ->nullable()
                ->constrained('session_zero_survey_versions')
                ->cascadeOnDelete();
            // Read attestation + §8 agreement ticks, as submitted.
            $table->jsonb('attestations')->nullable();
            // §2 private boundary answers (GM-only visibility, S02).
            $table->jsonb('private_answers')->nullable();
            // 'signed' | 'pending_reagreement' — CHECK added below via raw DDL.
            $table->string('status')->default('signed');
            // 'participant' | 'gm' — CHECK added below via raw DDL.
            $table->string('role')->default('participant');
            // Roster queries: all signatures for one version.
            $table->index('session_zero_survey_version_id');
        });

        // CHECK constraints via raw DDL (codebase pattern:
        // 2026_10_18_140000_evolve_session_zero_tables_for_charters.php).
        DB::statement(
            'ALTER TABLE session_zero_confirmations ADD CONSTRAINT session_zero_confirmations_status_check'
            ." CHECK (status = ANY (ARRAY['signed', 'pending_reagreement']::varchar[]))"
        );
        DB::statement(
            'ALTER TABLE session_zero_confirmations ADD CONSTRAINT session_zero_confirmations_role_check'
            ." CHECK (role = ANY (ARRAY['participant', 'gm']::varchar[]))"
        );

        // Narrow the legacy unique index to rows without a version pin.
        // The full (survey, user) index predates versioned signing and would
        // reject legitimate S02/S03 rows: a participant re-signing a new
        // version after a material amendment, and a GM countersignature by
        // an owner who also signed as participant. Legacy rows (null
        // version) keep the exact old uniqueness. The name is the Postgres
        // 63-char truncation of the original index name — reused verbatim
        // so a rollback restores the baseline schema byte-identically.
        DB::statement(
            'DROP INDEX IF EXISTS session_zero_confirmations_session_zero_survey_id_user_id_uniqu'
        );
        DB::statement(
            'CREATE UNIQUE INDEX session_zero_confirmations_session_zero_survey_id_user_id_uniqu'
            .' ON session_zero_confirmations (session_zero_survey_id, user_id)'
            .' WHERE session_zero_survey_version_id IS NULL'
        );

        // Raw DDL: Laravel's Blueprint does not compile partial index
        // predicates. At most one signature per (version, user, role);
        // legacy rows (null version) are exempt.
        DB::statement(
            'CREATE UNIQUE INDEX session_zero_confirmations_version_user_role_unique'
            .' ON session_zero_confirmations (session_zero_survey_version_id, user_id, role)'
            .' WHERE session_zero_survey_version_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        // DROP INDEX (not ALTER TABLE DROP CONSTRAINT): standalone unique
        // index, not a table constraint (cf. 2026_10_18_140000).
        DB::statement('DROP INDEX IF EXISTS session_zero_confirmations_version_user_role_unique');

        // Charter signatures cannot survive rollback: without the version
        // pin they would collapse into duplicate (survey, user) rows and
        // break the legacy unique index restored below. The narrowed
        // legacy index must also go before its predicate column does.
        DB::delete('DELETE FROM session_zero_confirmations WHERE session_zero_survey_version_id IS NOT NULL');
        DB::statement('DROP INDEX IF EXISTS session_zero_confirmations_session_zero_survey_id_user_id_uniqu');

        DB::statement('ALTER TABLE session_zero_confirmations DROP CONSTRAINT IF EXISTS session_zero_confirmations_status_check');
        DB::statement('ALTER TABLE session_zero_confirmations DROP CONSTRAINT IF EXISTS session_zero_confirmations_role_check');

        Schema::table('session_zero_confirmations', function (Blueprint $table): void {
            $table->dropIndex(['session_zero_survey_version_id']);
            $table->dropColumn([
                'session_zero_survey_version_id',
                'attestations',
                'private_answers',
                'status',
                'role',
            ]);
        });

        // Restore the legacy full (survey, user) unique index under its
        // original (63-char truncated) name — the baseline schema shape.
        DB::statement(
            'CREATE UNIQUE INDEX session_zero_confirmations_session_zero_survey_id_user_id_uniqu'
            .' ON session_zero_confirmations (session_zero_survey_id, user_id)'
        );
    }
};
