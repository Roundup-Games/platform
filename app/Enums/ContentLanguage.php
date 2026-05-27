<?php

namespace App\Enums;

enum ContentLanguage: string
{
    case En = 'en';
    case De = 'de';

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
            self::En => __('common.label_language_en'),
            self::De => __('common.label_language_de'),
        };
    }
}
