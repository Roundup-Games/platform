<?php

namespace App\Enums;

enum ParticipantStatus: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Pending = 'pending';
    case Waitlisted = 'waitlisted';
    case Benched = 'benched';

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
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Pending => 'Pending',
            self::Waitlisted => 'Waitlisted',
            self::Benched => 'Benched',
        };
    }
}
