<?php

namespace App\Enums;

enum DebriefingToolType: string
{
    case Debriefing = 'debriefing';
    case StarsAndWishes = 'stars-and-wishes';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
