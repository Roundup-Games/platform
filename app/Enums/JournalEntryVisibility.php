<?php

namespace App\Enums;

enum JournalEntryVisibility: string
{
    case Players = 'players';
    case Gms = 'gms';

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
            self::Players => __('journal.visibility_players'),
            self::Gms => __('journal.visibility_gms'),
        };
    }
}
