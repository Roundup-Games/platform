<?php

namespace App\Enums;

enum Visibility: string
{
    case Public = 'public';
    case Protected = 'protected';
    case Private = 'private';

    /**
     * User-facing label for the visibility level.
     */
    public function label(): string
    {
        return match ($this) {
            self::Public => __('common.content_public'),
            self::Protected => __('common.content_protected'),
            self::Private => __('common.content_private'),
        };
    }

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
