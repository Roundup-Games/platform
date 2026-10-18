<?php

namespace App\Console\Commands;

use App\Services\Badges\BadgesRarity as BadgesRarityService;
use Illuminate\Console\Command;

/**
 * Nightly badge rarity entry point (M069 S04, spec §2.4, D195):
 * recomputes the job-owned holders/eligible/rarity columns on every
 * badge_definitions row — the deferred second half of the nightly badge
 * jobs, beside its consumers (the gallery rarity display, D195).
 *
 * Thin by design — the orchestration, failure isolation and structured
 * logging live in the BadgesRarity service twin, so tests and future
 * admin surfaces share one code path. Runs synchronously here; the
 * schedule entry (routes/console.php, daily 06:45 after the 06:30
 * sweep) provides the backgrounding.
 *
 * Deliberately no badges:sync prepend (unlike badges:sweep): the sweep
 * syncs the registry fifteen minutes earlier, and the gallery
 * self-heals on the next night's run.
 */
class BadgesRarity extends Command
{
    protected $signature = 'badges:rarity';

    protected $description = 'Recompute holder/eligible counts and rarity classes for every badge definition';

    public function handle(BadgesRarityService $rarity): int
    {
        $result = $rarity->compute();

        $this->info(sprintf(
            'Badge rarity recomputed: %d definition(s), %d failed.',
            $result['definitions'],
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
