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

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Player => 'Player',
            self::Invited => 'Invited',
            self::Applicant => 'Applicant',
        };
    }

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    public function isPlayer(): bool
    {
        return $this === self::Player;
    }
}
