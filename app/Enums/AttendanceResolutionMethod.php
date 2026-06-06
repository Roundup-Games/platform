<?php

namespace App\Enums;

enum AttendanceResolutionMethod: string
{
    case EarlyConsensus = 'early_consensus';
    case Timeout = 'timeout';
    case Manual = 'manual';

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
            self::EarlyConsensus => __('attendance.resolution_method_early_consensus'),
            self::Timeout => __('attendance.resolution_method_timeout'),
            self::Manual => __('attendance.resolution_method_manual'),
        };
    }
}
