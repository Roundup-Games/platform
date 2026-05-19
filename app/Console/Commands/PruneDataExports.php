<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PruneDataExports extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'exports:prune
                            {--days=7 : Remove exports older than this many days}
                            {--dry-run : Show what would be deleted without removing files}';

    /**
     * The console command description.
     */
    protected $description = 'Prune expired user data export ZIPs older than the retention period';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 1) {
            $this->error('The --days option must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $disk = Storage::disk('local');
        $files = $disk->files('exports');
        $pruned = 0;
        $freedBytes = 0;

        foreach ($files as $file) {
            // Only target export ZIP files
            if (! str_ends_with($file, '.zip')) {
                continue;
            }

            $lastModified = $disk->lastModified($file);

            if ($lastModified === false) {
                continue;
            }

            $fileDate = now()->createFromTimestamp($lastModified);

            if ($fileDate->lt($cutoff)) {
                $size = $disk->size($file);

                if ($dryRun) {
                    $this->line("Would delete: {$file} (".format_bytes($size).')');
                } else {
                    $disk->delete($file);
                }

                $pruned++;
                $freedBytes += $size;
            }
        }

        $verb = $dryRun ? 'would be ' : '';
        $this->info("{$pruned} export file(s) {$verb}pruned (retention: {$days} days)");

        if ($pruned > 0) {
            $this->info("Space {$verb}freed: ".format_bytes($freedBytes));
        }

        Log::info('exports.prune', [
            'pruned' => $pruned,
            'freed_bytes' => $freedBytes,
            'retention_days' => $days,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }

}
