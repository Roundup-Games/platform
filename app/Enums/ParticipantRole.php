<?php

namespace App\Enums;

enum ParticipantRole: string
{
    case Owner = 'owner';
    case Player = 'player';
    case Invited = 'invited';
    case Applicant = 'applicant';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
