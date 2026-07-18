<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Rewrites email-invite rows to Gmail-canonical form.
 *
 * Extracted from the backfill migration so the logic is unit-testable and
 * reusable (e.g. a future one-off command). The migration delegates here; see
 * {@see EmailCanonicalizer} for the canonicalization rules.
 */
final class GmailCanonicalInviteBackfill
{
    /**
     * Rewrite invitee_email on pending/any email-invite rows whose address is a
     * Gmail-family variant, merging dot-variant duplicates so the
     * (entity_id, invitee_email) unique index stays satisfied.
     */
    public function run(): void
    {
        $targets = [
            ['game_participants', 'game_id'],
            ['campaign_participants', 'campaign_id'],
        ];

        foreach ($targets as [$table, $foreignKey]) {
            // Order by id so the earliest invite survives when dot-variant
            // duplicates collapse onto the same canonical key.
            DB::table($table)
                ->whereNotNull('invitee_email')
                ->where(function ($q) {
                    $q->whereLike('invitee_email', '%@gmail.com')
                        ->orWhereLike('invitee_email', '%@googlemail.com');
                })
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($table, $foreignKey) {
                    foreach ($rows as $row) {
                        $this->canonicalizeRow($table, $foreignKey, $row);
                    }
                });
        }
    }

    private function canonicalizeRow(string $table, string $foreignKey, object $row): void
    {
        $data = (array) $row;
        $id = $data['id'] ?? null;
        $original = $data['invitee_email'] ?? null;
        $foreignKeyValue = $data[$foreignKey] ?? null;

        if (! is_string($original) || $original === '' || $id === null || $foreignKeyValue === null) {
            return;
        }

        $canonical = EmailCanonicalizer::canonical($original);
        if ($canonical === $original) {
            return; // already canonical
        }

        // If a row for the same entity already uses the canonical form, the two
        // are Gmail-variant duplicates of one invite. Keep whichever already
        // linked a user (else the earliest by id ordering); delete the other.
        $survivor = DB::table($table)
            ->where($foreignKey, $foreignKeyValue)
            ->where('invitee_email', $canonical)
            ->first();

        if ($survivor !== null) {
            $survivorData = (array) $survivor;
            $rowHasUser = filled($data['user_id'] ?? null);
            $survivorHasUser = filled($survivorData['user_id'] ?? null);

            if ($rowHasUser && ! $survivorHasUser) {
                DB::table($table)->where('id', $survivorData['id'] ?? null)->delete();
                DB::table($table)->where('id', $id)->update(['invitee_email' => $canonical]);
            } else {
                // Keep the existing survivor (earlier by ordering, or already
                // matched); drop this later/empty duplicate.
                DB::table($table)->where('id', $id)->delete();
            }

            return;
        }

        DB::table($table)->where('id', $id)->update(['invitee_email' => $canonical]);
    }
}
