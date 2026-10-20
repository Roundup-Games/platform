<?php

namespace App\Enums;

enum CharacterStateChangeType: string
{
    case Progression = 'progression';
    case Item = 'item';
    case Condition = 'condition';
    case Note = 'note';

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
            self::Progression => __('journal.type_progression'),
            self::Item => __('journal.type_item'),
            self::Condition => __('journal.type_condition'),
            self::Note => __('journal.type_note'),
        };
    }
}
