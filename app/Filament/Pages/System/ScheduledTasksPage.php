<?php

namespace App\Filament\Pages\System;

use App\Enums\ScheduledTaskHealth;
use App\Models\ScheduledTaskRun;
use App\Support\Scheduling\ScheduleHealth;
use App\Support\Scheduling\ScheduleInventory;
use App\Support\Scheduling\TaskIdentity;
use Carbon\Carbon;
use Cron\CronExpression;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use UnitEnum;

class ScheduledTasksPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Scheduled Tasks';

    protected static ?string $navigationLabel = 'Scheduled Tasks';

    protected string $view = 'filament.pages.system.scheduled-tasks';

    public function getViewData(): array
    {
        // recordingSince() is an uncached min(started_at) aggregate —
        // computed ONCE here and threaded into getTasks() instead of
        // being re-run there (it was queried twice per render).
        $recordingSince = ScheduleHealth::recordingSince();
        $tasks = $this->getTasks($recordingSince);

        return [
            'tasks' => $tasks,
            'health' => ScheduleHealth::summary(),
            'recording_since' => $recordingSince,
            'total_tasks' => count($tasks),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * The schedule (what is due and when) joined with the recorded run
     * history (what actually ran and how it ended). One row per schedule
     * event, keyed by the shared TaskIdentity command string.
     *
     * @param  ?Carbon  $recordingSince  Pre-computed epoch (see
     *                                   getViewData) — null keeps the
     *                                   query local for non-page callers.
     * @return list<array{
     *     command: string,
     *     description: string|null,
     *     expression: string,
     *     human_expression: string,
     *     next_run: Carbon|null,
     *     without_overlapping: bool|int|null,
     *     on_one_server: bool,
     *     last_run: ScheduledTaskRun|null,
     *     last_run_color: string|null,
     *     last_run_ago: string|null,
     *     last_run_duration: string|null,
     *     health: ScheduledTaskHealth,
     * }>
     */
    public function getTasks(?Carbon $recordingSince = null): array
    {
        // Same honesty rule as ScheduleHealth::compute(): an inventory
        // that cannot load must degrade the page to the degraded banner
        // (summary() reports degraded=true), never 500 the primary
        // diagnostic page of a broken deploy.
        try {
            $events = ScheduleInventory::events();
        } catch (\Throwable $e) {
            // Degraded banner, never a 500 — but report() so a deploy
            // whose inventory cannot load is visible in telemetry, not
            // only in the banner nobody watches at 3am.
            report($e);
            Log::error('schedule.tasks_page.inventory_unavailable', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $now = Carbon::now();

        $latestRuns = ScheduledTaskRun::latestPerTask();
        $recordingSince ??= ScheduleHealth::recordingSince();

        $tasks = [];

        foreach ($events as $event) {
            // TaskIdentity::command() resolves inside the same resilience
            // rule as the cron parse: a throwing identity resolution (an
            // artisanBinary() edge, a malformed event) degrades this row,
            // not the whole diagnostic page the banner was built to save.
            $command = null;

            try {
                $command = TaskIdentity::command($event);
                $lastRun = $latestRuns[$command] ?? null;

                // Same resilience rule as ScheduleHealth::compute(): one
                // malformed expression must not take the whole diagnostic
                // page down — the row degrades to a missed-health entry and
                // the log carries the diagnostic. An unparseable schedule
                // never runs, so "missed" is also the honest classification.
                $cron = new CronExpression($event->expression);

                $tasks[] = [
                    'command' => $command,
                    'description' => ($event->description ?? null) ?: null,
                    'expression' => $event->expression,
                    'human_expression' => TaskIdentity::humanizeExpression($event->expression),
                    'next_run' => Carbon::instance($cron->getNextRunDate($now->toDateTime(), 0, true)),
                    'without_overlapping' => $event->withoutOverlapping ?: null,
                    'on_one_server' => (bool) $event->onOneServer,
                    'last_run' => $lastRun,
                    'last_run_color' => $lastRun?->status->badgeColor(),
                    'last_run_ago' => $lastRun?->started_at->diffForHumans(),
                    'last_run_duration' => $this->formatDuration($lastRun?->runtime_ms),
                    'health' => ScheduleHealth::classify($cron, $now, $lastRun?->started_at, $recordingSince),
                ];
            } catch (\Throwable $e) {
                $command ??= ($event->description ?? null) ?: 'unknown';
                $lastRun = $latestRuns[$command] ?? null;

                Log::error('schedule.tasks_page.task_unclassifiable', [
                    'expression' => $event->expression,
                    'error' => $e->getMessage(),
                ]);

                $tasks[] = [
                    'command' => $command,
                    'description' => ($event->description ?? null) ?: null,
                    'expression' => $event->expression,
                    'human_expression' => TaskIdentity::humanizeExpression($event->expression),
                    'next_run' => null,
                    'without_overlapping' => $event->withoutOverlapping ?: null,
                    'on_one_server' => (bool) $event->onOneServer,
                    'last_run' => $lastRun,
                    'last_run_color' => $lastRun?->status->badgeColor(),
                    'last_run_ago' => $lastRun?->started_at->diffForHumans(),
                    'last_run_duration' => $this->formatDuration($lastRun?->runtime_ms),
                    'health' => ScheduledTaskHealth::Missed,
                ];
            }
        }

        usort($tasks, fn (array $a, array $b): int => strcmp($a['command'], $b['command']));

        return $tasks;
    }

    /**
     * Human duration for the admin (EN-only surface). Null when the
     * framework does not time the run (background finishes) or it was
     * never recorded.
     */
    private function formatDuration(?int $runtimeMs): ?string
    {
        if ($runtimeMs === null) {
            return null;
        }

        $seconds = $runtimeMs / 1000;

        if ($seconds < 60) {
            return round($seconds, 1).'s';
        }

        $minutes = (int) floor($seconds / 60);

        return sprintf('%dm %ds', $minutes, (int) round($seconds - $minutes * 60));
    }
}
