<?php

namespace App\Console\Commands;

use App\Jobs\ResolveAttendance as ResolveAttendanceJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic command that dispatches the ResolveAttendance job.
 *
 * Finds games whose attendance reporting window has closed but whose
 * attendance has not yet been resolved, and resolves them via the
 * consensus engine.
 *
 * Runs every 30 minutes via the scheduler.
 */
class ResolveAttendance extends Command
{
    protected $signature = 'attendance:resolve
                            {--dry-run : Show what would be resolved without dispatching}';

    protected $description = 'Resolve attendance for games with expired reporting windows';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = now();

        $this->info('Starting attendance resolution...');
        Log::info('resolve_attendance.command.started', ['dry_run' => $dryRun]);

        if ($dryRun) {
            $this->info('Dry run mode — would dispatch ResolveAttendance job.');
            Log::info('resolve_attendance.command.completed', [
                'dry_run' => true,
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);

            return self::SUCCESS;
        }

        try {
            ResolveAttendanceJob::dispatch();

            $this->info('ResolveAttendance job dispatched.');

            Log::info('resolve_attendance.command.completed', [
                'dry_run' => false,
                'job_dispatched' => true,
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);
        } catch (\Throwable $e) {
            $this->error("Failed to dispatch resolve attendance job: {$e->getMessage()}");

            Log::error('resolve_attendance.command.failed', [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
