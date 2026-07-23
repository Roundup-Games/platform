<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-game custom session reminders (M057/S05 — decision D125 hybrid model).
 *
 * The two built-in reminders (24h / 1h) stay as-is on the games table; this
 * table holds organizer-authored *extra* reminders (up to 5 per game, enforced
 * in the organizer UI — T06). Each row is an absolute `send_at` timestamp the
 * scheduler can compare directly, plus an optional `message` for custom copy.
 *
 * `sent_at` is the dedup marker: the SendSessionReminders sweep (T06) selects
 * rows WHERE `sent_at IS NULL AND send_at <= now()`, dispatches each through
 * the existing SessionReminder notification (reusing NotificationCategory::
 * SessionReminder so preference filtering / block-lists / structured logging
 * apply unchanged per MEM855), and sets `sent_at` in a finally (mirroring the
 * built-in windows' dedup discipline).
 *
 * `offset_minutes` is stored only so the organizer UI can re-render "2 hours
 * before" after a `date_time` edit recomputes `send_at` — it is NOT consulted
 * by the scheduler. Nullable = the organizer picked an absolute time directly.
 *
 * Backward-compatible: no existing data references this table; nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The roundup Game this reminder belongs to.
            $table->uuid('game_id');
            $table->foreign('game_id')
                ->references('id')
                ->on('games')
                ->cascadeOnDelete();

            // Absolute time to send (scheduler compares this directly).
            $table->timestamp('send_at');

            // Organizer custom copy; NULL = use default SessionReminder lang-key copy.
            $table->text('message')->nullable();

            // UX round-trip only ("2 hours before"); the scheduler ignores it.
            $table->integer('offset_minutes')->nullable();

            // Dedup marker, set after dispatch (NULL = not yet sent).
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // The scheduler sweep queries `WHERE sent_at IS NULL AND send_at <= now()`.
            // A partial-style composite index covers that hot path.
            $table->index(['sent_at', 'send_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_reminders');
    }
};
