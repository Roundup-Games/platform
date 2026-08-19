<?php

namespace App\Enums;

enum GameType: string
{
    case BoardGame = 'board_game';
    case Ttrpg = 'ttrpg';
    case Gathering = 'gathering';

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
            self::BoardGame => __('games.type_board_game'),
            self::Ttrpg => __('games.type_ttrpg'),
            self::Gathering => __('games.type_gathering'),
        };
    }
}
