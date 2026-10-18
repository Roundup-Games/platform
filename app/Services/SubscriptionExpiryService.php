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
     * then each flip runs in its own transaction so a failure on one
     * row never un-expires its neighbors. ends_at stays as the actual
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

        $due = LocalSubscription::query()
            ->with('user')
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($due as $subscription) {
            DB::transaction(function () use ($subscription): void {
                $subscription->update(['status' => 'expired']);
            });

            $expired++;

            $user = $subscription->user;

            if ($user === null) {
                Log::warning('subscriptions.expire.orphaned_row', [
                    'local_subscription_id' => $subscription->id,
                ]);

                continue;
            }

            if (! $user->hasActiveMembership()) {
                $this->gmRoleService->handleSubscriptionLapse($user);
                $lapsed++;
            }
        }

        Log::info('subscriptions.expire.completed', [
            'expired' => $expired,
            'lapsed' => $lapsed,
        ]);

        return ['expired' => $expired, 'lapsed' => $lapsed];
    }
}
