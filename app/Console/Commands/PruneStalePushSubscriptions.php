<?php

namespace App\Console\Commands;

use App\Console\Concerns\ParsesPositiveIntegerOptions;
use App\Models\PushSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneStalePushSubscriptions extends Command
{
    use ParsesPositiveIntegerOptions;

    protected $signature = 'pwa:prune-stale-subscriptions
                            {--dry-run : Show what would be deleted without deleting}
                            {--max-age=180 : Delete subscriptions not updated in N days}';

    protected $description = 'Remove stale push subscriptions from inactive devices';

    public function handle(): int
    {
        // Validate up front: max-age=0 must fail fast rather than coerce to 0.
        // subDays(0) == now(), so `updated_at < now` matches every row and
        // delete() wipes the whole table — an unrecoverable mass-delete from a
        // typo'd option.
        if (! $this->positiveIntegerOption('max-age', $maxAge, 'days')) {
            return self::FAILURE;
        }
        assert($maxAge !== null); // the --max-age signature default (180) guarantees a value
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subDays($maxAge);

        $query = PushSubscription::where('updated_at', '<', $cutoff);

        $rawCount = $dryRun ? $query->count() : $query->delete();
        $count = is_numeric($rawCount) ? (int) $rawCount : 0;

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
