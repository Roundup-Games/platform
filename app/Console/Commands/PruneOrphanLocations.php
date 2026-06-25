<?php

namespace App\Console\Commands;

use App\Console\Concerns\ParsesPositiveIntegerOptions;
use App\Models\Location;
use Illuminate\Console\Command;

class PruneOrphanLocations extends Command
{
    use ParsesPositiveIntegerOptions;

    protected $signature = 'locations:prune-orphans
                            {--dry-run : Show what would be deleted without deleting}
                            {--hours=24 : Only delete orphans older than this many hours}';

    protected $description = 'Remove manual-source locations with zero references (games, events, campaigns, users)';

    public function handle(): int
    {
        // Validate up front: hours=0 removes the just-created safety margin,
        // deleting manual locations created moments ago that have not yet been
        // linked to a game/event/campaign (a create→link race window).
        if (! $this->positiveIntegerOption('hours', $hours, 'hours')) {
            return self::FAILURE;
        }
        assert($hours !== null); // the --hours signature default (24) guarantees a value
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subHours($hours);

        $orphans = Location::where('source', 'manual')
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('games')
            ->whereDoesntHave('events')
            ->whereDoesntHave('users')
            ->whereDoesntHave('campaigns');

        $count = $orphans->count();

        if ($count === 0) {
            $this->info('No orphan locations found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[DRY-RUN] Would delete {$count} orphan location(s):");
            $orphans->limit(20)->each(fn (Location $loc) => $this->line(
                "  - {$loc->id} {$loc->name} ({$loc->city}) created {$loc->created_at?->diffForHumans()}"
            ));
            if ($count > 20) {
                $this->line('  ... and '.($count - 20).' more');
            }

            return self::SUCCESS;
        }

        $orphans->each(fn (Location $loc) => $loc->delete());

        $this->info("Deleted {$count} orphan location(s).");

        return self::SUCCESS;
    }
}
