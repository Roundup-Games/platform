<?php

namespace App\Enums;

enum GameStatus: string
{
    case Scheduled = 'scheduled';
    case Canceled = 'canceled';
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
            self::Scheduled => __('common.status_scheduled'),
            self::Canceled => __('common.status_cancelled'),
            self::Completed => __('common.status_completed'),
        };
    }
}
