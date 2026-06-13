<?php

namespace App\Dto;

/**
 * A single venue-search result.
 *
 * Combines verified-location display fields with an optional proximity
 * distance (null when no coordinates were supplied). Produced by
 * VenueSearchService::search() and consumed by the venue-picker UIs.
 */
final readonly class VenueSearchResult
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $city,
        public ?string $address,
        public ?string $venueType,
        public ?float $distanceKm,
    ) {}
}
