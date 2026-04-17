<?php

namespace App\Enums;

enum SafetyToolCategory: string
{
    case Before = 'before';
    case During = 'during';
    case After = 'after';

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
            self::Before => 'Before the Game',
            self::During => 'During the Game',
            self::After => 'After the Game',
        };
    }
}
