<?php

namespace App\Services\Badges;

use App\Models\BadgeUser;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

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
class SupportingMemberEvaluator implements BadgeEvaluator
{
    private const BADGE_KEY = 'supporting_member';

    private const LAPSE_REASON = 'membership_inactive';

    public function evaluate(User $user): ?BadgeAward
    {
        $proposal = $this->proposalFor($user);

        if ($proposal === null) {
            return null;
        }

        [$tierKey, $evidence] = $proposal;

        return BadgeAward::for(self::BADGE_KEY, $tierKey, $evidence);
    }

    public function sweep(): void
    {
        $service = app(BadgeService::class);

        $this->cohort()->chunkById(200, function ($users) use ($service): void {
            /** @var iterable<User> $users */
            foreach ($users as $user) {
                $proposal = $this->proposalFor($user);

                if ($proposal === null) {
                    // Quiet lapse (spec §4): membership gone → park the
                    // badge. Idempotent — no hold, already lapsed, or
                    // admin-revoked is a no-op inside BadgeService.
                    $service->lapse($user, self::BADGE_KEY, self::LAPSE_REASON);

                    continue;
                }

                [$tierKey, $evidence] = $proposal;

                $service->award($user, self::BADGE_KEY, $tierKey, $evidence);
            }
        });
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The live computation both surfaces share: tier earned plus the
     * evidence model, or null when the user has no active membership.
     *
     * @return array{0: string, 1: Model|null}|null
     */
    private function proposalFor(User $user): ?array
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
        $completedMonths = (int) floor(abs($startedAt->diffInMonths(now())));

        $tierKey = null;

        foreach ($this->tierThresholds() as $tier => $threshold) {
            if ($completedMonths >= $threshold) {
                // Thresholds ascend, so the last match is the highest earned.
                $tierKey = $tier;
            }
        }

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
        // fetched as a scalar and normalized here.
        $cashierStartedAt = $this->asCarbon($user->subscriptions()
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
     * Tier thresholds from the registry entry — the same array
     * badges:sync mirrors into badge_definitions, so the evaluator and
     * the write side can never disagree about what a tier means.
     *
     * @return array<string, int>
     */
    private function tierThresholds(): array
    {
        $definition = app(BadgeRegistry::class)->find(self::BADGE_KEY);

        if ($definition === null) {
            throw new LogicException(sprintf('%s is missing from the badge registry', self::BADGE_KEY));
        }

        $tiers = $definition->tiers;

        // Thresholds are declared ascending; sort defensively so the
        // "last match wins" tier pick below can never mis-order.
        asort($tiers);

        return $tiers;
    }

    /**
     * Users in this badge's orbit: anyone with subscription history
     * (local or Cashier, any status — tenure and lapse both key off
     * history) plus current holders (whose rows must lapse or
     * requalify even if subscription rows were pruned).
     *
     * The whole predicate is ONE grouped where: chunkById appends its
     * id > last cursor as a top-level AND, and SQL AND binds tighter
     * than OR — an ungrouped cohort predicate would re-match users in
     * every chunk and loop forever.
     *
     * @return Builder<User>
     */
    private function cohort(): Builder
    {
        // Cashier's subscribed() reads ->subscriptions as a model property
        // (ManagesSubscriptions::subscription()), which is a lazy load under
        // Model::shouldBeStrict. Strict mode only flags models hydrated from
        // MULTI-row result sets (Builder::hydrate stamps preventsLazyLoading
        // when count($items) > 1), so every single-member run masked the
        // violation until a second membership user entered the cohort. The
        // sweep is a bulk path: eager-load the relation instead of relying
        // on the per-user lazy read — this is also the N+1 fix.
        return User::query()
            ->with('subscriptions')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->whereHas('localSubscriptions')
                        ->orWhereHas('subscriptions');
                })->orWhereIn('id', BadgeUser::query()
                    ->whereHas('badgeDefinition', fn (Builder $definition) => $definition->where('key', self::BADGE_KEY))
                    ->select('user_id'));
            });
    }
}
