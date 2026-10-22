<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Previously On drafts (M071/S04, docs/spec/campaign-journal-concept.md §3,
 * D221): the draft-first home for the auto-composed pre-session digest a
 * member GM reviews and publishes with one click.
 *
 * This is a DEDICATED entity, never a GameBulletin draft row: bulletin
 * creation is the single fan-out hook (GameBulletinObserver::created fans
 * out to the whole D185 channel matrix — board, in-app/mail/push/Discord
 * per prefs, Discord teasers), so a draft bulletin would deliver on
 * compose. A separate table keeps the pre-publish state invisible to every
 * channel until the GM clicks publish.
 *
 * Decisions locked by the migration's shape:
 *
 *  - unique(game_id): one draft per target session — re-compose (publish
 *    trigger + 24h reminder refresh) is an UPDATE, never a duplicate row.
 *  - game_id cascades: a canceled/deleted session kills its draft.
 *  - published_at + published_bulletin_id: the publish stamp and the link
 *    to the delivered bulletin (nullOnDelete — deleting the bulletin never
 *    deletes the draft's audit trail, and published drafts are immutable
 *    either way: the composer refuses to touch them).
 *  - body is text and stores the composed PLAIN TEXT verbatim (bulletin
 *    board renders escaped plain text; the Discord teaser neutralizes
 *    masked-link markdown), so what the GM previewed is exactly what is
 *    published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('previously_on_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            // One draft per target session (unique below); a canceled or
            // deleted session takes its draft with it.
            $table->foreignUuid('game_id')->constrained('games')->cascadeOnDelete();
            $table->text('body');
            // Publish stamp (nullable = still a draft). Plain timestamp per
            // the platform-wide convention — the schema has no tz columns
            // (charter published_at precedent, 2026_10_18_140000).
            $table->timestamp('published_at')->nullable();
            // The bulletin the publish created. nullOnDelete: deleting the
            // bulletin keeps the draft row and its published_at audit trail.
            $table->foreignUuid('published_bulletin_id')->nullable()->constrained('game_bulletins')->nullOnDelete();
            $table->timestamps();

            // One draft per target session: re-compose = update (D221).
            $table->unique('game_id');
        });
    }

    public function down(): void
    {
        // GM-authored draft content: one-way once live — refuse to
        // destroy rows, drop when empty (published rows are immutable
        // projections; drafts are the only copy).
        $rows = DB::table('previously_on_drafts')->count();

        if ($rows > 0) {
            throw new RuntimeException(
                "previously_on_drafts holds {$rows} GM-authored draft row(s) — refusing to drop. ".
                'Restore from a backup or continue on a disposable environment (migrate:fresh).',
            );
        }

        Schema::dropIfExists('previously_on_drafts');
    }
};
