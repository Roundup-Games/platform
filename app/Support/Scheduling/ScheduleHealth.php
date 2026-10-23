<?php

namespace App\Support\Scheduling;

use App\Enums\ScheduledTaskHealth;
use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled-work health signals (M068): the counts the dashboard Queue
 * Health tile shows and the per-task states the Scheduled Tasks page
 * renders — one classification, shared.
 *
 * Known artifact: "Missed" counts a task's latest due time regardless of
 * WHEN the task's schedule entry was deployed to this box — a task shipped
 * after its daily due time shows one missed occurrence until its first
 * real run clears it (e.g. badges:sweep deployed at 09:00 reads as having
 * missed that morning's 06:30). Erring loud beats erring quiet; the first
 * occurrence after deploy self-heals the row.
 *
 * Day-one semantics: a task with no recorded runs whose latest due time
 * predates the recording epoch (the oldest run row) is AwaitingFirstRun —
 * expected right after run tracking is deployed — not Missed. Only a due
 * time that passed AFTER recording started counts as Missed; skipped
 * occurrences are excluded from last-executed resolution
 * (ScheduledTaskRun::latestPerTask), so a task stuck behind an overlap
 * mutex surfaces as Missed, never as a Skipped-masked Healthy.
 */
final class ScheduleHealth
{
    /**
     * The summary cache key. Bump the version suffix whenever the
     * cached array's shape changes, so a pre-deploy entry can never be
     * read back as the new shape.
     */
    private const CACHE_KEY = 'admin:schedule-health.v2';

    /**
     * Missed occurrences, tasks whose latest run failed, and tasks still
     * awaiting their first recorded run. Cached 5 minutes — the dashboard
     * tile may therefore lag the Scheduled Tasks page (which computes
     * per-row health live) by up to one TTL; deliberate trade of freshness
     * for a Redis-bound dashboard.
     *
     * A degraded result (the schedule inventory itself failed to load) is
     * distinguishable from a healthy zero and cached for one minute only,
     * so the tile never reports "all clear" for a broken deploy for long.
     *
     * @return array{missed: int, failed_last: int, awaiting_first: int, degraded: bool}
     */
    public static function summary(): array
    {
        // v2: the 'degraded' key joined the shape — an old-shape entry
        // under the v1 key would pass is_array() and hand the widget an
        // array without 'degraded' (undefined-key read). Versioned, not
        // reused: stale entries simply expire unretrieved.
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            /** @var array{missed: int, failed_last: int, awaiting_first: int, degraded: bool} $cached */
            return $cached;
        }

        $summary = self::compute();

        Cache::put(self::CACHE_KEY, $summary, now()->addMinutes($summary['degraded'] ? 1 : 5));

        return $summary;
    }

    /**
     * When run recording started: the oldest run row. Null on a completely
     * fresh install (no rows at all) — everything is then awaiting.
     *
     * The aggregate comes back as a plain datetime string (Postgres,
     * MySQL) — never a Carbon — so it is narrowed and parsed (same shape
     * as RecurrenceService). The old instanceof guard nulled the epoch on
     * every install, making the zero-row missed branch unreachable
     * (MEM1255).
     */
    public static function recordingSince(): ?Carbon
    {
        $oldest = ScheduledTaskRun::query()->min('started_at');

        return is_string($oldest) ? Carbon::parse($oldest) : null;
    }

    /**
     * @return array{missed: int, failed_last: int, awaiting_first: int, degraded: bool}
     */
    private static function compute(): array
    {
        $latestRuns = ScheduledTaskRun::latestPerTask();
        $recordingSince = self::recordingSince();
        $now = Carbon::now();

        $missed = 0;
        $failedLast = 0;
        $awaitingFirst = 0;

        // The per-event resilience rule extends to the inventory load
        // itself: a schedule definition that throws while resolving must
        // degrade the tile to a DEGRADED all-zero result — visibly not a
        // healthy zero (the widget renders it like the Horizon-unreachable
        // branch) — not 500 every page embedding summary(). If
        // schedule:work cannot even load the schedule, the deploy is
        // broken; the log carries the why.
        try {
            $events = ScheduleInventory::events();
        } catch (Throwable $e) {
            logger()->warning('schedule.health.inventory_unavailable', [
                'error' => $e->getMessage(),
            ]);

            return ['missed' => 0, 'failed_last' => 0, 'awaiting_first' => 0, 'degraded' => true];
        }

        foreach ($events as $event) {
            // One malformed entry must never take the whole tile (and any
            // page embedding it) down: a task whose cron cannot even be
            // parsed is broken, so it alerts in the failed bucket while
            // the log carries the diagnostic. Expressions are
            // code-controlled today — this is the observability surface's
            // own resilience rule.
            try {
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
            } catch (Throwable $e) {
                Log::error('schedule.health.task_unclassifiable', [
                    'expression' => $event->expression,
                    'error' => $e->getMessage(),
                ]);

                $failedLast++;
            }
        }

        return ['missed' => $missed, 'failed_last' => $failedLast, 'awaiting_first' => $awaitingFirst, 'degraded' => false];
    }

    /**
     * Classify one task's health against its run history.
     *
     * A 2-minute grace window absorbs the race between "due this minute"
     * and "scheduler tick not yet fired", so a healthy schedule does not
     * blink red on page load. Inside the grace window the judgment falls
     * back to the occurrence BEFORE the most recent one: a task that runs
     * more often than the grace (every-minute automations) would otherwise
     * never age out of the window and be structurally unflaggable, while
     * requiring two unrecorded occurrences keeps a single mid-tick hiccup
     * race-safe for every cadence.
     */
    public static function classify(
        CronExpression $cron,
        Carbon $now,
        ?Carbon $lastRunAt,
        ?Carbon $recordingSince,
    ): ScheduledTaskHealth {
        $previousDue = Carbon::instance($cron->getPreviousRunDate($now->toDateTime(), 0, true));

        // Grace walk: only occurrences at least 2 minutes old are judged,
        // so the race between "due this minute" and "scheduler tick not
        // yet fired" never flags. allowCurrentDate means $previousDue may
        // be "now" itself on matching minutes, so walk back one occurrence
        // at a time (sub-grace cadences take 2-3 steps, every other
        // cadence at most one).
        while ($previousDue->copy()->addMinutes(2)->gt($now)) {
            $previousDue = Carbon::instance($cron->getPreviousRunDate($previousDue, 0, false));
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
