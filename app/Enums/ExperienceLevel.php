<?php

namespace App\Enums;

enum ExperienceLevel: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';
    case All = 'all';

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
            self::Beginner => __('games.content_experience_beginner'),
            self::Intermediate => __('games.content_experience_intermediate'),
            self::Advanced => __('games.content_experience_advanced'),
            self::All => __('discovery.content_all_levels'),
        };
    }
}
