<?php

namespace App\Services\Badges;

use App\Models\User;

/**
 * The do-nothing evaluator (spec §2.2, D186).
 *
 * One standing use:
 *
 * - **Dormant definitions** (is_dormant: true) carry NoOpEvaluator as
 *   their standing evaluator: the badge is registered so the shape,
 *   tiers and copy exist, but its signal does not ship until a later
 *   milestone swaps in the real evaluator (Chartered → M070, Campaign
 *   Compleat → M071). Evaluating a dormant badge must never award.
 *
 * The former second use — holding the slot for real-but-unwired
 * definitions (Debut, First Seat, Founding Table, S01) — retired in
 * S05 when their evaluators landed; activating a badge is now, and
 * was always meant to be, the one-line registry change.
 */
class NoOpEvaluator implements BadgeEvaluator
{
    public function evaluate(User $user): ?BadgeAward
    {
        return null;
    }

    public function sweep(): void
    {
        // Intentionally empty — there is no cohort to re-evaluate.
    }
}
