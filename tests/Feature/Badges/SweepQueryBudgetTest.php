<?php

use App\Models\BadgeUser;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Sweep Query Budget (M075/S05/T03 — R094 perf contract)
|--------------------------------------------------------------------------
|
| DemoCohortProofTest is the behavioral contract; this file is the perf
| contract for R094's evidence-query batching (the per-chunk
| prefetchEvidence seam in CohortSweepEvaluator + the sweep-scoped
| badge-definition memo in BadgeService): badges:sync followed by
| badges:sweep on the 25-user toy cohort must stay inside a measured
| statement budget so the per-user N+1 cannot silently return.
|
| Both ceilings are RECALIBRATED from the plan's provisional ≤400 total
| via its own raise-with-evidence path (S05-T01/T02 DB::listen
| measurements on the same cohort shape):
|
|   First cycle (sync + sweep): 773 → 647 measured, ceiling 720. 89% of
|   first-cycle cost is the award-write + notification delivery path
|   (~232 + ~406 statements) — deliberately out of R094's surface — so
|   400 was unreachable by design. 720 sits below the pre-batching floor
|   (758–773 across runs) and above the ±15 award-draw variance on 647;
|   single-seam regressions are the steady-state pin's job.
|
|   Steady state (second sweep — the nightly production shape): 183–189
|   → 87 measured, ceiling 105. With no new awards, the count is exactly
|   what R094 batched: ~11 memoized definition lookups (was 52), ~13
|   chunked evidence queries (was ~58), 11 cohort queries, and the
|   ~52 per-call badge_user lock reads that stay per-user by contract.
|   105 is derived from the single-seam regression floors — evidence
|   batching alone reverting ≈ 132, the memo alone ≈ 128 — so EITHER
|   seam silently regressing trips the pin, while the observed ±6
|   cross-run noise on 87 (183 vs 189 pre-batching) stays below it.
|
| Determinism: counts are code-path-determined for the fixed toy dataset;
| the ceilings absorb award-draw and notification variance. Set
| SWEEP_BUDGET_REPORT=1 to print the measured counts on every run and
| watch the trend before a ceiling trips.
*/

/**
 * Holds (badge_user rows) for one registry key.
 *
 * Local twin of DemoCohortProofTest's demoCohortHoldsFor: helper
 * functions do not cross file boundaries under --parallel (per-process
 * lookup), so each file carries its own.
 */
function sweepBudgetHoldsFor(string $key): int
{
    return BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', $key))
        ->count();
}

beforeEach(function () {
    // Toy-cohort boot, byte-identical to DemoCohortProofTest: the
    // seeder provisions its game systems by slug from its own public
    // constants, then draws the badge cohort fixtures at toy scale.
    seedDemoGameSystems();

    $this->artisan('demo:seed', [
        '--users' => '25',
        '--gms' => '3',
        '--subscribers' => '1',
        '--cohort-only' => true,
    ])->assertSuccessful();
});

it('keeps the badge sweep inside the post-R094 query budget without losing the demo contract', function () {
    // Cheap counting vehicle: a DB::listen counter has no query-log
    // memory overhead, unlike getQueryLog() at hundreds of statements.
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    // First cycle: the exact DemoCohortProofTest backfill — sync, then
    // sweep (the sweep prepends its own sync; both are inside the count).
    $this->artisan('badges:sync')->assertSuccessful();
    $this->artisan('badges:sweep')->assertSuccessful();
    $firstCycle = $queries;

    // Steady state: a second sweep finds nothing new to award or lapse —
    // the nightly production shape, and the purest N+1 signal because no
    // award-path or delivery statements dilute the count.
    $this->artisan('badges:sweep')->assertSuccessful();
    $steadyState = $queries - $firstCycle;

    // Behavioral floor (DemoCohortProofTest remains the full contract):
    // all eight demo badges must still be held. A lost award usually
    // means a per-user sweep error (badges.sweep.user_failed is
    // catch-log-continue by design), and a budget measured on a broken
    // award set is meaningless — so check this BEFORE the ceilings.
    $lost = [];
    foreach ([
        'debut', 'first_seat', 'founding_table', 'still_rolling', 'guild_linked',
        'regulars_table', 'trusted_organizer', 'mentor',
    ] as $key) {
        if (sweepBudgetHoldsFor($key) === 0) {
            $lost[] = $key;
        }
    }
    expect($lost)->toBeEmpty('budget run lost the demo contract: '.implode(', ', $lost));

    // Opt-in drift observability: the ceilings only speak on failure;
    // this prints the measured counts on every opt-in run.
    if (getenv('SWEEP_BUDGET_REPORT') === '1') {
        fwrite(STDERR, "[sweep-budget] first-cycle={$firstCycle} steady-state={$steadyState}\n");
    }

    expect($firstCycle)->toBeLessThanOrEqual(720)
        ->and($steadyState)->toBeLessThanOrEqual(105);
});
