<?php

namespace App\Enums;

/**
 * Scheduled-task health classification (M068). Distinguishes "no run yet
 * because recording just started" from "was due after recording started
 * and still did not run" — the difference between an expected day-one
 * state and a real problem.
 */
enum ScheduledTaskHealth: string
{
    /** Latest due occurrence has a recorded run (or is within the grace window). */
    case Healthy = 'healthy';

    /** Due after recording started (or history exists and the latest due time was skipped). */
    case Missed = 'missed';

    /** No history yet, and the latest due time predates the recording epoch. */
    case AwaitingFirstRun = 'awaiting_first_run';

    public function badgeColor(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Missed => 'danger',
            self::AwaitingFirstRun => 'gray',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'On time',
            self::Missed => 'Missed occurrence',
            self::AwaitingFirstRun => 'Awaiting first run',
        };
    }

    /**
     * Whether this state should contribute to "needs attention" counts.
     *
     * @return array{missed: bool, awaiting: bool}
     */
    public function counts(): array
    {
        return match ($this) {
            self::Missed => ['missed' => true, 'awaiting' => false],
            self::AwaitingFirstRun => ['missed' => false, 'awaiting' => true],
            self::Healthy => ['missed' => false, 'awaiting' => false],
        };
    }
}
