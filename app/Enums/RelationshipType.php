<?php

namespace App\Enums;

enum RelationshipType: string
{
    case Follow = 'follow';
    case Block = 'block';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
