<?php

namespace App\Services\Badges;

use App\Models\BadgeUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The shared skeleton for cohort-swept badges (spec §2.2): one audited
 * place for every load-bearing sweep invariant, so a new badge author
 * implements three small hooks instead of copying a 40-line sweep body
 * and re-learning the traps the hard way.
 *
 * The invariants this class owns:
 *
 * - **Grouped cohort predicate** — the cohort is "qualifying users ∪
 *   current holders" inside ONE grouped where. chunkById appends its
 *   id > last cursor as a top-level AND, and SQL AND binds tighter than
 *   OR: an ungrouped cohort predicate re-matches users in every chunk
 *   and the sweep loops forever. Overriding cohort() is therefore
 *   forbidden — override qualifyingCohort()/baseQuery() instead.
 * - **Holders stay in the cohort** — lapsed and revoked holds must keep
 *   re-entering the sweep (lapse/requalify/revocation recovery), even
 *   after their underlying evidence rows were pruned.
 * - **Tier thresholds from the registry** — badges:sync mirrors the
 *   registry's tiers into badge_definitions, so the evaluator and the
 *   write side can never disagree about what a tier means. Thresholds
 *   are declared ascending; asort keeps the "last match wins" pick
 *   honest even if an entry is misordered (Postgres jsonb does not
 *   preserve key order).
 * - **All writes through BadgeService** — evaluators never write
 *   badge_user/badge_events; award() owns the audited, idempotent
 *   pipeline (evaluate() must stay pure — the event pipeline calls it).
 *
 * Hooks a subclass implements:
 *
 * - badgeKey()             — the registry key this badge sweeps for.
 * - qualifyingCohort()     — the signal half of the cohort predicate,
 *                            applied INSIDE the grouped where.
 * - proposalFor()          — [tier, evidence] the user's current signal
 *                            earns, or null (untiered badges propose a
 *                            null tier). Pure: no writes.
 * - prefetchEvidence()     — optional per-chunk evidence prefetch
 *                            (R094): one batched round trip per
 *                            evidence type instead of per-user queries.
 * - sweepOnUnqualified()   — what a null proposal means during sweep.
 *                            Default parks nothing (milestone badges
 *                            never lapse, spec §2.1); lapse-capable
 *                            badges override to park the hold through
 *                            BadgeService::lapse (spec §4).
 * - baseQuery()            — override only to add eager loads the
 *                            proposal needs (see SupportingMember).
 *
 * FoundingTableEvaluator (whole-table window sweep) and NoOpEvaluator
 * deliberately stay outside this class: neither sweeps a
 * qualifying-users ∪ holders cohort.
 */
abstract class CohortSweepEvaluator implements BadgeEvaluator
{
    /**
     * Users per chunkById batch — bounded so a sweep's memory stays flat
     * and progress survives interruptions (resume by re-running).
     */
    private const COHORT_CHUNK_SIZE = 200;

    /**
     * Batched per-user evidence for the chunk currently being swept,
     * keyed by user id (R094). Populated once per chunk by
     * prefetchEvidence(), rebuilt before every chunk and dropped after
     * the sweep — the map never outlives the sweep path, so evaluate()
     * (the MembershipActivated event pipeline) always runs
     * proposalFor()'s fresh single-user queries, byte-identical to the
     * pre-batching code.
     *
     * @var array<string, mixed>
     */
    private array $chunkEvidence = [];

    abstract protected function badgeKey(): string;

    /**
     * The signal half of the cohort predicate: who could newly qualify.
     * Applied INSIDE the grouped where — never add orWhere/whereIn
     * outside it (see the class docblock's chunkById trap).
     *
     * @param  Builder<User>  $grouped  the grouped builder to constrain
     * @return Builder<User>
     */
    abstract protected function qualifyingCohort(Builder $grouped): Builder;

