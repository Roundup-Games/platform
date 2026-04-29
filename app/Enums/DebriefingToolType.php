<?php

namespace App\Enums;

enum DebriefingToolType: string
{
    case Debriefing = 'debriefing';
    case StarsAndWishes = 'stars-and-wishes';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
