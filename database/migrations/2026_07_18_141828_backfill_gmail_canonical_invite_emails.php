<?php

use App\Services\GmailCanonicalInviteBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill pending email-invite rows to Gmail-canonical form.
 *
 * Background: invitee_email was historically compared and stored with exact
 * string equality. Gmail ignores dots and a "+suffix" in the local part and
 * treats @googlemail.com as identical to @gmail.com, so an invite sent to
 * "alice.smith@gmail.com" could not be claimed by a Google signup that
 * returns "alicesmith@gmail.com" — the row stayed user_id=NULL (blank-name
 * row in the admin Participants tab).
 *
 * EmailCanonicalizer now collapses Gmail-family addresses to one form at both
 * invite-store and registration-match time. This migration rewrites existing
 * invitee_email values so outstanding Gmail invites become claimable and the
 * (entity_id, invitee_email) unique index stays consistent with new invites.
 * The merge logic lives in {@see GmailCanonicalInviteBackfill} so it is
 * unit-tested independently of the migrator.
 *
 * Down is a no-op: canonicalization is lossy (dots/\"+suffix\" are dropped),
 * so the original strings cannot be reconstructed.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(GmailCanonicalInviteBackfill::class)->run();
    }

    public function down(): void
    {
        // Canonicalization drops dots and "+suffix" — the original local parts
        // cannot be reconstructed, so this migration is not reversible.
    }
};
