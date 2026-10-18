<?php

namespace App\Services;

use App\Enums\LocalSubscriptionSource;
use App\Models\LocalSubscription;
use App\Models\MembershipType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Ops comp membership grants (M069 S03, R083).
 *
 * A comp is a flagged local subscription (source=comp, granted_by,
 * reason) that carries the full GM benefit set. The service owns the
 * subscription row only — GM benefits and the Supporting Member badge
 * arrive through the S02 seam: GmRoleService::assignGMRole runs its own
 * transaction and dispatches MembershipActivated after commit, and the
 * queued BadgePipeline awards supporting_member with local_subscription
 * evidence. No badge code lives here.
 */
class CompMembershipService
{
    public function __construct(private readonly GmRoleService $gmRoleService) {}

    /**
     * Grant (or re-grant) a comp membership on the GM plan.
     *
     * A re-grant updates the user's existing active comp row on the plan
     * (ends_at/reason/granted_by) instead of duplicating it. NEVER route
     * this through activateGmSubscription(): it updateOrCreates the row
     * with ends_at null (GmRoleService), wiping fixed-period comps.
     *
     * @param  CarbonInterface|null  $endsAt  Period end, or null for a
     *                                        permanent comp.
     * @return LocalSubscription The comp row (already committed).
     *
     * @throws RuntimeException When no active local GM plan exists (run
     *                          the MembershipTypeSeeder first).
     */
    public function grant(User $user, ?CarbonInterface $endsAt, User $grantedBy, string $reason): LocalSubscription
    {
        $gmPlan = MembershipType::active()
            ->where('type', 'local')
            ->whereJsonContains('metadata->gm_plan', true)
            ->first();

        if (! $gmPlan) {
            throw new RuntimeException(
                'GM plan not found in membership_types. Run the MembershipTypeSeeder (php artisan db:seed) before granting comp memberships.'
            );
        }

        // One transaction for the subscription row ONLY. assignGMRole
        // runs after this commit so its after-commit MembershipActivated
        // dispatch observes committed state.
        $subscription = DB::transaction(function () use ($user, $gmPlan, $endsAt, $grantedBy, $reason) {
            $existing = $user->localSubscriptions()
                ->whereBelongsTo($gmPlan)
                ->where('source', LocalSubscriptionSource::Comp)
                ->active()
                ->first();

            $subscription = $existing ?? new LocalSubscription;

            $subscription->membershipType()->associate($gmPlan);
            $subscription->grantedBy()->associate($grantedBy);
            $subscription->fill([
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => $endsAt,
                'canceled_at' => null,
                'source' => LocalSubscriptionSource::Comp,
                'reason' => $reason,
            ]);

            $user->localSubscriptions()->save($subscription);

            Log::info('comp membership granted', [
                'user_id' => $user->id,
                'granted_by' => $grantedBy->id,
                'local_subscription_id' => $subscription->id,
                'ends_at' => $endsAt?->toIso8601String(),
            ]);

            return $subscription;
        });

        // Re-checks hasActiveMembership() (true via the committed comp
        // row), assigns the GM role, and dispatches MembershipActivated
        // after commit — the badge pipeline's sole entry point.
        $this->gmRoleService->assignGMRole($user);

        return $subscription;
    }

    /**
     * Withdraw the user's active comp membership.
     *
     * Cancels the comp row (the grant reason stays on it; the withdraw
     * reason rides the structured log). GM benefits lapse only when no
     * other active membership remains (self-serve local or Paddle) —
     * otherwise assignGMRole's role/profile state stays untouched.
     *
     * @return bool True when a comp row was canceled, false when the
     *              user has no active comp to withdraw.
     */
    public function withdraw(User $user, User $actor, string $reason): bool
    {
        $comp = $user->localSubscriptions()
            ->where('source', LocalSubscriptionSource::Comp)
            ->active()
            ->first();

        if (! $comp) {
            return false;
        }

        DB::transaction(function () use ($user, $actor, $reason, $comp) {
            $comp->update([
                'status' => 'canceled',
                'canceled_at' => now(),
            ]);

            Log::info('comp membership withdrawn', [
                'user_id' => $user->id,
                'actor_id' => $actor->id,
                'reason' => $reason,
            ]);
        });

        // Benefits follow the last active membership. hasActiveMembership()
        // is a live query, so it sees the canceled row. When another
        // membership remains, the GM role/profile stay as they are.
        if (! $user->hasActiveMembership()) {
            $this->gmRoleService->handleSubscriptionLapse($user);
        }

        return true;
    }
}
