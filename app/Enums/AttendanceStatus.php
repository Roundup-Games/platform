<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Attended = 'attended';
    case NoShow = 'no_show';
    case LateCancel = 'late_cancel';
    case Excused = 'excused';
    case CancelledEarly = 'cancelled_early';

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
            self::Attended => __('attendance.status_attended'),
            self::NoShow => __('attendance.status_no_show'),
            self::LateCancel => __('attendance.status_late_cancel'),
            self::Excused => __('attendance.status_excused'),
            self::CancelledEarly => __('attendance.status_cancelled_early'),
        };
    }
}