    /**
     * The proposal both surfaces share: the tier (null = untiered/base)
     * and the evidence row the current signal justifies, or null when
     * the user has earned nothing. Pure — no writes.
     *
     * @return array{0: string|null, 1: Model|null}|null
     */
    abstract protected function proposalFor(User $user): ?array;

    /**
     * What a null proposal does during sweep(). Default: nothing —
     * milestone/one-time badges never lapse (spec §2.1). Requalifying
     * badges return their lapse reason to park the hold through
     * BadgeService::lapse (spec §4: lapse honestly).
     *
     * The hook replaces the copy-pasted `$service->lapse(...)` body
     * three evaluators used to carry: the skeleton owns the call, the
     * badge owns only its reason string.
     */
    protected function sweepOnUnqualified(User $user, BadgeService $service): void
    {
        $reason = $this->lapseReason();

        if ($reason !== null) {
            $service->lapse($user, $this->badgeKey(), $reason);
        }
    }

    /**
     * The BadgeService::lapse reason a null proposal parks a hold with,
     * or null when the badge never lapses (milestone/one-time).
     */
    protected function lapseReason(): ?string
    {
        // Parks nothing by design — see the docblock.
        return null;
    }

    /**
     * The cohort's base query. Override only to eager-load relations
     * proposalFor() reads (never to change the predicate shape).
     *
     * @return Builder<User>
     */
    protected function baseQuery(): Builder
    {
        // Anonymized accounts are frozen out of every sweep cohort:
        // erasure means no new badge_user/badge_events rows and no
        // notifications post-erasure. Existing holds are simply never
        // re-evaluated (they cannot lapse or upgrade either — frozen,
        // not parked).
        return User::query()->notAnonymized();
    }

    /**
     * Per-chunk evidence prefetch hook (R094): called once at the top
     * of every chunkById batch, BEFORE proposalFor() runs for any of
     * its users. Override to replace per-user evidence queries with one
     * batched round trip per evidence type (grouped counts keyed by
     * user_id, or Postgres DISTINCT ON for earliest/latest row picks).
     *
     * Contract:
     * - Record an entry for EVERY chunk user — null evidence included
     *   — through setChunkEvidence(); a user WITHOUT an entry falls
     *   back to proposalFor()'s own single-user query, which is exactly
     *   the evaluate()/event-pipeline path and must stay byte-identical.
     * - Qualification and tier math NEVER changes here — only where
     *   proposalFor()'s numbers come from does.
     *
     * @param  Collection<int, User>  $users  the chunk about to be swept
     */
    protected function prefetchEvidence(Collection $users): void
    {
        // Default no-op: evaluators without per-user evidence queries
        // (eager loads via baseQuery(), whole-table windows) batch
        // nothing.
    }

    /**
     * Record one user's batched evidence for the current chunk
     * (null = the batch ran and found nothing).
     */
    final protected function setChunkEvidence(User $user, mixed $evidence): void
    {
        $this->chunkEvidence[$user->id] = $evidence;
    }

    /**
     * The chunk's batched evidence for this user.
     *
     * @return array{batched: bool, evidence: mixed} batched=false means
     *                                               no prefetch covered this user — proposalFor() runs its
     *                                               own single-user query (the evaluate()/event path).
     */
    final protected function batchedEvidence(User $user): array
    {
        if (array_key_exists($user->id, $this->chunkEvidence)) {
            return ['batched' => true, 'evidence' => $this->chunkEvidence[$user->id]];
        }

        return ['batched' => false, 'evidence' => null];
    }

    public function evaluate(User $user): ?BadgeAward
    {
        $proposal = $this->proposalFor($user);

        if ($proposal === null) {
            return null;
        }

        return BadgeAward::for($this->badgeKey(), $proposal[0], $proposal[1]);
    }

