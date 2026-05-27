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
            self::Scheduled => __('games.status_scheduled'),
            self::Canceled => __('games.status_canceled'),
            self::Completed => __('games.status_completed'),
        };
    }
}
