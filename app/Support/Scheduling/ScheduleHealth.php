<?php

namespace App\Support\Scheduling;

use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled-work health signals (M068/S04): the counts the dashboard
 * Queue Health tile shows, computed from the same run history and
 * missed-occurrence rule the Scheduled Tasks page renders per row.
 */
final class ScheduleHealth
{
    /**
     * Missed occurrences + tasks whose latest run failed, cached 5 minutes
     * (the dashboard polls; the signals move on scheduler ticks).
     *
     * @return array{missed: int, failed_last: int}
     */
    public static function summary(): array
    {
        /** @var array{missed: int, failed_last: int} $summary */
        $summary = Cache::remember('admin:schedule-health', now()->addMinutes(5), fn (): array => self::compute());

        return $summary;
    }

    /**
     * @return array{missed: int, failed_last: int}
     */
    private static function compute(): array
    {
        $latestRuns = ScheduledTaskRun::latestPerTask();
        $now = Carbon::now();

        $missed = 0;
        $failedLast = 0;

        foreach (ScheduleInventory::events() as $event) {
            $run = $latestRuns[TaskIdentity::command($event)] ?? null;

            if (self::missed(new CronExpression($event->expression), $now, $run?->started_at)) {
                $missed++;
            }

            if ($run?->status === ScheduledTaskRunStatus::Failed) {
                $failedLast++;
            }
        }

        return ['missed' => $missed, 'failed_last' => $failedLast];
    }

    /**
     * True when the most recent due occurrence has no recorded run — the
     * scheduler was down, the entry errored before dispatch, or recording
     * was just deployed. A 2-minute grace window absorbs the race between
     * "due this minute" and "scheduler tick not yet fired" so a healthy
     * schedule does not blink red on page load.
     */
    public static function missed(CronExpression $cron, Carbon $now, ?Carbon $lastRunAt): bool
    {
        $previousDue = Carbon::instance($cron->getPreviousRunDate($now->toDateTime(), 0, true));

        if ($previousDue->copy()->addMinutes(2)->isFuture()) {
            return false;
        }

        return $lastRunAt === null || $lastRunAt->lt($previousDue);
    }
}
