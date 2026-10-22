<?php

namespace App\Services\Badges;

use App\Models\User;

/**
 * The evaluator contract every badge's grant logic implements (spec §2.2).
 *
 * Two flavors, one interface:
 *
 * - **Event-driven evaluators** answer "did this event cross a threshold?"
 *   BadgePipeline (S02) calls evaluate() against the acting user right
 *   after a domain event (subscription activated, charter signed,
 *   attendance verified). evaluate() must be side-effect-free beyond
 *   returning the proposed award — BadgeService::award() owns all writes,
 *   including the idempotency decision, so evaluators can never double-
 *   award or bypass the audit trail. Return null when the user has earned
 *   nothing for this badge.
 *
 * - **Sweep evaluators** implement sweep() for state the database must be
 *   re-read to know: tenure clocks, streak grace windows, requalification
 *   deadlines, backfills. The nightly scheduler (wired in S02) calls
 *   sweep() across all registered evaluators; inside it, an evaluator
 *   re-evaluates its cohort through BadgeService so awards, lapses and
 *   revocations still flow through the audited pipeline. Cohort-swept
 *   badges extend CohortSweepEvaluator instead of re-implementing the
 *   sweep skeleton and its invariants.
 */
interface BadgeEvaluator
{
    /**
     * Propose an award for this user, or null if they have not earned one.
     *
     * Pure proposal: do not write badge_user/badge_events here — return
     * the BadgeAward and let BadgeService decide and record.
     */
    public function evaluate(User $user): ?BadgeAward;

    /**
     * Cohort re-evaluation for time-driven badges (tenure, streaks,
     * requalification windows, backfills). No-op for purely event-driven
     * evaluators. Wiring lands in S02.
     */
    public function sweep(): void;
}
