<?php

namespace App\Dto;

use App\Services\CityDirectoryService;

/**
 * A resolved city cluster with its upcoming public activity counts.
 *
 * Built by {@see CityDirectoryService} for city hub pages
 * (M062): a cluster is the set of locations sharing one normalized city
 * name (Str::slug(city)) and one geohash region prefix. Serializable to a
 * plain array so per-city resolutions round-trip through the cache.
 */
final class CitySummary
{
    /**
     * @param  string  $slug  Normalized city slug (Str::slug of the city column) — the URL key.
     * @param  string  $city  Canonical display name (most frequent city value in the cluster).
     * @param  string|null  $country  Most frequent country code in the cluster (ISO-3, may be null).
     * @param  string  $regionPrefix  The geohash prefix that delimited the cluster (region scale).
     * @param  array<int, string>  $geohashTiles  Distinct geohash_4 tiles of the cluster's locations.
     * @param  array<int, string>  $locationIds  UUIDs of every location in the cluster.
     * @param  int  $upcomingGamesCount  Future scheduled public games at cluster locations (within the window).
     * @param  int  $upcomingCampaignsCount  Active public campaigns with a future scheduled session at a cluster location.
     * @param  int  $upcomingEventsCount  Public events (is_public + public status) starting within the window at cluster locations.
     * @param  int  $verifiedVenuesCount  Cluster locations eligible for a public venue page (scopePublicVenuePage + slug).
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $city,
        public readonly ?string $country,
        public readonly string $regionPrefix,
        public readonly array $geohashTiles,
        public readonly array $locationIds,
        public readonly int $upcomingGamesCount,
        public readonly int $upcomingCampaignsCount,
        public readonly int $upcomingEventsCount,
        public readonly int $verifiedVenuesCount,
    ) {}

    /**
     * Total upcoming public activity used by the qualification guard
     * (config cityhubs.min_upcoming_sessions). Games, campaigns, and events
     * are each distinct attendable offerings, so they sum.
     */
    public function upcomingActivityCount(): int
    {
        return $this->upcomingGamesCount
            + $this->upcomingCampaignsCount
            + $this->upcomingEventsCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'city' => $this->city,
            'country' => $this->country,
            'regionPrefix' => $this->regionPrefix,
            'geohashTiles' => $this->geohashTiles,
            'locationIds' => $this->locationIds,
            'upcomingGamesCount' => $this->upcomingGamesCount,
            'upcomingCampaignsCount' => $this->upcomingCampaignsCount,
            'upcomingEventsCount' => $this->upcomingEventsCount,
            'verifiedVenuesCount' => $this->verifiedVenuesCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            slug: (string) $array['slug'],
            city: (string) $array['city'],
            country: isset($array['country']) ? (string) $array['country'] : null,
            regionPrefix: (string) $array['regionPrefix'],
            geohashTiles: array_values(array_map('strval', $array['geohashTiles'] ?? [])),
            locationIds: array_values(array_map('strval', $array['locationIds'] ?? [])),
            upcomingGamesCount: (int) ($array['upcomingGamesCount'] ?? 0),
            upcomingCampaignsCount: (int) ($array['upcomingCampaignsCount'] ?? 0),
            upcomingEventsCount: (int) ($array['upcomingEventsCount'] ?? 0),
            verifiedVenuesCount: (int) ($array['verifiedVenuesCount'] ?? 0),
        );
    }
}
