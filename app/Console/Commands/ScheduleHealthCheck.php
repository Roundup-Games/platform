<?php

namespace App\Console\Commands;

use App\Models\ScheduledTaskRun;
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
 * lands in scheduled_task_runs), and exits FAILURE on a REGRESSION — a
 * task that has run before missing its due time, a task whose latest run
 * failed, or the inventory itself failing to load. A failing run flips
 * the tile's failed_last counter red, names the command in the admin
 * page, and writes a structured error log line — no new notification
 * machinery, the observability surface is the alert.
 *
 * Misses by tasks that have NEVER run are reported informationally but
 * do not fail the gate: they are deploy-timing artifacts until their
 * first occurrence (a task shipped after its daily due time reads
 * missed; the Scheduled Tasks page shows those rows regardless). One
 * occurrence separates artifact from broken — a never-running entry
 * surfaces on the page while this gate stays quiet, trading one
 * occurrence of latency for nights that are not spent crying wolf.
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

        // Deploy-timing artifacts: missed tasks that have never recorded
        // a run. They stay visible on the Scheduled Tasks page (missed
        // rows) but do not fail this gate — their next occurrence either
        // runs green (artifact confirmed) or graduates to a genuine miss
        // (prior run exists → regression).
        /** @var list<string> $everRan */
        $everRan = ScheduledTaskRun::query()->distinct()->pluck('task_key')->all();
        $missedKeys = $summary['missed_task_keys'];
        $regressions = array_values(array_intersect($missedKeys, $everRan));
        $artifacts = array_values(array_diff($missedKeys, $everRan));

        if ($regressions === [] && $summary['failed_last'] === 0 && $summary['degraded'] === false) {
            $this->info(sprintf(
                'Scheduled work healthy: no regressions, no failing tasks%s.',
                $artifacts === [] ? '' : sprintf(' (%d never-ran task(s) awaiting their first occurrence)', count($artifacts)),
            ));

            return self::SUCCESS;
        }

        Log::error('schedule.health.degraded', [
            'regressed_tasks' => $regressions,
            'never_ran_tasks' => $artifacts,
            'failed_last' => $summary['failed_last'],
            'degraded' => $summary['degraded'],
        ]);

        $this->error(sprintf(
            'Scheduled work degraded: %s, %d failed last run%s — see the Scheduled Tasks admin page.',
            $regressions === [] ? 'none' : sprintf('missed [%s]', implode(', ', $regressions)),
            $summary['failed_last'],
            $summary['degraded'] ? ', schedule inventory failed to load' : '',
        ));

        return self::FAILURE;
    }
}
