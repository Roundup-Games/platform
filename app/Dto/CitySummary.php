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
     * @param  bool  $featured  Admin curation flag (62-04): featured cities force-qualify over both thresholds and feed the featured-cities rail.
     * @param  array<string, string>  $intro  Curated translatable hero intro (locale => string) from the cities.intro column; empty when uncurated.
     * @param  array<string, string>  $seoTitle  Curated translatable SEO title override (locale => string) from cities.seo_title; empty when uncurated (D171).
     * @param  array<string, string>  $seoDescription  Curated translatable meta description override (locale => string) from cities.seo_description; empty when uncurated (D171).
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
        public readonly bool $featured = false,
        public readonly array $intro = [],
        public readonly array $seoTitle = [],
        public readonly array $seoDescription = [],
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
     * Curated intro for one locale: the trimmed value, or null when the
     * locale has no (non-empty) translation — the hub hero then falls
     * back to its generated copy (62-04 decision: curated locale intro
     * rendered from the cached resolution, MEM1023).
     */
    public function introFor(string $locale): ?string
    {
        $text = $this->intro[$locale] ?? null;

        if (! is_string($text)) {
            return null;
        }

        $trimmed = trim($text);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Curated SEO title for one locale: the trimmed value, or null when
     * the locale has no curated override — the hub then falls back to the
     * generated lang-key title.
     */
    public function seoTitleFor(string $locale): ?string
    {
        return $this->localizedSeoText($this->seoTitle, $locale);
    }

    /**
     * Curated meta description for one locale (same null-when-absent
     * semantics as seoTitleFor).
     */
    public function seoDescriptionFor(string $locale): ?string
    {
        return $this->localizedSeoText($this->seoDescription, $locale);
    }

    /**
     * @param  array<string, string>  $map
     */
    private function localizedSeoText(array $map, string $locale): ?string
    {
        $text = $map[$locale] ?? null;

        if (! is_string($text)) {
            return null;
        }

        $trimmed = trim($text);

        return $trimmed === '' ? null : $trimmed;
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
            'featured' => $this->featured,
            'intro' => $this->intro,
            'seoTitle' => $this->seoTitle,
            'seoDescription' => $this->seoDescription,
        ];
    }

    /**
     * Strict hydration: every field is narrowed with is_string/is_int
     * checks (the ActionItem/DiscoveryFilters convention) instead of blind
     * casts, so a malformed cache entry surfaces as the documented default
     * rather than a silent (string) coercion of whatever was stored.
     * featured/intro default false/[] — pre-62-04 cache entries (written
     * before curation existed) degrade safely instead of erroring.
     *
     * @param  array<string, mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            slug: is_string($array['slug'] ?? null) ? $array['slug'] : '',
            city: is_string($array['city'] ?? null) ? $array['city'] : '',
            country: is_string($array['country'] ?? null) ? $array['country'] : null,
            regionPrefix: is_string($array['regionPrefix'] ?? null) ? $array['regionPrefix'] : '',
            geohashTiles: self::stringList($array['geohashTiles'] ?? []),
            locationIds: self::stringList($array['locationIds'] ?? []),
            upcomingGamesCount: is_int($array['upcomingGamesCount'] ?? null) ? $array['upcomingGamesCount'] : 0,
            upcomingCampaignsCount: is_int($array['upcomingCampaignsCount'] ?? null) ? $array['upcomingCampaignsCount'] : 0,
            upcomingEventsCount: is_int($array['upcomingEventsCount'] ?? null) ? $array['upcomingEventsCount'] : 0,
            verifiedVenuesCount: is_int($array['verifiedVenuesCount'] ?? null) ? $array['verifiedVenuesCount'] : 0,
            featured: is_bool($array['featured'] ?? null) ? $array['featured'] : false,
            intro: self::stringMap($array['intro'] ?? []),
            seoTitle: self::stringMap($array['seoTitle'] ?? []),
            seoDescription: self::stringMap($array['seoDescription'] ?? []),
        );
    }

    /**
     * Keep only the string entries of a cached list value.
     *
     * @return array<int, string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * Keep only the string-keyed string entries of a cached map value
     * (locale => intro text).
     *
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $locale => $text) {
            if (is_string($locale) && is_string($text)) {
                $strings[$locale] = $text;
            }
        }

        return $strings;
    }
}
