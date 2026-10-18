<?php

namespace App\Console\Commands;

use App\Services\Badges\BadgesSweep as BadgesSweepService;
use Illuminate\Console\Command;

/**
 * Nightly badge sweep entry point (M069 S02, spec §2.2): syncs the
 * registry, then runs every registered evaluator's sweep() (tenure
 * tier crossings, streak recomputes, quiet lapses, backfills).
 *
 * Thin by design — the orchestration, failure isolation and structured
 * logging live in the BadgesSweep service twin, so tests and future
 * admin surfaces share one code path. Runs synchronously here; the
 * schedule entry (routes/console.php, daily 06:30) provides the
 * backgrounding.
 *
 * Deliberately NO --dry-run: the sweep is idempotent end-to-end
 * through BadgeService's deduplicating pipeline, and a dry-run built
 * from evaluate() proposals would mislead — proposals are not outcomes
 * (BadgeService may AlreadyHeld / upgrade / refuse), so reporting the
 * former as the latter would show phantom awards. Re-running the real
 * sweep converges without writing anything new.
 */
class BadgesSweep extends Command
{
    protected $signature = 'badges:sweep';

    protected $description = 'Sync the badge registry, then run every registered evaluator\'s sweep (tenure tiers, streaks, lapses, backfills)';

    public function handle(BadgesSweepService $sweeper): int
    {
        $result = $sweeper->sweep();

        if ($result['sync_failed']) {
            $this->error('badges:sync failed — sweeping the (possibly stale) registry mirror.');
        }

        $this->info(sprintf(
            'Badge sweep finished: %d evaluator(s) run, %d failed.',
            $result['evaluators'],
            $result['failed'],
        ));

        foreach ($result['failures'] as $failure) {
            $this->error(sprintf(
                '  [%s] %s: %s',
                $failure['badge'],
                $failure['exception'],
                $failure['message'],
            ));
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
