<?php

namespace App\Enums;

enum BadgeMode: string
{
    case OneTime = 'one_time';
    case Milestone = 'milestone';
    case Recurring = 'recurring';
    case Requalifying = 'requalifying';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
