<?php

namespace App\Console\Commands;

use App\Services\LegacyRecapJournalBackfill;
use Illuminate\Console\Command;

/**
 * Promote legacy campaign-session recaps (games.recap) into journal
 * entries (D218/D220).
 *
 * The go-live migration ran this once inside its up(); this command is
 * the re-runnable operator surface for everything after that point —
 * a migration rollback + re-run must never be the answer (down() drops
 * user-authored journal content). Idempotent by construction: the
 * NOT EXISTS guard plus the one-recap-per-session partial unique index
 * make a second run a no-op, so it is safe to run any time, on any
 * cadence, or on a schedule after a bulk import.
 *
 * Scope matches the service: campaign sessions only; one-shot games
 * keep their column-only recap until the S03 cleanup decision. The
 * games.recap column is never cleared — it stays as the synced
 * projection.
 */
class BackfillLegacyRecaps extends Command
{
    protected $signature = 'backfill:legacy-recaps';

    protected $description = 'Promote legacy campaign-session recaps into journal entries (idempotent; re-runs are no-ops)';

    public function handle(LegacyRecapJournalBackfill $backfill): int
    {
        $inserted = $backfill->run();

        $this->info($inserted > 0
            ? "Backfilled {$inserted} campaign-session recap(s) into the journal."
            : 'Nothing to backfill — every campaign-session recap already has a journal entry.');

        return self::SUCCESS;
    }
}
