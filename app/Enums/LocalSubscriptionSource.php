<?php

namespace App\Enums;

enum LocalSubscriptionSource: string
{
    case SelfServe = 'self_serve';
    case Comp = 'comp';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
