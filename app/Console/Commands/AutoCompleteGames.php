<?php

namespace App\Console\Commands;

use App\Jobs\AutoCompleteGames as AutoCompleteGamesJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic command that dispatches the AutoCompleteGames job.
 *
 * Finds games whose scheduled end time plus the auto-complete offset
 * has passed and marks them as completed, opening the attendance
 * reporting window.
 *
 * Runs every 30 minutes via the scheduler.
 */
class AutoCompleteGames extends Command
{
    protected $signature = 'attendance:auto-complete
                            {--dry-run : Show what would be auto-completed without dispatching}';

    protected $description = 'Auto-complete games past their scheduled end + offset';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = now();

        $this->info('Starting auto-complete games...');
        Log::info('auto_complete_games.command.started', ['dry_run' => $dryRun]);

        if ($dryRun) {
            $this->info('Dry run mode — would dispatch AutoCompleteGames job.');
            Log::info('auto_complete_games.command.completed', [
                'dry_run' => true,
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);

            return self::SUCCESS;
        }

        try {
            AutoCompleteGamesJob::dispatch();

            $this->info('AutoCompleteGames job dispatched.');

            Log::info('auto_complete_games.command.completed', [
                'dry_run' => false,
                'job_dispatched' => true,
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);
        } catch (\Throwable $e) {
            $this->error("Failed to dispatch auto-complete games job: {$e->getMessage()}");

            Log::error('auto_complete_games.command.failed', [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
