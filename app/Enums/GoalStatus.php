<?php

namespace App\Enums;

enum GoalStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Achieved = 'achieved';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Planned => __('journal.status_planned'),
            self::Active => __('journal.status_active'),
            self::Achieved => __('journal.status_achieved'),
            self::Failed => __('journal.status_failed'),
            self::Abandoned => __('journal.status_abandoned'),
        };
    }
}
