<?php

namespace App\Services;

use App\Models\LocalSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Local-subscription expiry sweep (M069 S03 T3, R083).
 *
 * Fixed-period comps (ends_at set) must actually end. scopeActive alone
 * only hides a past-end row from queries — the row keeps status=active
 * and membership history stays muddy. This service flips due rows to
 * expired and lapses GM benefits when the user has no active membership
 * left, mirroring CompMembershipService::withdraw's seam: benefits
 * follow the LAST active membership, so hasActiveMembership() (Paddle
 * subscribed() plus local scopeActive truth) decides the lapse. Badge
 * lapse stays sweep-owned — the 06:30 badges:sweep stamps the
 * Supporting Member badge the same night (schedule comment,
 * routes/console.php).
 */
class SubscriptionExpiryService
{
    public function __construct(private readonly GmRoleService $gmRoleService) {}

    /**
     * Expire due local subscriptions and lapse orphaned GM benefits.
     *
     * One bounded query selects the due rows (active + ends_at past),
     * then each row — flip AND GM-benefit lapse together — runs in its
     * own transaction so a failure on one row never touches its
     * neighbors — and never aborts the sweep: a row whose transaction
     * throws is rolled back whole (still active, still due), logged and
     * skipped, retrying the next night (the command's documented
     * always-SUCCESS contract, pinned by SubscriptionsExpireTest). ends_at stays as the actual
     * period end and canceled_at is never touched — cancellation is an
     * explicit operator action (CompMembershipService::withdraw), expiry
     * is the passage of time.
     *
     * @return array{expired: int, lapsed: int}
     */
    public function expireDue(): array
    {
        $expired = 0;
        $lapsed = 0;

        // user.subscriptions eager-loaded deliberately: hasActiveMembership()
        // reaches Cashier's subscribed(), which reads ->subscriptions as a
        // model property — a lazy load that Model::shouldBeStrict flags
        // (non-production) once the due set hydrates users from a
        // multi-row result. Same pattern and rationale as
        // SupportingMemberEvaluator's cohort query (also the N+1 fix).
        $due = LocalSubscription::query()
            ->with('user.subscriptions')
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($due as $subscription) {
            try {
                // Flip AND lapse in ONE transaction: the lapse must not
                // be skipped while the flip commits — the due query only
                // selects status=active, so a committed flip whose lapse
                // threw would strand the user's GM benefits forever (the
                // sweep can never re-find the row). Atomic here means a
                // lapse failure rolls the flip back too, leaving the row
                // due again tomorrow night — the retry the sweep promises.
                $lapsedThisRow = false;
                $expiredThisRow = false;

                DB::transaction(function () use ($subscription, &$lapsedThisRow, &$expiredThisRow): void {
                    // Conditional flip, not the snapshot's word: the row
                    // must STILL be active and due at flip time. A comp
                    // re-grant (CompMembershipService renews ends_at on
                    // the in-place row) or a withdraw landing between the
                    // snapshot and this transaction makes the row no
                    // longer ours to expire — an unconditional flip would
                    // destroy the renewal AND lapse benefits off a grant
                    // the admin saw succeed.
                    $stillDue = LocalSubscription::query()
                        ->whereKey($subscription->getKey())
                        ->where('status', 'active')
                        ->whereNotNull('ends_at')
                        ->where('ends_at', '<=', now())
                        ->update(['status' => 'expired']) > 0;

                    if (! $stillDue) {
                        return;
                    }

                    $expiredThisRow = true;

                    $user = $subscription->user;

                    if ($user === null) {
                        Log::warning('subscriptions.expire.orphaned_row', [
                            'local_subscription_id' => $subscription->id,
                        ]);

                        return;
                    }

                    // Live re-check at decision time via the
                    // last-active-membership seam: a Paddle webhook or
                    // comp grant that lands between the snapshot and this
                    // transaction must decide the lapse.
                    // Cancel-after-snapshot would otherwise strand
                    // benefits forever (the due query never revisits an
                    // expired row). refresh() re-hydrates the model and
                    // its loaded relations; handleSubscriptionLapse()
                    // returns whether the lapse actually ran.
                    $lapsedThisRow = $this->gmRoleService
                        ->handleSubscriptionLapse($user->refresh());
                });

                // Count only landed flips: a declined conditional flip
                // (comp re-grant or withdraw beat the sweep to the row)
                // expired nothing — reporting it would overstate the
                // sweep in the structured log and command output.
                if ($expiredThisRow) {
                    $expired++;

                    if ($lapsedThisRow) {
                        $lapsed++;
                    }
                }
            } catch (\Throwable $e) {
                // Per-row failure isolation: nothing committed for this
                // row (flip and lapse share the transaction), the row is
                // logged and skipped, and tomorrow night's due query
                // finds it again — the documented retry contract.
                Log::warning('subscriptions.expire.row_failed', [
                    'local_subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('subscriptions.expire.completed', [
            'expired' => $expired,
            'lapsed' => $lapsed,
        ]);

        return ['expired' => $expired, 'lapsed' => $lapsed];
    }
}
