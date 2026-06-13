<?php

namespace App\Console\Commands;

use App\Models\UserAppVisit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneOldVisits extends Command
{
    protected $signature = 'pwa:prune-visits
                            {--dry-run : Show what would be deleted without deleting}
                            {--max-age=90 : Delete visit records older than N days}';

    protected $description = 'Remove old app visit tracking data beyond what is needed for eligibility';

    public function handle(): int
    {
        $maxAge = (int) $this->option('max-age');
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subDays($maxAge)->toDateString();

        $query = UserAppVisit::where('visit_date', '<', $cutoff);

        $rawCount = $dryRun ? $query->count() : $query->delete();
        $count = is_numeric($rawCount) ? (int) $rawCount : 0;

        $this->info($dryRun
            ? "Would delete {$count} old visit record(s) (older than {$maxAge} days)"
            : "Deleted {$count} old visit record(s) (older than {$maxAge} days)"
        );

        Log::channel('daily')->info('pwa.visits.pruned', [
            'count' => $count,
            'max_age_days' => $maxAge,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
