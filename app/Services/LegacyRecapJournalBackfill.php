<?php

namespace App\Services;

use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Models\Game;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Backfills legacy games.recap values into the campaign journal.
 *
 * D220: campaign_journal_entries is the source of truth for recaps; the
 * games.recap column becomes a synced projection written by
 * CampaignJournalService. This backfill promotes every pre-journal recap on
 * a campaign session to its kind=recap journal entry so the S02 timeline,
 * S04 Previously On and Campaign Compleat (D222 — migrated legacy recaps
 * count toward coverage) start from complete data.
 *
 * Extracted from the journal-table migration so the logic is unit-testable
 * and re-runnable (pattern: {@see GmailCanonicalInviteBackfill}). Idempotent
 * via the NOT EXISTS guard — safe to re-run and safe against the
 * campaign_journal_entries_one_recap_per_session partial unique index.
 *
 * Scope (D218/D220):
 *  - Campaign sessions only (campaign_id NOT NULL); one-shot games keep
 *    their column-only recap until the S03 cleanup decision.
 *  - Read-only on games — recap is NOT cleared; the column stays as the
 *    synced projection.
 */
final class LegacyRecapJournalBackfill
{
    /**
     * Promote every legacy campaign-session recap to a journal entry.
     *
     * @return int Number of journal entries inserted (0 on a re-run).
     */
    public function run(): int
    {
        $inserted = 0;

        Game::query()
            ->select(['id', 'campaign_id', 'owner_id', 'recap', 'updated_at'])
            ->whereNotNull('recap')
            ->where('recap', '!=', '')
            ->whereNotNull('campaign_id')
            // Idempotency guard: skip sessions that already have a recap
            // entry — re-runs of this backfill and writes made through
            // CampaignJournalService after go-live. A correlated NOT EXISTS
            // keeps it one predicate per page instead of a per-row probe,
            // and pre-empts the one-recap-per-session partial unique index.
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('campaign_journal_entries')
                    ->whereColumn('campaign_journal_entries.game_id', 'games.id')
                    ->where('kind', JournalEntryKind::Recap->value);
            })
            ->chunkById(500, function ($games) use (&$inserted): void {
                /** @var iterable<Game> $games */
                foreach ($games as $game) {
                    $this->writeEntry($game);
                    $inserted++;
                }
            });

        Log::info('backfill.legacy_recap_journal', ['inserted' => $inserted]);

        return $inserted;
    }

    /**
     * Write one session's recap entry. Entry timestamps inherit the recap's
     * original write time: game.updated_at is the closest timeline-order
     * proxy (games carry no ended_at, MEM585). A NULL updated_at (raw
     * legacy import) is stamped with the backfill's now() — storing the
     * NULL would pin the entry to the TOP of the timeline forever
     * (Postgres sorts NULLS FIRST under ORDER BY created_at DESC).
     */
    private function writeEntry(Game $game): void
    {
        // Created through the journalEntries relation (R2: no hand-plumbed
        // game_id). created_at/updated_at are set directly below — they are
        // not mass-assignable, and explicit values win over the automatic
        // touch because the attributes are already dirty.
        $entry = $game->journalEntries()->make([
            'campaign_id' => $game->campaign_id,
            'author_user_id' => $game->owner_id,
            'kind' => JournalEntryKind::Recap,
            'body' => $game->recap,
            'visibility' => JournalEntryVisibility::Players,
        ]);

        $stamp = $game->updated_at ?? now();

        $entry->created_at = $stamp;
        $entry->updated_at = $stamp;

        $entry->save();
    }
}
