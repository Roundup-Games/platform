<?php

namespace App\Enums;

enum Visibility: string
{
    case Public = 'public';
    case Protected = 'protected';
    case Private = 'private';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Validation rule string for visibility fields.
     *
     * Usage: 'visibility' => Visibility::validationRule(),
     */
    public static function validationRule(): string
    {
        return 'required|in:' . implode(',', self::values());
    }
}
