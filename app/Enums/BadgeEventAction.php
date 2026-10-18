<?php

namespace App\Enums;

enum BadgeEventAction: string
{
    case Awarded = 'awarded';
    case Upgraded = 'upgraded';
    case Revoked = 'revoked';
    case GrantedManually = 'granted_manually';
    case Lapsed = 'lapsed';
    case Requalified = 'requalified';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
