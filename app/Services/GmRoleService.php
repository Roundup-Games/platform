<?php

namespace App\Services;

use App\Enums\LocalSubscriptionSource;
use App\Events\MembershipActivated;
use App\Events\MembershipEnded;
use App\Models\GMProfile;
use App\Models\MembershipType;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class GmRoleService
{
    private const ROLE_NAME = 'Game Master';

    /**
     * Activate a free GM subscription for the user.
     *
     * Creates a LocalSubscription, assigns the GM role, and activates the GMProfile.
     * Idempotent — safe to call if already subscribed.
     *
     * Pass the plan the user actually chose when the caller has one
     * (BillingPortal resolves and validates the clicked row); the lookup
     * is a fallback for plan-less callers (MembershipPage), ordered by
     * price so several active gm_plan rows resolve deterministically
     * instead of by physical row order.
     */
    public function activateGmSubscription(User $user, ?MembershipType $plan = null): bool
    {
        $gmPlan = $plan ?? MembershipType::active()
            ->where('type', 'local')
            ->whereJsonContains('metadata->gm_plan', true)
            ->orderBy('price_cents')
            ->first();

        if (! $gmPlan) {
            Log::error('GM plan not found in membership_types. Ensure MembershipTypeSeeder has been run.');

            return false;
        }

        try {
            $this->persistActivation($user, $gmPlan);
        } catch (UniqueConstraintViolationException) {
            // A concurrent activation (double-click on Start/Reactivate)
            // won the insert — the partial unique index (one active row
            // per user, plan, source) turned this lookup-then-write into
            // a loser. Converge on the winner's row instead of surfacing
            // a 500: the retry's updateOrCreate finds the committed row
            // and takes the update path (same semantics as
            // CompMembershipService::persistGrant).
            $this->persistActivation($user, $gmPlan);
        }

        // Membership domain event AFTER the transaction commits
        // (after-commit discipline, cf. BadgeService): the queued badge
        // pipeline only ever observes committed membership state.
        // Repeated dispatches are safe — the pipeline is idempotent. The
        // transaction either returns true or throws, so reaching here
        // means the activation is committed.
        MembershipActivated::dispatch($user);

        return true;
    }

    /**
     * Deactivate the user's SELF-SERVE GM subscription (the billing
     * portal's cancel action).
     *
     * Scoped to source=self_serve on purpose (the mirror of
     * activateGmSubscription's scoping): with the M069 comp model a comp
     * and a self-serve row can both be active on the same GM plan, and
     * a source-blind pick could cancel the admin-granted comp — only
     * CompMembershipService::withdraw ends a comp. GM benefits (role,
     * public-entry rights, GMProfile) lapse only when NO active
     * membership remains after the cancel (the withdraw semantics);
     * a surviving comp keeps them alive.
     *
     * Does NOT delete the profile or reviews (revokeGMRole contract).
     *
     * @return bool True when a self-serve row was canceled, false when
     *              the user has no active self-serve GM subscription
     *              (nothing was touched — the caller must not report
     *              a cancellation).
     */
    public function deactivateGmSubscription(User $user): bool
    {
        $subscription = $user->localSubscriptions()
            ->whereHas('membershipType', fn ($q) => $q->whereJsonContains('metadata->gm_plan', true))
            ->where('source', LocalSubscriptionSource::SelfServe)
            ->active()
            ->first();

        if ($subscription === null) {
            return false;
        }

        DB::transaction(function () use ($user, $subscription): void {
            $subscription->update([
                'status' => 'canceled',
                'canceled_at' => now(),
            ]);

            Log::info('GM subscription deactivated', [
                'user_id' => $user->id,
                'local_subscription_id' => $subscription->id,
            ]);
        });

        // Benefits follow the last active membership — the invariant is
        // single-sourced inside handleSubscriptionLapse(): a live
        // hasActiveMembership() re-check there decides the lapse, so a
        // surviving comp (or any other active membership) keeps the
        // role/profile alive and no MembershipEnded fires.
        $this->handleSubscriptionLapse($user);

        return true;
    }

    /**
     * Assign the Game Master role to a user.
     *
     * Requirements:
     * - User must have an active subscription (Paddle or local).
     * - Creates a GMProfile if one does not already exist.
     * - Sets is_active=true on the GMProfile.
     * - Assigns the 'Game Master' Spatie role at global scope.
     *
     * @return bool True if the role was assigned (or already active).
     */
    public function assignGMRole(User $user): bool
    {
        if (! $user->hasActiveMembership()) {
            Log::warning('GM role assignment denied: no active subscription', [
                'user_id' => $user->id,
                'action' => 'assignGMRole',
            ]);

            return false;
        }

        DB::transaction(function () use ($user) {
            // Ensure the role exists (defense against unseeded DB)
            $role = Role::firstOrCreate([
                'name' => self::ROLE_NAME,
                'guard_name' => 'web',
                'team_id' => null,
            ]);

            // Assign at global scope
            if (! $user->hasRole(self::ROLE_NAME)) {
                $user->assignRole($role);
            }

            // Grant public game/campaign creation for active GMs
            $user->can_create_public_entries = true;
            $user->save();

            // Create or reactivate the GMProfile
            $profile = $user->gmProfile()->updateOrCreate([], ['is_active' => true]);

            Log::info('GM role assigned', [
                'user_id' => $user->id,
                'gm_profile_id' => $profile->id,
            ]);

            return true;
        });

        // The Paddle-path funnel: a GM whose membership is already active
        // (Paddle webhook, comp, or a local plan) gets the same membership
        // event as a fresh local activation — the badge pipeline cannot
        // tell the paths apart, by design. The transaction either returns
        // true or throws, so reaching here means the assignment committed.
        MembershipActivated::dispatch($user);

        return true;
    }

    /**
     * Revoke the Game Master role from a user.
     *
     * - Removes the 'Game Master' Spatie role.
     * - Sets gmProfile.is_active = false.
     * - Does NOT delete the GMProfile or its reviews.
     */
    public function revokeGMRole(User $user): void
    {
        DB::transaction(function () use ($user) {
            if ($user->hasRole(self::ROLE_NAME)) {
                $user->removeRole(self::ROLE_NAME);
            }

            // Revoke public game/campaign creation
            $user->can_create_public_entries = false;
            $user->save();

            $profile = $user->gmProfile;
            if ($profile && $profile->is_active) {
                $profile->is_active = false;
                $profile->save();
            }

            Log::info('GM role revoked', [
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * Check if a user is an active GM (has role AND active subscription).
     */
    public function isGmActive(User $user): bool
    {
        return $user->hasRole(self::ROLE_NAME) && $user->hasActiveMembership();
    }

    /**
     * Handle a GM-benefits lapse: the LAST-active-membership seam.
     *
     * The invariant is single-sourced here: benefits (role, public-entry
     * rights, GMProfile) lapse only when NO active membership remains —
     * a live hasActiveMembership() re-check (Cashier subscribed() plus
     * any active local plan) decides. Callers invoke this whenever a
     * membership ended; the seam itself sorts out whether another
     * membership survives. The copy-pasted check-then-act guards that
     * used to live at every call site raced grants that committed
     * between their check and the revoke.
     *
     * Retention-predicate note: this seam retains on ANY active
     * membership (the M069/M071 portal/comp/expiry semantics). The
     * Paddle subscription.canceled webhook intentionally retains on
     * the narrower hasGmSubscription() (GM-plan local rows only) and
     * calls the unguarded lapseGmBenefits() below — a divergence
     * settled in 6c0b19ed and pinned by PaddleWebhookLifecycleSmokeTest
     * that differs for a user whose sole surviving membership is a
     * non-GM local plan (GM-tier entitlement: the webhook lapses, the
     * portal paths retain). Tracked for a future product call on
     * unifying the retention predicate.
     *
     * Revokes the GM role but preserves the GMProfile for historical
     * data, then dispatches MembershipEnded after the revoke commits.
     *
     * @return bool True when the lapse ran (role revoked + MembershipEnded
     *              dispatched); false when another active membership
     *              retains the benefits.
     */
    public function handleSubscriptionLapse(User $user): bool
    {
        if ($user->hasActiveMembership()) {
            Log::info('GM benefits retained: another active membership remains', [
                'user_id' => $user->id,
            ]);

            return false;
        }

        return $this->lapseGmBenefits($user);
    }

    /**
     * The unguarded lapse: revoke GM benefits and dispatch MembershipEnded.
     *
     * Retention is the caller's concern — today only the Paddle webhook
     * uses this directly (its narrow hasGmSubscription() retain-guard
     * predates the last-active-membership invariant; see
     * handleSubscriptionLapse). Every other path goes through the seam.
     */
    public function lapseGmBenefits(User $user): bool
    {
        Log::info('GM subscription lapse handling started', [
            'user_id' => $user->id,
        ]);

        $this->revokeGMRole($user);

        Log::info('GM subscription lapse handling completed', [
            'user_id' => $user->id,
        ]);

        // Membership end — the same domain event from every path, so
        // consumers never care which membership ended it.
        MembershipEnded::dispatch($user);

        return true;
    }

    /**
     * The transactional write behind activateGmSubscription(): the
     * self-serve subscription row, entry rights, GM role, and profile —
     * re-invoked after a unique-violation loss (the second updateOrCreate
     * hits the winner's row and takes the update path).
     */
    private function persistActivation(User $user, MembershipType $gmPlan): void
    {
        DB::transaction(function () use ($user, $gmPlan): void {
            // Ensure the role exists (defense against unseeded DB)
            $role = Role::firstOrCreate([
                'name' => self::ROLE_NAME,
                'guard_name' => 'web',
                'team_id' => null,
            ]);

            // Create or reactivate the user's SELF-SERVE local
            // subscription row. Scoped to source=self_serve on purpose:
            // matching on the plan alone would flip an expired/withdrawn
            // COMP row back to active with ends_at null while keeping its
            // source/granted_by/reason — provenance that lies (comp
            // reporting would count a self-serve revival as an admin
            // grant). Comp re-grants route through
            // CompMembershipService::grant, which owns those columns.
            // starts_at survives reactivation (the comp path's "a
            // re-grant renews, it never rewrites history" rule): a
            // churned-and-returned supporter keeps their original start
            // date, because SupportingMemberEvaluator reads the EARLIEST
            // starts_at for tenure tiers. The EARLIEST across ALL rows
            // of the key, history included: the active-per-source dedupe
            // migration keeps the newest-active row and cancels the
            // rest, so the surviving active row can carry a LATER
            // starts_at than its canceled siblings — reading any single
            // row ("value('starts_at')" without ordering) can anchor
            // tenure on an arbitrary history row.
            $existingStartsAt = $user->localSubscriptions()
                ->where('membership_type_id', $gmPlan->id)
                ->where('source', LocalSubscriptionSource::SelfServe)
                ->min('starts_at');

            // Prefer the LIVE row, fall back to the earliest-started
            // history row (both under the row lock, mirroring
            // CompMembershipService::persistGrant). An unordered pick
            // over the dedupe-manufactured state (newer active + older
            // canceled rows for the same key) can select a CANCELED row,
            // flip it to active, and violate the partial unique index —
            // and the violation-retry re-picks the same loser forever,
            // 500-looping every later activation for exactly the users
            // the dedupe migration was written for.
            $existing = $user->localSubscriptions()
                ->where('membership_type_id', $gmPlan->id)
                ->where('source', LocalSubscriptionSource::SelfServe)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first()
                ?? $user->localSubscriptions()
                    ->where('membership_type_id', $gmPlan->id)
                    ->where('source', LocalSubscriptionSource::SelfServe)
                    ->orderBy('starts_at')
                    ->lockForUpdate()
                    ->first();

            if ($existing !== null) {
                $existing->fill([
                    'status' => 'active',
                    'starts_at' => $existingStartsAt ?? now(),
                    'ends_at' => null,
                    'canceled_at' => null,
                ])->save();
            } else {
                $user->localSubscriptions()->create([
                    'membership_type_id' => $gmPlan->id,
                    'source' => LocalSubscriptionSource::SelfServe,
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => null,
                    'canceled_at' => null,
                ]);
            }

            // Grant public game/campaign creation for active GMs
            $user->can_create_public_entries = true;
            $user->save();

            // Assign the GM role at global scope
            if (! $user->hasRole(self::ROLE_NAME)) {
                $user->assignRole($role);
            }

            // Create or reactivate the GMProfile
            $profile = $user->gmProfile()->updateOrCreate([], ['is_active' => true]);

            Log::info('GM subscription activated', [
                'user_id' => $user->id,
                'gm_profile_id' => $profile->id,
            ]);
        });
    }
}
