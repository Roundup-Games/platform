<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rewrites email-invite rows to Gmail-canonical form AND retroactively
 * links already-registered users to invites that were never claimed.
 *
 * Extracted from the backfill migration so the logic is unit-testable and
 * reusable (e.g. a one-off command). See {@see EmailCanonicalizer} for the
 * canonicalization rules.
 *
 * Why two passes: PendingInvitationMatcher only runs at registration time.
 * An invitee who signed up via Google BEFORE the canonicalization fix had
 * their matcher find no match (dot mismatch) and left user_id=NULL. Canonicalizing
 * the invite row later makes it *claimable on a future signup*, but the
 * already-registered user is never re-scanned. linkExistingUsers() closes that
 * gap by associating existing users whose canonical email matches a pending
 * invite — mirroring PendingInvitationMatcher::match() but run once over all
 * historical rows.
 */
final class GmailCanonicalInviteBackfill
{
    /** @var array{string, string}[] [table, foreign_key] pairs */
    private const TARGETS = [
        ['game_participants', 'game_id'],
        ['campaign_participants', 'campaign_id'],
    ];

    /**
     * Rewrite invitee_email on pending/any email-invite rows whose address is a
     * Gmail-family variant, merging dot-variant duplicates so the
     * (entity_id, invitee_email) unique index stays satisfied.
     */
    public function run(): void
    {
        foreach (self::TARGETS as [$table, $foreignKey]) {
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

    /**
     * Associate already-registered users to pending email invites that were
     * never claimed (typically because the invitee signed up before the
     * canonicalization fix, so the registration-time matcher found no match).
     *
     * Returns the number of invite rows newly linked to a user.
     */
    public function linkExistingUsers(): int
    {
        // users.email is NOT stored canonically (registrants keep whatever form
        // they signed up with), so a plain `where email = canonical` misses
        // users registered under a dotted / "+suffix" variant. Build a PHP-side
        // index of every Gmail-family user keyed by canonical form. Bounded to
        // gmail/googlemail addresses so non-Gmail users are never scanned.
        $usersByCanonicalEmail = [];
        User::whereLike('email', '%@gmail.com')
            ->orWhereLike('email', '%@googlemail.com')
            ->select(['id', 'email'])
            ->chunkById(500, function ($users) use (&$usersByCanonicalEmail) {
                foreach ($users as $user) {
                    $canonical = EmailCanonicalizer::canonical($user->email);
                    // First registered user wins on conflict (oldest id),
                    // matching PendingInvitationMatcher's behavior.
                    $usersByCanonicalEmail[$canonical] ??= $user->id;
                }
            });

        $linked = 0;
        foreach (self::TARGETS as [$table, $foreignKey]) {
            // Re-canonicalize defensively in case run() has not run, then look
            // for an existing user by canonical email. Only pending, invited,
            // unlinked rows are candidates.
            DB::table($table)
                ->whereNull('user_id')
                ->whereNotNull('invitee_email')
                ->where('role', ParticipantRole::Invited->value)
                ->where('status', ParticipantStatus::Pending->value)
                ->where(function ($q) {
                    $q->whereLike('invitee_email', '%@gmail.com')
                        ->orWhereLike('invitee_email', '%@googlemail.com');
                })
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($table, &$linked, $usersByCanonicalEmail) {
                    foreach ($rows as $row) {
                        if ($this->linkRow($table, $row, $usersByCanonicalEmail)) {
                            $linked++;
                        }
                    }
                });
        }

        return $linked;
    }

    /**
     * @param  array<string, string>  $usersByCanonicalEmail
     */
    private function linkRow(string $table, object $row, array $usersByCanonicalEmail): bool
    {
        $data = (array) $row;
        $id = $data['id'] ?? null;
        $email = $data['invitee_email'] ?? null;
        if (! is_string($email) || $email === '' || $id === null) {
            return false;
        }

        $canonical = EmailCanonicalizer::canonical($email);
        $userId = $usersByCanonicalEmail[$canonical] ?? null;
        if ($userId === null) {
            return false; // no registered user for this invite yet
        }

        try {
            DB::table($table)->where('id', $id)->update([
                'user_id' => $userId,
                // Normalize the stored address to canonical at the same time so
                // the row is consistent with post-fix storage.
                'invitee_email' => $canonical,
            ]);
        } catch (QueryException $e) {
            // (entity_id, user_id) unique-index conflict: the user is already a
            // participant via another row. Drop this unclaimed invite duplicate
            // rather than leaving a dangling blank-name row.
            Log::warning('backfill.invite_link_conflict', [
                'table' => $table,
                'invite_id' => $id,
                'user_id' => $userId,
                'invitee_email' => $canonical,
            ]);
            DB::table($table)->where('id', $id)->delete();

            return false;
        }

        return true;
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
