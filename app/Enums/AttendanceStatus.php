<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Attended = 'attended';
    case NoShow = 'no_show';
    case LateCancel = 'late_cancel';
    case Excused = 'excused';

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
            self::Attended => 'Attended',
            self::NoShow => 'No Show',
            self::LateCancel => 'Late Cancel',
            self::Excused => 'Excused',
        };
    }
}
