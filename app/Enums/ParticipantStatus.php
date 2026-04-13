<?php

namespace App\Enums;

enum ParticipantStatus: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Pending = 'pending';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
