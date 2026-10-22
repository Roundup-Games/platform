<?php

namespace App\Services;

use App\Enums\LocalSubscriptionSource;
use App\Models\LocalSubscription;
use App\Models\MembershipType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * Two transactions by design: the comp row commits, then
     * assignGMRole runs its own transaction. A failure between the two
     * strands an active-but-roleless comp until the admin retries — and
     * the retry heals (the re-grant update path converges, assignGMRole
     * re-runs). Do NOT "fix" this into a nested transaction: the
     * after-commit MembershipActivated dispatch must observe committed
     * state.
     *
     * @param  CarbonInterface|null  $endsAt  Period end, or null for a
     *                                        permanent comp.
     * @return LocalSubscription The comp row (already committed).
     *
     * @throws RuntimeException When no active local GM plan exists (run
     *                          the MembershipTypeSeeder first).
     * @throws \InvalidArgumentException When $endsAt lies in the past —
     *                                   a grant that cannot carry benefits
     *                                   must refuse loudly, not return a
     *                                   benefitless active row.
     */
    public function grant(User $user, ?CarbonInterface $endsAt, User $grantedBy, string $reason): LocalSubscription
    {
        if ($endsAt !== null && $endsAt->isPast()) {
            throw new \InvalidArgumentException('Comp period end must be in the future (or null for permanent).');
        }

        // Same determinism rule as GmRoleService::activateGmSubscription:
        // order by price so several active gm_plan tiers (Game Master,
        // Game Master+…) resolve to the base plan instead of physical
        // row order — provenance and badge evidence stay unambiguous.
        $gmPlan = MembershipType::active()
            ->where('type', 'local')
            ->whereJsonContains('metadata->gm_plan', true)
            ->orderBy('price_cents')
            ->first();

        if (! $gmPlan) {
            throw new RuntimeException(
                'GM plan not found in membership_types. Run the MembershipTypeSeeder (php artisan db:seed) before granting comp memberships.'
            );
        }

        // One transaction for the subscription row ONLY. assignGMRole
        // runs after this commit so its after-commit MembershipActivated
        // dispatch observes committed state. The partial unique index
        // (user_id, membership_type_id, source) where status=active is
        // the structural backstop for the double-grant race — two
        // concurrent grants converge on the winner's row below.
        try {
            $subscription = $this->persistGrant($user, $gmPlan, $endsAt, $grantedBy, $reason);
        } catch (UniqueConstraintViolationException) {
            // A concurrent grant won the insert — converge on its row
            // (same plan, same provenance; the re-grant semantics make
            // the loser's values equivalent).
            $subscription = $this->persistGrant($user, $gmPlan, $endsAt, $grantedBy, $reason);
        }

        // Re-checks hasActiveMembership() (true via the committed comp
        // row), assigns the GM role, and dispatches MembershipActivated
        // after commit — the badge pipeline's sole entry point.
        $this->gmRoleService->assignGMRole($user);

        return $subscription;
    }

    /**
     * The transactional row write behind grant(): find-or-create the
     * active comp row. Re-invoked after a unique-violation loss (the
     * second find hits the winner's row and takes the update path).
     */
    private function persistGrant(User $user, MembershipType $gmPlan, ?CarbonInterface $endsAt, User $grantedBy, string $reason): LocalSubscription
    {
        return DB::transaction(function () use ($user, $gmPlan, $endsAt, $grantedBy, $reason) {
            // status = 'active' regardless of ends_at: a period that
            // has ended but not yet been swept (03:15 owns the flip) is
            // still THE current comp row — re-granting must renew it in
            // place, never insert beside it (the partial unique index
            // makes the old scopeActive() find here impossible anyway).
            //
            // Row lock serializes against withdraw() (and the expiry
            // sweep) on this same row: without it, a withdraw committing
            // between the read and the save is silently undone by the
            // unconditional fill below — re-activating a comp an admin
            // just canceled, then re-granting the benefits they saw
            // lapse. The partial unique index only guards inserts; this
            // is same-row mutation.
            $existing = $user->localSubscriptions()
                ->whereBelongsTo($gmPlan)
                ->where('source', LocalSubscriptionSource::Comp)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $subscription = $existing ?? new LocalSubscription;

            // A re-grant renews, it never rewrites history: starts_at
            // survives from the original grant (every grant event stays
            // in the structured log; the row keeps the FIRST grant date).
            $isNew = $existing === null;

            $subscription->membershipType()->associate($gmPlan);
            $subscription->grantedBy()->associate($grantedBy);
            $subscription->fill([
                'status' => 'active',
                'starts_at' => $isNew ? now() : $subscription->starts_at,
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
        $canceled = DB::transaction(function () use ($user, $actor, $reason): bool {
            // Resolve under the row lock, inside the transaction: an
            // unlocked read outside races persistGrant() both ways — a
            // withdraw committing between grant's read and save gets
            // silently undone, and vice versa a grant committing after
            // this read would be canceled by the stale-model update.
            // The lock (persistGrant takes the same one) serializes
            // the two writers on the shared comp row.
            $comp = $user->localSubscriptions()
                ->where('source', LocalSubscriptionSource::Comp)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($comp === null) {
                return false;
            }

            $comp->update([
                'status' => 'canceled',
                'canceled_at' => now(),
            ]);

            Log::info('comp membership withdrawn', [
                'user_id' => $user->id,
                'actor_id' => $actor->id,
                'reason' => $reason,
            ]);

            return true;
        });

        if (! $canceled) {
            return false;
        }

        // Benefits follow the last active membership — single-sourced
        // inside handleSubscriptionLapse(): a live re-check there keeps
        // the role/profile alive whenever another membership remains.
        $this->gmRoleService->handleSubscriptionLapse($user);

        return true;
    }
}
