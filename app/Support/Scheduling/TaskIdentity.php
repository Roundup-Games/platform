<?php

namespace App\Support\Scheduling;

use Illuminate\Console\Application as ArtisanApp;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;

/**
 * Shared identity for scheduled tasks (2026-10-17, M068).
 *
 * The same resolution logic feeds the Scheduled Tasks admin page (what is
 * due) and RecordScheduledTaskRun (what ran) — one truth so a schedule row
 * and its run history always join on the same key.
 */
final class TaskIdentity
{
    /**
     * Stable key for a schedule event: the bare artisan command signature
     * (e.g. "attendance:resolve") or the description for closure events.
     */
    public static function command(Event $event): string
    {
        $command = $event->command ?? '';

        // Schedule events store "'{phpBinary}' '{artisan}' {command}" (see
        // Application::formatCommandString; artisanBinary() already returns
        // the escaped/quoted token). Strip through the artisan token —
        // options after the command name (e.g. "--window=60") stay, they
        // are part of the task's identity. The old page-side code stripped a
        // prefix that never matched this format, leaking the full PHP path
        // into displayed task names.
        $artisanToken = ArtisanApp::artisanBinary();
        if ($artisanToken !== '' && str_contains($command, $artisanToken)) {
            $command = trim(Str::after($command, $artisanToken));
        }

        if ($command === '') {
            return $event->description !== null && $event->description !== ''
                ? (string) $event->description
                : 'Closure';
        }

        return $command;
    }

    /**
     * Human-readable label for common cron expressions. Admin surfaces are
     * EN-only (K002 exemption).
     */
    public static function humanizeExpression(string $expression): string
    {
        return match ($expression) {
            '* * * * *' => 'Every minute',
            '*/5 * * * *' => 'Every 5 min',
            '*/10 * * * *' => 'Every 10 min',
            '*/15 * * * *' => 'Every 15 min',
            '*/30 * * * *' => 'Every 30 min',
            '0 * * * *' => 'Hourly',
            default => self::humanizeComplexExpression($expression),
        };
    }

    private static function humanizeComplexExpression(string $expression): string
    {
        $parts = explode(' ', $expression);
        if (count($parts) !== 5) {
            return $expression;
        }

        [$min, $hour, $dom, $mon, $dow] = $parts;

        if ($dom === '*' && $mon === '*' && $dow === '*' && $hour !== '*') {
            return sprintf('Daily %02d:%02d', (int) $hour, (int) $min);
        }

        if ($dom === '*' && $mon === '*' && $dow !== '*') {
            $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $day = $dayNames[(int) $dow] ?? $dow;

            return sprintf('%s %02d:%02d', $day, (int) $hour, (int) $min);
        }

        if ($dom !== '*' && $mon === '*' && $dow === '*') {
            return sprintf('Monthly day %s %02d:%02d', $dom, (int) $hour, (int) $min);
        }

        return $expression;
    }
}
