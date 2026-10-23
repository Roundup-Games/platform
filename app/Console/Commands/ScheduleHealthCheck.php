<?php

namespace App\Console\Commands;

use App\Support\Scheduling\ScheduleHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled-work health gate (M068 follow-up, 2026-10-23 incident).
 *
 * The Queue Health tile and the Scheduled Tasks page compute degraded
 * schedule health passively — but only for someone looking. This command
 * turns that same signal into an ACTIVE alert through the pipes that
 * already exist: it runs hourly, in the FOREGROUND (so its own exit code
 * lands in scheduled_task_runs), and exits FAILURE when scheduled work
 * missed its due time, last run failed, or the inventory itself could not
 * load. A failing run flips the tile's failed_last counter red, names the
 * command in the admin page, and writes a structured error log line — no
 * new notification machinery, the observability surface is the alert.
 *
 * The worker container's s6 watchdog covers process health (a wedged
 * Horizon gets the container restarted); this covers work health (the
 * scheduler alive but occurrences going unrecorded).
 */
class ScheduleHealthCheck extends Command
{
    protected $signature = 'schedule:health-check';

    protected $description = 'Exit FAILURE when scheduled work missed its due time or last run failed — the red-tile alert behind the Queue Health tile';

    public function handle(): int
    {
        $summary = ScheduleHealth::summary();

        if ($summary['missed'] === 0 && $summary['failed_last'] === 0 && $summary['degraded'] === false) {
            $this->info('Scheduled work healthy: nothing missed, no failing tasks.');

            return self::SUCCESS;
        }

        Log::error('schedule.health.degraded', $summary);

        $this->error(sprintf(
            'Scheduled work degraded: %d missed, %d failed last run%s — see the Scheduled Tasks admin page.',
            $summary['missed'],
            $summary['failed_last'],
            $summary['degraded'] ? ', schedule inventory failed to load' : '',
        ));

        return self::FAILURE;
    }
}
