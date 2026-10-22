<?php

use App\Services\LegacyRecapJournalBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign journal foundation (M071, docs/spec/campaign-journal-concept.md §2).
 *
 * One append-only store for a campaign's narrative content — recaps,
 * in-character posts, GM prep notes — that every later M071 slice reads
 * from (S02 journal tab, S04 Previously On, S05 Campaign Compleat).
 *
 * Decisions locked by the migration's shape:
 *
 *  - D218: the journal keys off campaign_id only — every campaign type
 *    (ttrpg etc.) gets a journal; there is no per-type column.
 *  - D220: games.recap becomes a synced projection of the kind=recap
 *    entry written through CampaignJournalService. The projection sync
 *    lives in S01's service layer, not here; this table is the source
 *    of truth.
 *  - game_id is nullable (spec §2: interlude entries belong to the
 *    campaign but no session) yet cascades on delete — deleting a
 *    session removes its entries, matching today's recap-on-delete
 *    behavior.
 *  - author_participant_id is nullable and nullOnDelete (never cascade):
 *    removing a participant row must not delete the journal content the
 *    player authored; author_user_id keeps the durable attribution.
 *  - Partial unique index campaign_journal_entries_one_recap_per_session —
 *    at most ONE kind=recap entry per session at the database level. The
 *    application-level check narrows the race, but the index is the
 *    unconditional backstop for double-submits and concurrent writes
 *    (pattern: 2026_10_18_140000_evolve_session_zero_tables_for_charters.php).
 *  - Composite (campaign_id, created_at) index for the S02
 *    reverse-chronological timeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_journal_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            // Nullable: interlude entries attach to the campaign only
            // (spec §2). Cascade: a deleted session takes its entries with
            // it, matching today's recap-on-delete behavior.
            $table->foreignUuid('game_id')->nullable()->constrained('games')->cascadeOnDelete();
            // Durable attribution; survives participant removal.
            $table->foreignUuid('author_user_id')->constrained('users')->cascadeOnDelete();
            // Character link (nullable). nullOnDelete, never cascade:
            // removing a participant row must not delete authored entries.
            $table->foreignUuid('author_participant_id')->nullable()->constrained('campaign_participants')->nullOnDelete();
            // Stored as plain strings; the model casts to the enums.
            // CHECK constraints added below via raw DDL.
            $table->string('kind');
            $table->text('body');
            $table->string('visibility');
            // Free text (spec §2): "9. Flockentag, 1372 Dal" — no parsing,
            // no system coupling.
            $table->string('in_game_date')->nullable();
            $table->timestamps();

            // S02 timeline: reverse-chronological per campaign.
            $table->index(['campaign_id', 'created_at']);
            $table->index('game_id');
        });

        // CHECK constraints via raw DDL (codebase pattern:
        // 2026_10_18_140000_evolve_session_zero_tables_for_charters.php).
        DB::statement(
            'ALTER TABLE campaign_journal_entries ADD CONSTRAINT campaign_journal_entries_kind_check'
            ." CHECK (kind = ANY (ARRAY['recap', 'in_character', 'prep_note']::varchar[]))"
        );
        DB::statement(
            'ALTER TABLE campaign_journal_entries ADD CONSTRAINT campaign_journal_entries_visibility_check'
            ." CHECK (visibility = ANY (ARRAY['players', 'gms']::varchar[]))"
        );

        // Raw DDL: Laravel's Blueprint does not compile partial index
        // predicates. At most one kind=recap row per session — the race
        // and double-submit backstop for the S01 write-recap path.
        DB::statement(
            'CREATE UNIQUE INDEX campaign_journal_entries_one_recap_per_session'
            .' ON campaign_journal_entries (game_id)'
            ." WHERE kind = 'recap' AND game_id IS NOT NULL"
        );

        // D220: promote existing games.recap values on campaign sessions to
        // their kind=recap journal entries — the table above is now the
        // source of truth. The logic lives in
        // {@see LegacyRecapJournalBackfill} so it is unit-tested
        // independently of the migrator (GmailCanonicalInviteBackfill
        // pattern). One-shot games keep their column-only recap until the
        // S03 decision, and the games.recap column itself stays untouched
        // as the synced projection.
        app(LegacyRecapJournalBackfill::class)->run();
    }

    public function down(): void
    {
        // One-way by design once the journal is live: the table holds
        // user-authored content (in-character posts, GM prep notes,
        // post-go-live recaps) that a re-run of up() can NOT re-create —
        // only the go-live backfill rows regenerate from games.recap.
        // Refuse loudly instead of silently destroying them; an empty
        // table (fresh install rollback) drops normally.
        $entries = DB::table('campaign_journal_entries')->count();

        if ($entries > 0) {
            throw new RuntimeException(
                "campaign_journal_entries holds {$entries} row(s) of user-authored journal content — ".
                'refusing to drop. Restore from a backup or continue on a disposable environment (migrate:fresh).',
            );
        }

        Schema::dropIfExists('campaign_journal_entries');
    }
};
