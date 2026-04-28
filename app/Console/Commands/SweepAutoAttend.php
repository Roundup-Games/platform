<?php

namespace App\Console\Commands;

use App\Jobs\AutoAttendAfter48Hours;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic sweep that dispatches the auto-attend job for eligible games.
 *
 * Finds completed games older than 48h with unreported participants and
 * dispatches the AutoAttendAfter48Hours queued job.
 * Runs every 30 minutes via the scheduler.
 */
class SweepAutoAttend extends Command
{
    protected $signature = 'attendance:sweep-auto-attend
                            {--dry-run : List eligible games without dispatching}';

    protected $description = 'Dispatch auto-attend job for completed games older than 48 hours';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = now();

        $this->info('Starting auto-attend sweep...');
        Log::info('auto_attend.sweep.started', ['dry_run' => $dryRun]);

        if ($dryRun) {
            $this->info('Dry run mode — would dispatch AutoAttendAfter48Hours job.');
            Log::info('auto_attend.sweep.completed', [
                'dry_run' => true,
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);

            return self::SUCCESS;
        }

        try {
            AutoAttendAfter48Hours::dispatch();

            $this->info('AutoAttendAfter48Hours job dispatched.');

            Log::info('auto_attend.sweep.completed', [
                'dry_run' => false,
                'job_dispatched' => true,
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);
        } catch (\Throwable $e) {
            $this->error("Failed to dispatch auto-attend job: {$e->getMessage()}");

            Log::error('auto_attend.sweep.failed', [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
