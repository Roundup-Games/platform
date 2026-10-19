<?php

namespace App\Services\Badges;

use App\Models\BadgeUser;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Chartered (spec §3 GM Craft row, D189; live since M070/S04): the
 * campaign-charter milestone — "a charter fully signed by the entire
 * table (at any version)", tiers at 1/10/50 charters, eligibility
 * GameMasters.
 *
 * Qualification semantics: a charter counts when it holds at least one
 * confirmation row with role=gm — ANY status. That single row is the
 * "entire table signed" fact, and its proof chain is airtight:
 *
 * - gm rows are only ever created by CharterSigningService::countersign(),
 *   whose gate requires EVERY approved participant to hold a signed
 *   participant row on that version — or by CharterService::carrySignatures()
 *   copying such a row onto a minor amendment;
 * - a material amendment's resetAgreement() flips the gm row to
 *   pending_reagreement, but the fully-signed-at-that-version FACT stands
 *   immutable — "at any version" per D189 — so the status filter stays
 *   deliberately open and the award can never lapse away under amendment.
 *
 * Count = distinct charter ids across campaigns owned by the user; the
 * tier is the highest threshold (bronze 1 / silver 10 / gold 50, from
 * the registry entry) the count reaches. Evidence is the latest
 * qualifying gm confirmation row (uuid-keyed via HasPlatformUuid, so it
 * satisfies badge_user.evidence_id's native uuid column — MEM1277; morph
 * alias 'session_zero_confirmation' per D170).
 *
 * Milestone semantics (spec §2.1): award and upgrade only, NEVER lapse —
 * sweep() carries no lapse() call at all. evaluate() is a pure proposal
 * (BadgeService owns every write); sweep() is the stateless backfill:
 * everyone whose charters hold a gm row (plus current holders) is
 * re-proposed on the nightly sweep, and repeat sweeps converge through
 * BadgeService's AlreadyHeld/Upgraded decisions. A sweep failure never
 * crashes the run — the orchestrator isolates evaluators (BadgesSweep).
 */
class CharteredEvaluator implements BadgeEvaluator
{
    private const BADGE_KEY = 'chartered';

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
                    // Milestone badges never lapse (spec §2.1): an owner
                    // in the cohort via a held-but-pruned hold parks
                    // nothing — the countersigned fact stands forever.
                    continue;
                }

                [$tierKey, $evidence] = $proposal;

                $service->award($user, self::BADGE_KEY, $tierKey, $evidence);
            }
        });
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The live computation both surfaces share: the tier the signed-
     * charter count has earned plus the latest gm confirmation row as
     * evidence, or null below bronze (no countersigned charter).
     *
     * @return array{0: string, 1: SessionZeroConfirmation}|null
     */
    private function proposalFor(User $user): ?array
    {
        $count = $this->countersignedCharterCount($user);

        $tierKey = null;

        foreach ($this->tierThresholds() as $tier => $threshold) {
            if ($count >= $threshold) {
                // Thresholds ascend, so the last match is the highest earned.
                $tierKey = $tier;
            }
        }

        if ($tierKey === null) {
            return null;
        }

        $evidence = $this->latestGmConfirmation($user);

        // Non-null by construction — a charter only counts because a gm
        // row exists on it — but the guard keeps the tuple's type honest
        // under level-9 analysis (and parks nothing if a row vanished
        // between the count and evidence reads).
        if ($evidence === null) {
            return null;
        }

        return [$tierKey, $evidence];
    }

    /**
     * Distinct charters (kind='charter') on campaigns the user owns that
     * hold at least one role=gm confirmation row — the "fully signed at
     * any version" count. Legacy surveys can never match: they are not
     * charter rows, and their confirmations predate the role column.
     */
    private function countersignedCharterCount(User $user): int
    {
        return SessionZeroSurvey::query()
            ->charter()
            ->whereIn('campaign_id', $user->ownedCampaigns()->select('id'))
            ->whereHas('confirmations', fn (Builder $confirmation): Builder => $confirmation
                ->where('role', SessionZeroConfirmation::ROLE_GM))
            ->distinct()
            ->count('id');
    }

    /**
     * Evidence pointer: the latest role=gm confirmation row across the
     * user's charters, by signature timestamp (uuid-keyed, so the row
     * satisfies badge_user.evidence_id's native uuid column).
     */
    private function latestGmConfirmation(User $user): ?SessionZeroConfirmation
    {
        return SessionZeroConfirmation::query()
            ->where('role', SessionZeroConfirmation::ROLE_GM)
            ->whereIn(
                'session_zero_survey_id',
                SessionZeroSurvey::query()
                    ->charter()
                    ->whereIn('campaign_id', $user->ownedCampaigns()->select('id'))
                    ->select('id'),
            )
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->first();
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
        // "last match wins" tier pick above can never mis-order.
        asort($tiers);

        return $tiers;
    }

    /**
     * Users in this badge's orbit: anyone owning a campaign whose
     * charter holds a gm confirmation row (the count crosses thresholds
     * as tables sign) plus current holders (so a revoked hold
     * requalifies through the audited pipeline).
     *
     * The whole predicate is ONE grouped where for the chunkById
     * reason (see SupportingMemberEvaluator): the cursor's id > last
     * is a top-level AND, and SQL AND binds tighter than OR — an
     * ungrouped cohort predicate would re-match users in every chunk
     * and loop forever.
     *
     * @return Builder<User>
     */
    private function cohort(): Builder
    {
        return User::query()
            ->where(function (Builder $query): void {
                $query->whereHas('ownedCampaigns.charter.confirmations', fn (Builder $confirmation): Builder => $confirmation
                    ->where('role', SessionZeroConfirmation::ROLE_GM))
                    ->orWhereIn('id', BadgeUser::query()
                        ->whereHas('badgeDefinition', fn (Builder $definition) => $definition->where('key', self::BADGE_KEY))
                        ->select('user_id'));
            });
    }
}
