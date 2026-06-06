<?php

namespace App\Console\Commands;

use App\Jobs\AttendanceNudgeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic command that dispatches the AttendanceNudgeJob.
 *
 * Finds games whose attendance window closes in ~24h and nudges
 * participants who haven't filed attendance reports yet.
 *
 * Runs every 30 minutes via the scheduler.
 */
class SweepAttendanceNudge extends Command
{
    protected $signature = 'attendance:nudge
                            {--dry-run : Show what would happen without dispatching}';

    protected $description = 'Send 24h attendance reminder nudges to participants who haven\'t filed reports';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = now();

        $this->info('Starting attendance nudge sweep...');
        Log::info('attendance_nudge.command.started', ['dry_run' => $dryRun]);

        if ($dryRun) {
            $this->info('Dry run mode — would dispatch AttendanceNudgeJob.');
            Log::info('attendance_nudge.command.completed', [
                'dry_run' => true,
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);

            return self::SUCCESS;
        }

        try {
            AttendanceNudgeJob::dispatch();

            $this->info('AttendanceNudgeJob dispatched.');

            Log::info('attendance_nudge.command.completed', [
                'dry_run' => false,
                'job_dispatched' => true,
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);
        } catch (\Throwable $e) {
            $this->error("Failed to dispatch attendance nudge job: {$e->getMessage()}");

            Log::error('attendance_nudge.command.failed', [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
