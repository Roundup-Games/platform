<?php

namespace App\Enums;

enum BadgeSource: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
