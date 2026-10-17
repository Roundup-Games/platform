<?php

namespace App\Enums;

enum ScheduledTaskRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Filament/admin badge color for the run outcome. Admin surfaces are
     * EN-only (K002 exemption), so no translation here.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Running => 'warning',
            self::Skipped => 'gray',
        };
    }
}
