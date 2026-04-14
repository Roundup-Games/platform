<?php

namespace App\Enums;

enum ContentLanguage: string
{
    case En = 'en';
    case De = 'de';
    case DeEn = 'de+en';

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
            self::En => 'English',
            self::De => 'German',
            self::DeEn => 'German + English',
        };
    }
}
