<?php

namespace App\Enums;

enum GameType: string
{
    case BoardGame = 'board_game';
    case Ttrpg = 'ttrpg';

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
            self::BoardGame => 'Board Game',
            self::Ttrpg => 'TTRPG',
        };
    }
}
