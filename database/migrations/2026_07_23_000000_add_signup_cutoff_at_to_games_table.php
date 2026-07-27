<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional signup cutoff to games (M057/S05 — "close RSVPs before or
 * after event start").
 *
 * `signup_cutoff_at` is a nullable absolute timestamp: when set and in the
 * past, NEW signups are blocked at all three participant-write entry points
 * (web apply, share-link join, Discord button). A NULL value means "no cutoff"
 * and preserves the current behavior, so this is backward-compatible — no
 * backfill is required and existing games continue to accept signups.
 *
 * The cutoff gates NEW signups only; waitlist auto-promotion on a capacity
 * increase is intentionally NOT gated (organizer can still grow the table).
 * The single predicate that centralizes this is `Game::signupHasClosed()`
 * (decision D124), so the three write paths call one method instead of each
 * re-implementing the past-cutoff check.
 *
 * Absolute timestamp (not an offset like "2h before") matches the roadmap
 * wording and lets the editor UI offer presets ("1h before", "at start")
 * that resolve to an absolute time the scheduler can compare directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->timestamp('signup_cutoff_at')
                ->nullable()
                ->after('reminder_24h_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn('signup_cutoff_at');
        });
    }
};
