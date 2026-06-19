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

    /**
     * The venue types that count as public commercial venues when verified
     * (D079 / MEM717). The single source of truth for the "commercial venue"
     * predicate — consumed by LocationDisclosureService (in-memory, per card)
     * and Location::scopePublicVenuePage() (query form, for the sitemap and
     * any bulk eligibility check) so the rule can never drift across the
     * disclosure service, the venue 404 gate, <x-venue-link>, and the sitemap.
     *
     * `Other` is intentionally excluded — it does not imply a public space.
     */
    public const COMMERCIAL_TYPES = [
        self::Cafe,
        self::Flgs,
        self::Library,
        self::CommunityCenter,
        self::Convention,
        self::Bar,
    ];

    /**
     * The string column values of {@see COMMERCIAL_TYPES}, for query scopes.
     *
     * Derived from the enum const (not redeclared) so a renamed case value
     * can never desynchronize the query form from the in-memory form.
     *
     * @return list<string>
     */
    public static function commercialValues(): array
    {
        return array_map(fn (self $type) => $type->value, self::COMMERCIAL_TYPES);
    }

    /**
     * True when this type is one of the commercial venue types.
     */
    public function isCommercial(): bool
    {
        return in_array($this, self::COMMERCIAL_TYPES, true);
    }

    public function label(): string
    {
        return __("venue.type_{$this->value}");
    }
}
