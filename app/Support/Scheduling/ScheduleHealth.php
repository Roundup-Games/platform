<?php

namespace App\Support\Scheduling;

use App\Enums\ScheduledTaskHealth;
use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled-work health signals (M068): the counts the dashboard Queue
 * Health tile shows and the per-task states the Scheduled Tasks page
 * renders — one classification, shared.
 *
 * Day-one semantics: a task with no recorded runs whose latest due time
 * predates the recording epoch (the oldest run row) is AwaitingFirstRun —
 * expected right after run tracking is deployed — not Missed. Only a due
 * time that passed AFTER recording started (or a skipped occurrence on a
 * task with history) counts as Missed.
 */
final class ScheduleHealth
{
    /**
     * Missed occurrences, tasks whose latest run failed, and tasks still
     * awaiting their first recorded run. Cached 5 minutes.
     *
     * @return array{missed: int, failed_last: int, awaiting_first: int}
     */
    public static function summary(): array
    {
        /** @var array{missed: int, failed_last: int, awaiting_first: int} $summary */
        $summary = Cache::remember('admin:schedule-health', now()->addMinutes(5), fn (): array => self::compute());

        return $summary;
    }

    /**
     * When run recording started: the oldest run row. Null on a completely
     * fresh install (no rows at all) — everything is then awaiting.
     */
    public static function recordingSince(): ?Carbon
    {
        $oldest = ScheduledTaskRun::query()->min('started_at');

        return $oldest instanceof Carbon ? $oldest : null;
    }

    /**
     * @return array{missed: int, failed_last: int, awaiting_first: int}
     */
    private static function compute(): array
    {
        $latestRuns = ScheduledTaskRun::latestPerTask();
        $recordingSince = self::recordingSince();
        $now = Carbon::now();

        $missed = 0;
        $failedLast = 0;
        $awaitingFirst = 0;

        foreach (ScheduleInventory::events() as $event) {
            $run = $latestRuns[TaskIdentity::command($event)] ?? null;

            $health = self::classify(
                new CronExpression($event->expression),
                $now,
                $run?->started_at,
                $recordingSince,
            );

            $counts = $health->counts();
            $missed += $counts['missed'] ? 1 : 0;
            $awaitingFirst += $counts['awaiting'] ? 1 : 0;

            if ($run?->status === ScheduledTaskRunStatus::Failed) {
                $failedLast++;
            }
        }

        return ['missed' => $missed, 'failed_last' => $failedLast, 'awaiting_first' => $awaitingFirst];
    }

    /**
     * Classify one task's health against its run history.
     *
     * A 2-minute grace window absorbs the race between "due this minute"
     * and "scheduler tick not yet fired", so a healthy schedule does not
     * blink red on page load.
     */
    public static function classify(
        CronExpression $cron,
        Carbon $now,
        ?Carbon $lastRunAt,
        ?Carbon $recordingSince,
    ): ScheduledTaskHealth {
        $previousDue = Carbon::instance($cron->getPreviousRunDate($now->toDateTime(), 0, true));

        if ($previousDue->copy()->addMinutes(2)->isFuture()) {
            return ScheduledTaskHealth::Healthy;
        }

        if ($lastRunAt !== null) {
            return $lastRunAt->lt($previousDue)
                ? ScheduledTaskHealth::Missed
                : ScheduledTaskHealth::Healthy;
        }

        // No history: only a due time that passed after recording started
        // is a genuine miss; one before it simply was never observed.
        return $previousDue->gt($recordingSince ?? $now)
            ? ScheduledTaskHealth::Missed
            : ScheduledTaskHealth::AwaitingFirstRun;
    }
}
