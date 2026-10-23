<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the games.recap projection column (D220 completed, 2026-10-23).
 *
 * The kind=recap campaign_journal_entries row is the one and only recap
 * record; every reader resolves through Game::recapEntry. The column was
 * the legacy write target (retired with RecapService) and a synced
 * projection for one release window — the backfill that promoted legacy
 * values into journal entries has run everywhere it can.
 *
 * One-shot caveat: one-shot games never receive journal entries (their
 * surface is the Epilogue bulletin), so any lingering one-shot recap
 * column text is intentionally dropped with the column — one-shot
 * recaps have no replacement surface by design (D219).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('games', 'recap')) {
            Schema::table('games', function (Blueprint $table): void {
                $table->dropColumn('recap');
            });
        }
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->text('recap')->nullable();
        });
    }
};
