<?php

namespace App\Filament\Pages\System;

use Carbon\Carbon;
use Cron\CronExpression;
use Filament\Pages\Page;
use Illuminate\Console\Application as ArtisanApp;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use UnitEnum;

class ScheduledTasksPage extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clock';

    protected static string | UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Scheduled Tasks';

    protected static ?string $navigationLabel = 'Scheduled Tasks';

    protected string $view = 'filament.pages.system.scheduled-tasks';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<int, array{
     *     command: string,
     *     description: string|null,
     *     expression: string,
     *     human_expression: string,
     *     next_run: \Carbon\Carbon,
     *     without_overlapping: int|null,
     *     on_one_server: bool,
     * }>
     */
    public function getTasks(): array
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);
        $events = $schedule->events();
        $now = Carbon::now();

        $tasks = [];

        foreach ($events as $event) {
            $command = $this->resolveCommand($event);
            $cron = new CronExpression($event->expression);

            $tasks[] = [
                'command' => $command,
                'description' => $event->description ?: null,
                'expression' => $event->expression,
                'human_expression' => $this->humanizeExpression($event->expression),
                'next_run' => Carbon::instance($cron->getNextRunDate($now->toDateTime(), 0, true)),
                'without_overlapping' => $event->withoutOverlapping ?: null,
                'on_one_server' => (bool) ($event->onOneServer ?? false),
            ];
        }

        usort($tasks, fn (array $a, array $b): int => strcmp($a['command'], $b['command']));

        return $tasks;
    }

    /**
     * @return array{pending: int, failed: int, completed: int}
     */
    public function getStats(): array
    {
        /** @var JobRepository $jobs */
        $jobs = app(JobRepository::class);

        return [
            'pending' => $jobs->countPending(),
            'failed' => $jobs->countFailed(),
            'completed' => $jobs->countCompleted(),
        ];
    }

    /**
     * Resolve the artisan command from the event's command string.
     */
    private function resolveCommand(object $event): string
    {
        $command = $event->command ?? '';

        if (Str::startsWith($command, "'".ArtisanApp::artisanBinary()."'")) {
            $command = trim(Str::after($command, ArtisanApp::artisanBinary()."'"), " '");
        }

        // Closure-based events — use the description as the identifier
        if (empty($command)) {
            return $event->description ?: 'Closure';
        }

        return $command;
    }

    /**
     * Convert common cron expressions to human-readable labels.
     */
    private function humanizeExpression(string $expression): string
    {
        return match ($expression) {
            '* * * * *' => 'Every minute',
            '*/5 * * * *' => 'Every 5 min',
            '*/10 * * * *' => 'Every 10 min',
            '*/15 * * * *' => 'Every 15 min',
            '*/30 * * * *' => 'Every 30 min',
            '0 * * * *' => 'Hourly',
            default => $this->humanizeComplexExpression($expression),
        };
    }

    /**
     * Attempt to describe less common cron patterns.
     */
    private function humanizeComplexExpression(string $expression): string
    {
        $parts = explode(' ', $expression);
        if (count($parts) !== 5) {
            return $expression;
        }

        [$min, $hour, $dom, $mon, $dow] = $parts;

        // Daily at specific time
        if ($dom === '*' && $mon === '*' && $dow === '*' && $hour !== '*') {
            $time = sprintf('%02d:%02d', (int) $hour, (int) $min);

            return "Daily {$time}";
        }

        // Weekly on specific day
        if ($dom === '*' && $mon === '*' && $dow !== '*') {
            $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $day = $dayNames[(int) $dow] ?? $dow;
            $time = sprintf('%02d:%02d', (int) $hour, (int) $min);

            return "{$day} {$time}";
        }

        // Monthly
        if ($dom !== '*' && $mon === '*' && $dow === '*') {
            $time = sprintf('%02d:%02d', (int) $hour, (int) $min);

            return "Monthly day {$dom} {$time}";
        }

        return $expression;
    }
}
