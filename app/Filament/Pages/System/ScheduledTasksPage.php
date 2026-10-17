<?php

namespace App\Filament\Pages\System;

use App\Models\ScheduledTaskRun;
use App\Support\Scheduling\ScheduleHealth;
use App\Support\Scheduling\TaskIdentity;
use Carbon\Carbon;
use Cron\CronExpression;
use Filament\Pages\Page;
use Illuminate\Console\Scheduling\Schedule;
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
        return [
            'tasks' => $this->getTasks(),
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
     * @return list<array{
     *     command: string,
     *     description: string|null,
     *     expression: string,
     *     human_expression: string,
     *     next_run: Carbon,
     *     without_overlapping: bool|int|null,
     *     on_one_server: bool,
     *     last_run: ScheduledTaskRun|null,
     *     last_run_color: string|null,
     *     last_run_ago: string|null,
     *     last_run_duration: string|null,
     *     missed: bool,
     * }>
     */
    public function getTasks(): array
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);
        $events = $schedule->events();
        $now = Carbon::now();

        $latestRuns = ScheduledTaskRun::latestPerTask();

        $tasks = [];

        foreach ($events as $event) {
            $command = TaskIdentity::command($event);
            $cron = new CronExpression($event->expression);
            $lastRun = $latestRuns[$command] ?? null;

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
                'missed' => ScheduleHealth::missed($cron, $now, $lastRun?->started_at),
            ];
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
