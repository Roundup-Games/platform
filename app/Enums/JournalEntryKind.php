<?php

namespace App\Enums;

enum JournalEntryKind: string
{
    case Recap = 'recap';
    case InCharacter = 'in_character';
    case PrepNote = 'prep_note';

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
            self::Recap => __('journal.type_recap'),
            self::InCharacter => __('journal.type_in_character'),
            self::PrepNote => __('journal.type_prep_note'),
        };
    }
}
