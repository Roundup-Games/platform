<?php

namespace App\Enums;

enum EventType: string
{
    case GameDay = 'game_day';
    case Social = 'social';
    case Convention = 'convention';
    case Other = 'other';

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
            self::GameDay => __('events.label_event_type_game_day'),
            self::Social => __('events.label_event_type_social'),
            self::Convention => __('events.label_event_type_convention'),
            self::Other => __('events.label_event_type_other'),
        };
    }
}
