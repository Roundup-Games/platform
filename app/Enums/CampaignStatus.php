<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

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
            self::Active => __('common.status_active'),
            self::Cancelled => __('common.status_cancelled'),
            self::Completed => __('common.status_completed'),
        };
    }
}
