<?php

namespace App\Console\Commands;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Services\GmailCanonicalInviteBackfill;
use Illuminate\Console\Command;

/**
 * Backfill Gmail-family email invites to canonical form and retroactively
 * link already-registered users who were never matched at signup time.
 *
 * Two phases (both idempotent):
 *   1. Canonicalize invitee_email rows (dots, "+suffix", @googlemail.com),
 *      merging dot-variant duplicates.
 *   2. Associate pending invites to existing users whose canonical email
 *      matches — closes the gap for invitees who signed up before the fix,
 *      when the registration-time matcher found no match.
 *
 * Usage:
 *   php artisan invites:canonicalize-gmail           # Run both phases
 *   php artisan invites:canonicalize-gmail --dry-run # Preview counts only
 *   php artisan invites:canonicalize-gmail --link-only # Skip phase 1
 */
class CanonicalizeGmailInvites extends Command
{
    protected $signature = 'invites:canonicalize-gmail
        {--dry-run : Report what would change without writing}
        {--link-only : Skip the email-canonicalization phase}';

    protected $description = 'Canonicalize Gmail invite emails and link existing registered users to pending invites';

    public function handle(GmailCanonicalInviteBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no rows will be written. Counts are computed on live data.');

            $pendingGmailInvites = $this->countPendingGmailInvites();
            $unlinkedGmailInvites = $this->countUnlinkedClaimableInvites();

            $this->info("Pending Gmail-family invite rows: {$pendingGmailInvites}");
            $this->info("Unlinked pending invites that would be scanned for linking: {$unlinkedGmailInvites}");

            return self::SUCCESS;
        }

        if (! $this->option('link-only')) {
            $this->info('Phase 1: canonicalizing Gmail invite emails…');
            $backfill->run();
            $this->info('Phase 1 complete.');
        }

        $this->info('Phase 2: linking existing registered users to pending invites…');
        $linked = $backfill->linkExistingUsers();
        $this->info("Phase 2 complete — {$linked} invite(s) linked to existing users.");

        return self::SUCCESS;
    }

    private function countPendingGmailInvites(): int
    {
        return \DB::table('game_participants')->whereNotNull('invitee_email')
            ->where(function ($q) {
                $q->whereLike('invitee_email', '%@gmail.com')->orWhereLike('invitee_email', '%@googlemail.com');
            })->count()
            + \DB::table('campaign_participants')->whereNotNull('invitee_email')
                ->where(function ($q) {
                    $q->whereLike('invitee_email', '%@gmail.com')->orWhereLike('invitee_email', '%@googlemail.com');
                })->count();
    }

    private function countUnlinkedClaimableInvites(): int
    {
        return \DB::table('game_participants')
            ->whereNull('user_id')->whereNotNull('invitee_email')
            ->where('role', ParticipantRole::Invited->value)
            ->where('status', ParticipantStatus::Pending->value)
            ->where(function ($q) {
                $q->whereLike('invitee_email', '%@gmail.com')->orWhereLike('invitee_email', '%@googlemail.com');
            })->count()
            + \DB::table('campaign_participants')
                ->whereNull('user_id')->whereNotNull('invitee_email')
                ->where('role', ParticipantRole::Invited->value)
                ->where('status', ParticipantStatus::Pending->value)
                ->where(function ($q) {
                    $q->whereLike('invitee_email', '%@gmail.com')->orWhereLike('invitee_email', '%@googlemail.com');
                })->count();
    }
}
