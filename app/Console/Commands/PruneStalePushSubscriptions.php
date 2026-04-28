<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneStalePushSubscriptions extends Command
{
    protected $signature = 'pwa:prune-stale-subscriptions
                            {--dry-run : Show what would be deleted without deleting}
                            {--max-age=180 : Delete subscriptions not updated in N days}';

    protected $description = 'Remove stale push subscriptions from inactive devices';

    public function handle(): int
    {
        $maxAge = (int) $this->option('max-age');
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subDays($maxAge);

        $query = PushSubscription::where('updated_at', '<', $cutoff);

        $count = $dryRun ? $query->count() : $query->delete();

        $this->info($dryRun
            ? "Would delete {$count} stale push subscription(s) (not updated in {$maxAge}+ days)"
            : "Deleted {$count} stale push subscription(s) (not updated in {$maxAge}+ days)"
        );

        Log::channel('daily')->info('pwa.subscriptions.pruned', [
            'count' => $count,
            'max_age_days' => $maxAge,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
