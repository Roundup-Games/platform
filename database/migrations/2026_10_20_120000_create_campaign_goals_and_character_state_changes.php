<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign journal data foundations (M071/S02,
 * docs/spec/campaign-journal-concept.md §2): the system-agnostic goals
 * and character state changes behind the Journal tab's goal board and
 * per-character state timelines.
 *
 * Decisions locked by the migration's shape:
 *
 *  - status_changed_at_game_id is nullable and nullOnDelete (never
 *    cascade): the stamp is metadata about WHEN a goal's status last
 *    moved — a deleted session must not delete the goal. S04
 *    "Previously On" diffs these stamps against sessions
 *    (status_changed_at_game_id lands NOW for that forward-compat).
 *  - character_state_changes.participant_id is nullable and
 *    nullOnDelete (journal author_participant_id precedent): the
 *    campaign's state history survives participant removal.
 *  - character_state_changes.game_id is nullable (interlude state
 *    notes, mirroring journal entries) yet cascades on delete — a
 *    deleted session takes its state changes with it, matching the
 *    recap-on-delete behavior of campaign_journal_entries.
 *  - status / change_type are plain strings with CHECK constraints
 *    added below via raw DDL (Blueprint cannot compile them; codebase
 *    pattern: 2026_10_20_110000_create_campaign_journal_entries_table
 *    and 2026_10_18_140000).
 *  - No numbers, no sheets, no system coupling (spec §2): delta and
 *    description are free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_goals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            // Stored as plain strings; the model casts to the enum.
            // CHECK constraint added below via raw DDL.
            $table->string('status');
            // Metadata stamp for S04 Previously On diffs: nullable and
            // nullOnDelete — a deleted session must not delete the goal.
            $table->foreignUuid('status_changed_at_game_id')->nullable()->constrained('games')->nullOnDelete();
            $table->integer('sort')->default(0);
            $table->timestamps();

            // S02 goal board: grouped by status, ordered by sort.
            $table->index(['campaign_id', 'sort']);
        });

        Schema::create('character_state_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            // Nullable + nullOnDelete (journal author_participant_id
            // precedent): the campaign's state history survives
            // participant removal.
            $table->foreignUuid('participant_id')->nullable()->constrained('campaign_participants')->nullOnDelete();
            // Nullable: interlude state notes belong to the campaign but
            // no session (mirroring journal entries). Cascade: a deleted
            // session takes its state changes with it, matching
            // recap-on-delete.
            $table->foreignUuid('game_id')->nullable()->constrained('games')->cascadeOnDelete();
            // Stored as plain strings; the model casts to the enum.
            // CHECK constraint added below via raw DDL.
            $table->string('change_type');
            $table->text('delta');
            $table->timestamps();

            // S02 per-character state timelines.
            $table->index(['participant_id', 'created_at']);
            // S04 Previously On scans per campaign.
            $table->index(['campaign_id', 'created_at']);
        });

        // CHECK constraints via raw DDL (codebase pattern:
        // 2026_10_20_110000_create_campaign_journal_entries_table).
        DB::statement(
            'ALTER TABLE campaign_goals ADD CONSTRAINT campaign_goals_status_check'
            ." CHECK (status = ANY (ARRAY['planned', 'active', 'achieved', 'failed', 'abandoned']::varchar[]))"
        );
        DB::statement(
            'ALTER TABLE character_state_changes ADD CONSTRAINT character_state_changes_change_type_check'
            ." CHECK (change_type = ANY (ARRAY['progression', 'item', 'condition', 'note']::varchar[]))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('character_state_changes');
        Schema::dropIfExists('campaign_goals');
    }
};
