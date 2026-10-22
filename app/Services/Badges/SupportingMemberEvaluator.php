<?php

namespace App\Services\Badges;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Supporting Member (spec §2.5): active paid membership, tiered by
 * completed months of support history — the money-badge done honestly
 * (spec §1.5: certifies financial support, never skill).
 *
 * - evaluate() runs from the MembershipActivated pipeline: every
 *   activation re-proposes at the tier the user's full support history
 *   has earned. Tenure is the span since the earliest membership start
 *   across local (starts_at, any status — support history counts) and
 *   Cashier (created_at) subscriptions, so rejoiners and plan-hoppers
 *   keep their months.
 * - sweep() is the nightly re-read of everything the event path cannot
 *   know: tenure tier crossings (year_2/3/5 at 12/24/60 completed
 *   months), quiet lapses when the membership went inactive (spec §4),
 *   and requalification on the next award after a rejoin.
 *
 * Tier thresholds live in the registry entry (single source of truth).
 */
class SupportingMemberEvaluator extends CohortSweepEvaluator
{
    private const BADGE_KEY = 'supporting_member';

    private const LAPSE_REASON = 'membership_inactive';

    protected function badgeKey(): string
    {
        return self::BADGE_KEY;
    }

    /** Quiet lapse (spec §4): membership gone → park the badge. Idempotent — no hold, already lapsed, admin-revoked, a manual-source grant (never auto-lapsed), or a retired definition (holds freeze) is a no-op inside BadgeService. */
    protected function lapseReason(): ?string
    {
        return self::LAPSE_REASON;
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The live computation both surfaces share: tier earned plus the
     * evidence model, or null when the user has no active membership.
     *
     * @return array{0: string|null, 1: Model|null}|null
     */
    protected function proposalFor(User $user): ?array
    {
        if (! $user->hasActiveMembership()) {
            return null;
        }

        $earliest = $this->earliestMembershipStart($user);

        if ($earliest === null) {
            return null;
        }

        [$startedAt, $evidence] = $earliest;

        // Completed months: 12 full months to the day earn year_2.
        // Carbon 3's diffInMonths is signed: a future starts_at (possible
        // only on hand-crafted/admin rows — every writer uses now())
        // would count the months UNTIL the start as elapsed tenure.
        // Clamp at zero instead of abs().
        $completedMonths = (int) floor(max(0, $startedAt->diffInMonths(now())));

        $tierKey = $this->tierFor($completedMonths);

        // year_1 sits at threshold 0, so an active member always earns at
        // least it; null here means the registry entry lost its tiers.
        if ($tierKey === null) {
            return null;
        }

        return [$tierKey, $evidence];
    }

    /**
     * Earliest membership start across both subscription flavors, with
     * the evidence record. Cashier's subscriptions table has no
     * starts_at column — created_at is the safe proxy for when Paddle
     * first reported the subscription.
     *
     * Only a LocalSubscription can be the evidence pointer:
     * badge_user.evidence_id is a native uuid column (D170) and Cashier
     * subscriptions carry bigint keys. When the earliest support came
     * through Paddle, the Cashier row anchors the tenure clock but the
     * award carries a null evidence pointer rather than a broken one.
     *
     * @return array{0: CarbonInterface, 1: Model|null}|null
     */
    private function earliestMembershipStart(User $user): ?array
    {
        $local = $user->localSubscriptions()
            ->whereNotNull('starts_at')
            ->orderBy('starts_at')
            ->first();

        // The billable relation resolves to a generic Model whose
        // created_at Larastan types as string|null, so the timestamp is
        // fetched as a scalar and normalized here. toBase() skips model
        // hydration on purpose: shouldBeStrict(! production) makes a
        // partially-hydrated model (value() selects only created_at)
        // throw MissingAttributeException — the strict-mode crash this
        // query caused for any Paddle subscriber in dev/test.
        $cashierStartedAt = $this->asCarbon($user->subscriptions()
            ->toBase()
            ->whereNotNull('created_at')
            ->orderBy('created_at')
            ->value('created_at'));

        $localStartedAt = $this->asCarbon($local?->getAttribute('starts_at'));

        if ($localStartedAt === null && $cashierStartedAt === null) {
            return null;
        }

        if ($cashierStartedAt === null || ($localStartedAt !== null && $localStartedAt->lte($cashierStartedAt))) {
            /** @var array{0: CarbonInterface, 1: Model} */
            return [$localStartedAt, $local];
        }

        /** @var array{0: CarbonInterface, 1: Model|null} */
        return [$cashierStartedAt, null];
    }

    /**
     * Normalize a raw model attribute to a Carbon instance — Eloquent
     * hands back either a Carbon or a raw driver string depending on
     * the model's casts, and the billable relation gives no guarantees.
     */
    private function asCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    /**
     * Users in this badge's orbit: anyone with subscription history
     * (local or Cashier, any status — tenure and lapse both key off
     * history). Current holders join through the skeleton's holders
     * half (their rows must lapse or requalify even if subscription
     * rows were pruned).
     */
    protected function qualifyingCohort(Builder $grouped): Builder
    {
        return $grouped->where(function (Builder $query): void {
            $query->whereHas('localSubscriptions')
                ->orWhereHas('subscriptions');
        });
    }

    /**
     * Cashier's subscribed() reads ->subscriptions as a model property
     * (ManagesSubscriptions::subscription()), which is a lazy load under
     * Model::shouldBeStrict. Strict mode only flags models hydrated from
     * MULTI-row result sets (Builder::hydrate stamps preventsLazyLoading
     * when count($items) > 1), so every single-member run masked the
     * violation until a second membership user entered the cohort. The
     * sweep is a bulk path: eager-load the relation instead of relying
     * on the per-user lazy read — this is also the N+1 fix.
     *
     * @return Builder<User>
     */
    protected function baseQuery(): Builder
    {
        // User::query() here must carry the base predicate chain —
        // anonymized users are frozen out of the cohort too. Compose
        // parent::baseQuery() instead of User::query() so this override
        // stays eager-load-only as its contract requires.
        return parent::baseQuery()->with('subscriptions');
    }
}
