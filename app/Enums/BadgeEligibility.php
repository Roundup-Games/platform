<?php

namespace App\Enums;

enum BadgeEligibility: string
{
    case AllUsers = 'all_users';
    case GameMasters = 'game_masters';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
