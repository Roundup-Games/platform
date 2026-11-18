<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monotonic SEQUENCE ledger for iTIP calendar payloads (D287, M081/S07).
 *
 * One row per ICS UID (game-{id}@roundup.games, campaign-{id}-session-{gameId}
 *
 * @roundup.games, event-{id}@roundup.games). IcsBuilder::nextSequence is the
 * single write path: an INSERT ... ON CONFLICT DO NOTHING establishes the
 * row at sequence 0, every later build bumps it — so every METHOD:REQUEST
 * update and METHOD:CANCEL for a UID carries a strictly higher SEQUENCE than
 * the one before (required by MS-STANXICAL for CANCEL processing; see the
 * IcsBuilder docblock for the per-recipient monotonicity rationale).
 *
 * RESTORED POST-SQUASH (2026-11-17): this file was authored in a worktree
 * during M081/S07 but never committed — only its result ever reached git,
 * folded into database/schema/pgsql-schema.sql by the 2026-11-16 re-squash
 * (commit 46336760) as ledger row 310. The dump is only ever loaded on a
 * FRESH install (empty migrations table), so every existing environment —
 * production included — would lack the table and every calendar-attaching
 * mail (invitations, updates, cancellations) would fail its queued send
 * with "relation does not exist". Restored under its ORIGINAL name so the
 * baseline ledger and the migrations directory stay in lockstep: fresh
 * installs skip it (ledger row + table already present via the dump),
 * existing environments run it once. The hasTable guard covers any
 * database that holds the table without the ledger row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('calendar_lifecycle_counters')) {
            return;
        }

        Schema::create('calendar_lifecycle_counters', function (Blueprint $table) {
            // Counter-table exemption from D170's uuid policy (same as
            // notification_engagement_counters): the row identity is the
            // natural key (uid), not a platform-owned entity id.
            $table->id();
            $table->string('entity_type', 16);
            $table->uuid('entity_id');
            $table->string('uid')->unique();
            $table->integer('sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_lifecycle_counters');
    }
};
