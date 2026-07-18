<?php

use App\Services\GmailCanonicalInviteBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Retroactively link already-registered users to pending Gmail invites that
 * were never claimed at signup time.
 *
 * Background: the canonicalization backfill (2026_07_18_141828) rewrote
 * invitee_email to Gmail-canonical form so future signups match. But
 * PendingInvitationMatcher only runs at registration — an invitee who signed
 * up via Google BEFORE the fix had their matcher find no match (dot mismatch)
 * and their invite row stayed user_id=NULL. Canonicalizing the row later did
 * not re-scan already-registered users, so those invites remained unclaimed
 * and rendered as blank-name rows in the admin Participants tab.
 *
 * This migration runs {@see GmailCanonicalInviteBackfill::linkExistingUsers()}
 * once over all historical rows: for each pending Gmail invite, if an existing
 * user canonicalizes to the same address, associate them. It also re-runs the
 * canonicalization pass defensively (idempotent if the prior migration ran).
 *
 * Down is a no-op: linking is the intended end state; unlinking would detach
 * users from invites they should be on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $backfill = app(GmailCanonicalInviteBackfill::class);

        // Defensive: ensure rows are canonical in case the prior migration was
        // skipped or partially run. Idempotent — no-op on already-canonical rows.
        $backfill->run();

        $backfill->linkExistingUsers();
    }

    public function down(): void
    {
        // Linking reflects the correct end state; not reversible.
    }
};
