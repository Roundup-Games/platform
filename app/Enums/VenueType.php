<?php

namespace App\Enums;

enum VenueType: string
{
    case Cafe = 'cafe';
    case Flgs = 'flgs';
    case Library = 'library';
    case CommunityCenter = 'community_center';
    case Convention = 'convention';
    case Bar = 'bar';
    case Other = 'other';

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
            self::Cafe => 'Café',
            self::Flgs => 'FLGS (Friendly Local Game Store)',
            self::Library => 'Library',
            self::CommunityCenter => 'Community Center',
            self::Convention => 'Convention / Convention Center',
            self::Bar => 'Bar / Pub',
            self::Other => 'Other',
        };
    }
}