    public function sweep(): void
    {
        $service = app(BadgeService::class);

        // Sweep-scoped definition memo (R094): award()/lapse() re-read
        // the badge_definitions row on every call — once per award and
        // once per null-proposal lapse probe — which after evidence
        // batching is the sweep's biggest fixed query cost. The memo
        // lives exactly this evaluator's pass and is flushed in the
        // finally, so an admin is_active/is_dormant toggle converges on
        // the next evaluator's pass.
        $service->beginSweepDefinitionMemo();

        try {
            $this->cohort()->chunkById(self::COHORT_CHUNK_SIZE, function (Collection $users) use ($service): void {
                // R094: one evidence round trip per evidence TYPE per
                // chunk, replacing the per-user evidence queries. The
                // map is rebuilt for every chunk (and dropped after the
                // sweep in the finally), so it can never leak into the
                // evaluate()/event-pipeline path.
                $this->chunkEvidence = [];

                try {
                    $this->prefetchEvidence($users);
                } catch (Throwable $e) {
                    // A broken batch query degrades to the per-user
                    // path instead of aborting the chunk: per-user
                    // isolation (the loop's catch below) is the sweep's
                    // failure contract — a prefetch failure must not
                    // starve the badge's whole tail cohort any more
                    // than a poisoned row may.
                    $this->chunkEvidence = [];

                    Log::error('badges.sweep.prefetch_failed', [
                        'badge' => $this->badgeKey(),
                        'exception' => $e::class.': '.$e->getMessage(),
                    ]);
                }

                foreach ($users as $user) {
                    // Per-user isolation: one poisoned row (a malformed
                    // driver value, a registry/DB drift after a failed sync)
                    // must not abort the chunk loop — chunkById order is
                    // deterministic, so an unguarded throw would starve the
                    // same badge's whole tail cohort every night until the
                    // data is repaired. Skip, log, keep sweeping.
                    try {
                        $proposal = $this->proposalFor($user);

                        if ($proposal === null) {
                            $this->sweepOnUnqualified($user, $service);

                            continue;
                        }

                        [$tierKey, $evidence] = $proposal;

                        $service->award($user, $this->badgeKey(), $tierKey, $evidence);
                    } catch (Throwable $e) {
                        Log::error('badges.sweep.user_failed', [
                            'badge' => $this->badgeKey(),
                            'user_id' => $user->id,
                            'exception' => $e::class.': '.$e->getMessage(),
                        ]);
                    }
                }
            });
        } finally {
            $this->chunkEvidence = [];
            $service->endSweepDefinitionMemo();
        }
    }

    /**
     * The cohort: qualifying users ∪ current holders, ONE grouped where.
     * Final on purpose — the grouping is the invariant (class docblock).
     *
     * @return Builder<User>
     */
    final protected function cohort(): Builder
    {
        return $this->baseQuery()->where(function (Builder $query): void {
            $this->qualifyingCohort($query)
                ->orWhereIn('id', BadgeUser::query()
                    ->whereHas('badgeDefinition', fn (Builder $definition): Builder => $definition
                        ->where('key', $this->badgeKey()))
                    ->select('user_id'));
        });
    }

    /**
     * Tier thresholds from the registry entry — the same array
     * badges:sync mirrors into badge_definitions, so the evaluator and
     * the write side can never disagree about what a tier means.
     *
     * @return array<string, int>
     */
    protected function tierThresholds(): array
    {
        $definition = app(BadgeRegistry::class)->find($this->badgeKey());

        if ($definition === null) {
            throw new LogicException(sprintf('%s is missing from the badge registry', $this->badgeKey()));
        }

        $tiers = $definition->tiers;

        // Thresholds are declared ascending; sort defensively so the
        // "last match wins" tier pick can never mis-order (Postgres
        // jsonb does not preserve key order).
        asort($tiers);

        return $tiers;
    }

    /**
     * The highest tier a count has earned (thresholds ascend, so the
     * last match wins), or null below every threshold.
     */
    protected function tierFor(int $count): ?string
    {
        $tierKey = null;

        foreach ($this->tierThresholds() as $tier => $threshold) {
            if ($count >= $threshold) {
                // Thresholds ascend, so the last match is the highest earned.
                $tierKey = $tier;
            }
        }

        return $tierKey;
    }
}
