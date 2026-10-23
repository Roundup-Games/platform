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

    /** The gate's own scheduled identity — excluded from its failure count. */
    private const TASK_KEY = 'schedule:health-check';

    protected $description = 'Exit FAILURE when scheduled work missed its due time or last run failed — the red-tile alert behind the Queue Health tile';

    public function handle(): int
    {
        // Fresh compute, not the 5-minute tile cache: a cached verdict can
        // lag the very occurrences this gate is judging.
        ScheduleHealth::flush();
        $summary = ScheduleHealth::summary();

        // Deploy-timing artifacts: missed tasks that have never recorded
        // a run. They stay visible on the Scheduled Tasks page (missed
        // rows) but do not fail this gate — their next occurrence either
        // runs green (artifact confirmed) or graduates to a genuine miss
        // (prior run exists → regression).
        /** @var list<string> $everRan */
        $everRan = ScheduledTaskRun::query()->distinct()->pluck('task_key')->all();
        $missedKeys = $summary['missed_task_keys'];

        // Self-exclusion, both lists: this gate must never fail over its
        // OWN state. Its previous FAILURE is its task's latest row (one
        // failing hour would otherwise poison failed_last forever), and
        // its own uncovered cadence lands in missed-with-history the
        // moment it runs hourly — neither is an independent signal. A
        // truly dead gate shows as a missed schedule:health-check row on
        // the Scheduled Tasks page, same as any other task.
        $missedOthers = array_values(array_diff($missedKeys, [self::TASK_KEY]));
        $regressions = array_values(array_intersect($missedOthers, $everRan));
        $artifacts = array_values(array_diff($missedOthers, $everRan));
        $failedOthers = array_values(array_diff($summary['failed_task_keys'], [self::TASK_KEY]));

        if ($regressions === [] && $failedOthers === [] && $summary['degraded'] === false) {
            $this->info(sprintf(
                'Scheduled work healthy: no regressions, no failing tasks%s.',
                $artifacts === [] ? '' : sprintf(' (%d never-ran task(s) awaiting their first occurrence)', count($artifacts)),
            ));

            return self::SUCCESS;
        }

        Log::error('schedule.health.degraded', [
            'regressed_tasks' => $regressions,
            'never_ran_tasks' => $artifacts,
            'failed_tasks' => $failedOthers,
            'degraded' => $summary['degraded'],
        ]);

        $this->error(sprintf(
            'Scheduled work degraded: %s, %d failed last run%s — see the Scheduled Tasks admin page.',
            $regressions === [] ? 'none' : sprintf('missed [%s]', implode(', ', $regressions)),
            count($failedOthers),
            $summary['degraded'] ? ', schedule inventory failed to load' : '',
        ));

        return self::FAILURE;
    }
}
