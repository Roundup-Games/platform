<?php

namespace App\Services\Badges;

use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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
class CharteredEvaluator extends CohortSweepEvaluator
{
    private const BADGE_KEY = 'chartered';

    protected function badgeKey(): string
    {
        return self::BADGE_KEY;
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The live computation both surfaces share: the tier the signed-
     * charter count has earned plus the latest gm confirmation row as
     * evidence, or null below bronze (no countersigned charter).
     *
     * @return array{0: string, 1: SessionZeroConfirmation}|null
     */
    protected function proposalFor(User $user): ?array
    {
        $batch = $this->batchedEvidence($user);

        if ($batch['batched']) {
            /** @var array{count: int, evidence: SessionZeroConfirmation|null} $payload */
            $payload = $batch['evidence'];
            $count = $payload['count'];
            // The evidence pick stays lazy below (??=) — same order as
            // the single-user path, which only fetches it once a tier
            // is reached.
            $evidence = $payload['evidence'];
        } else {
            $count = $this->countersignedCharterCount($user);
            $evidence = null;
        }

        $tierKey = $this->tierFor($count);

        if ($tierKey === null) {
            return null;
        }

        $evidence ??= $this->latestGmConfirmation($user);

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
     * R094 batching: two round trips per chunk instead of two queries
     * per qualifying user —
     *
     * (1) every countersigned charter in the chunk (the same proof
     *     chain as countersignedCharters(), joined to its owner),
     *     feeding both the per-owner distinct count and query (2)'s
     *     scope;
     * (2) the latest gm confirmation per OWNER via DISTINCT ON with
     *     the same (confirmed_at desc, id desc) ordering the
     *     single-user evidence read used.
     *
     * Every chunk user gets an entry (zero counts / null evidence
     * included) so the sweep never falls back to the single-user path.
     */
    protected function prefetchEvidence(EloquentCollection $users): void
    {
        $charters = SessionZeroSurvey::query()
            ->join('campaigns', 'campaigns.id', '=', 'session_zero_surveys.campaign_id')
            ->whereIn('campaigns.owner_id', $users->modelKeys())
            ->whereHas('confirmations', fn (Builder $confirmation): Builder => $confirmation
                ->where('role', SessionZeroConfirmation::ROLE_GM)
                ->whereHas('version', fn (Builder $version): Builder => $version
                    ->whereHas('confirmations', fn (Builder $participant): Builder => $participant
                        ->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT))))
            ->distinct()
            ->toBase()
            ->get(['campaigns.owner_id as owner_id', 'session_zero_surveys.id as survey_id']);

        $charterIdsByOwner = [];
        $ownerBySurveyId = [];

        foreach ($charters as $charter) {
            $charterIdsByOwner[$charter->owner_id][] = $charter->survey_id;
            $ownerBySurveyId[$charter->survey_id] = $charter->owner_id;
        }

        $allCharterIds = array_keys($ownerBySurveyId);

        $latestByOwner = $allCharterIds === [] ? [] : $this->latestGmConfirmationsByOwner($allCharterIds, $ownerBySurveyId);

        foreach ($users as $user) {
            $this->setChunkEvidence($user, [
                'count' => count($charterIdsByOwner[$user->id] ?? []),
                'evidence' => $latestByOwner[$user->id] ?? null,
            ]);
        }
    }

    /**
     * The batched evidence read: the latest gm confirmation per owner
     * across the chunk's countersigned charters — DISTINCT ON keeps the
     * (confirmed_at desc, id desc) pick inside the database, one row
     * per owner, exactly what latestGmConfirmation() returned per user.
     *
     * @param  list<string>  $charterIds  every countersigned charter id in the chunk
     * @param  array<string, string>  $ownerBySurveyId  charter id => owner id
     * @return array<string, SessionZeroConfirmation>
     */
    private function latestGmConfirmationsByOwner(array $charterIds, array $ownerBySurveyId): array
    {
        $confirmations = SessionZeroConfirmation::query()
            ->selectRaw('DISTINCT ON (campaigns.owner_id) session_zero_confirmations.*')
            ->join('session_zero_surveys', 'session_zero_surveys.id', '=', 'session_zero_confirmations.session_zero_survey_id')
            ->join('campaigns', 'campaigns.id', '=', 'session_zero_surveys.campaign_id')
            ->where('session_zero_confirmations.role', SessionZeroConfirmation::ROLE_GM)
            ->whereIn('session_zero_confirmations.session_zero_survey_id', $charterIds)
            ->orderBy('campaigns.owner_id')
            ->orderByDesc('session_zero_confirmations.confirmed_at')
            ->orderByDesc('session_zero_confirmations.id')
            ->get();

        $latestByOwner = [];

        foreach ($confirmations as $confirmation) {
            $ownerId = $ownerBySurveyId[$confirmation->session_zero_survey_id];
            $latestByOwner[$ownerId] = $confirmation;
        }

        return $latestByOwner;
    }

    /**
     * Distinct charters (kind='charter') on campaigns the user owns that
     * hold a role=gm confirmation row on a version that also carries at
     * least one participant confirmation row — the "fully signed at any
     * version" count. Status is deliberately unfiltered: participant rows
     * only ever originate from sign()/carrySignatures() (resetAgreement
     * merely flips a signed row to pending re-agreement — the fact
     * stands, see the material-amendment test), so row EXISTENCE is the
     * signature proof. A gm row alone never counts:
     * CharterSigningService refuses the empty-roster countersign for
     * the same reason (silence is never consent), and this chain keeps
     * the badge honest even against a stale or hand-crafted gm row on a
     * participant-less charter. Legacy surveys can never match: they
     * are not charter rows, and their confirmations predate the role
     * column.
     */
    private function countersignedCharterCount(User $user): int
    {
        return $this->countersignedCharters($user)
            ->distinct()
            ->count('id');
    }

    /**
     * The user's charters carrying the full proof chain — a GM row AND
     * at least one participant signature — as a composable query: the
     * tier count runs a count() over it and the evidence pointer
     * selects its ids, so both surfaces share ONE definition of what
     * counts (the evidence row can never point at a gm confirmation
     * whose charter the count would refuse).
     *
     * @return Builder<SessionZeroSurvey>
     */
    private function countersignedCharters(User $user): Builder
    {
        return SessionZeroSurvey::query()
            ->whereIn('campaign_id', $user->ownedCampaigns()->select('id'))
            ->whereHas('confirmations', fn (Builder $confirmation): Builder => $confirmation
                ->where('role', SessionZeroConfirmation::ROLE_GM)
                ->whereHas('version', fn (Builder $version): Builder => $version
                    ->whereHas('confirmations', fn (Builder $participant): Builder => $participant
                        ->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT))));
    }

    /**
     * Evidence pointer: the latest role=gm confirmation row on one of
     * the user's COUNTERSIGNED charters (the counted proof — see
     * countersignedCharters), by signature timestamp (uuid-keyed, so
     * the row satisfies badge_user.evidence_id's native uuid column).
     */
    private function latestGmConfirmation(User $user): ?SessionZeroConfirmation
    {
        // Scoped to the COUNTED charters (countersignedCharters): the
        // evidence row proves an earned tier, so it must never point at
        // a gm signature on a charter the count itself would refuse.
        return SessionZeroConfirmation::query()
            ->where('role', SessionZeroConfirmation::ROLE_GM)
            ->whereIn('session_zero_survey_id', $this->countersignedCharters($user)->select('id'))
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Owners of charters holding role=gm confirmation rows — the proof
     * chain in countersignedCharterCount() decides what counts; the
     * cohort stays broader (holders plus anyone with a gm row) so
     * re-proposals always reach them.
     */
    protected function qualifyingCohort(Builder $grouped): Builder
    {
        return $grouped->whereHas('ownedCampaigns.charter.confirmations', fn (Builder $confirmation): Builder => $confirmation
            ->where('role', SessionZeroConfirmation::ROLE_GM));
    }
}
