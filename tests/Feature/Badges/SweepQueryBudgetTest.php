<?php

use App\Models\BadgeUser;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
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
|
| Parallel observability: paratest swallows worker STDERR, so the report
| line above is invisible under --parallel. Set
| SWEEP_BUDGET_BREAKDOWN=<path> to append a per-worker block (worker id,
| per-window statement totals, cohort counts, per-window SQL histograms)
| to that file BEFORE any assertion — env vars propagate to paratest
| workers, making the file the only channel that survives the parallel
| gate (M075/S07).
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

/**
 * Histogram lines ("sql: <verb> <table> = N") for one counting window.
 *
 * Groups each statement by its leading SQL keyword (lowercased) plus the
 * first table token after from/into/update (quotes stripped, lowercased),
 * counted and sorted descending — the shape the SWEEP_BUDGET_BREAKDOWN
 * file consumes to attribute budget inflation to a verb/table pair.
 * Transaction-control statements (begin, commit, savepoint, ...) carry
 * no table and group under the verb alone; statements with no known
 * leading verb are dropped from the histogram (the window's statement
 * total remains the source of truth).
 *
 * @param  list<string>  $statements
 * @return list<string>
 */
function sweepBudgetHistogramLines(array $statements): array
{
    $buckets = [];
    foreach ($statements as $sql) {
        if (! preg_match('/^(select|insert|update|delete|begin|commit|rollback|savepoint|release)\b/i', $sql, $verbMatch)) {
            continue;
        }

        $verb = strtolower($verbMatch[1]);
        $table = preg_match('/\b(?:from|into|update)\s+["`]?([a-zA-Z0-9_.\-]+)["`]?/i', $sql, $tableMatch)
            ? strtolower($tableMatch[1])
            : null;

        $key = $verb.($table !== null ? ' '.$table : '');
        $buckets[$key] = ($buckets[$key] ?? 0) + 1;
    }

    arsort($buckets);

    return array_map(
        fn (int $count, string $key): string => "sql: {$key} = {$count}",
        array_values($buckets),
        array_keys($buckets),
    );
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
    // $sqlLog mirrors every statement string so the breakdown file can
    // histogram each counting window — the only diagnostic channel that
    // survives --parallel, where paratest swallows worker stderr.
    $queries = 0;
    $sqlLog = [];
    DB::listen(function (QueryExecuted $event) use (&$queries, &$sqlLog): void {
        $queries++;
        $sqlLog[] = $event->sql;
    });

    // First cycle: the exact DemoCohortProofTest backfill — sync, then
    // sweep (the sweep prepends its own sync; both are inside the count).
    $this->artisan('badges:sync')->assertSuccessful();
    $this->artisan('badges:sweep')->assertSuccessful();
    $firstCycle = $queries;
    $firstEnd = count($sqlLog);

    // Steady state: a second sweep finds nothing new to award or lapse —
    // the nightly production shape, and the purest N+1 signal because no
    // award-path or delivery statements dilute the count.
    $this->artisan('badges:sweep')->assertSuccessful();
    $steadyState = $queries - $firstCycle;
    $steadyEnd = count($sqlLog);

    // Cohort counts feed the breakdown file and the purity guard below;
    // they run after both windows are snapshotted so they pollute
    // neither budget.
    $usersTotal = User::query()->count();
    $usersNotAnonymized = User::query()->whereNull('anonymized_at')->count();
    $badgeUserTotal = BadgeUser::query()->count();

    // File-based breakdown: appended (multiple workers share the file)
    // and written BEFORE any assertion, so red runs — expected while
    // diagnosing the parallel-gate inflation — still produce it.
    $breakdownPath = getenv('SWEEP_BUDGET_BREAKDOWN');
    if ($breakdownPath !== false && $breakdownPath !== '') {
        $workerToken = getenv('TEST_TOKEN');
        $worker = $workerToken !== false && $workerToken !== '' ? $workerToken : 'standalone';
        $block = [
            '## sweep-budget-breakdown '.date('Y-m-d\TH:i:s'),
            "worker: {$worker}",
            'window: first-cycle',
            "statements: {$firstCycle}",
            'window: steady-state',
            "statements: {$steadyState}",
            "users_total_after: {$usersTotal}",
            "users_not_anonymized_after: {$usersNotAnonymized}",
            "badge_user_after: {$badgeUserTotal}",
            'histogram: first-cycle',
            ...sweepBudgetHistogramLines(array_slice($sqlLog, 0, $firstEnd)),
            'histogram: steady-state',
            ...sweepBudgetHistogramLines(array_slice($sqlLog, $firstEnd, $steadyEnd - $firstEnd)),
            '',
        ];

        $directory = dirname($breakdownPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($breakdownPath, implode("\n", $block)."\n", FILE_APPEND);
    }

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

    // Cohort purity: the demo cohort seeds exactly 25 users (--users=25,
    // byte-identical boot to DemoCohortProofTest). Anything above 25 can
    // only be foreign rows committed into this worker's shared parallel
    // database — the silent budget inflator this guard makes loud and
    // attributable before the ceilings mask its source.
    expect($usersTotal)->toBe(25, sprintf(
        'cohort purity violated: demo:seed --users=25 expected exactly 25 users, found %d — %d foreign committed user row(s) leaked onto the shared parallel DB, inflating the query budget',
        $usersTotal,
        $usersTotal - 25,
    ));

    // Opt-in drift observability: the ceilings only speak on failure;
    // this prints the measured counts on every opt-in run.
    if (getenv('SWEEP_BUDGET_REPORT') === '1') {
        fwrite(STDERR, "[sweep-budget] first-cycle={$firstCycle} steady-state={$steadyState}\n");
    }

    expect($firstCycle)->toBeLessThanOrEqual(720)
        ->and($steadyState)->toBeLessThanOrEqual(105);
});
