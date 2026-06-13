<?php

namespace App\Dto;

/**
 * Structured result from geocoding an address via Nominatim.
 *
 * Replaces the ad-hoc array{lat, lng, display_name, place_id, raw} shape
 * returned by GeocodingService::geocode().
 */
class GeocodeResult
{
    /**
     * @param  float  $lat  Latitude
     * @param  float  $lng  Longitude
     * @param  string  $displayName  Human-readable address
     * @param  string|null  $placeId  Nominatim place identifier
     * @param  array<int|string, mixed>  $raw  Full Nominatim response
     */
    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
        public readonly string $displayName,
        public readonly ?string $placeId,
        public readonly array $raw = [],
    ) {}

    /**
     * Build from a Nominatim JSON response item.
     *
     * @param  array<string, mixed>  $item
     */
    public static function fromNominatim(array $item): self
    {
        $lat = is_numeric($item['lat'] ?? null) ? (float) $item['lat'] : 0.0;
        $lng = is_numeric($item['lon'] ?? null) ? (float) $item['lon'] : 0.0;
        $displayName = is_string($item['display_name'] ?? null) ? $item['display_name'] : '';
        $placeId = isset($item['place_id']) && (is_int($item['place_id']) || is_string($item['place_id'])) ? (string) $item['place_id'] : null;

        return new self(
            lat: $lat,
            lng: $lng,
            displayName: $displayName,
            placeId: $placeId,
            raw: $item,
        );
    }

    /**
     * @return array{lat: float, lng: float, display_name: string, place_id: string|null, raw: array<int|string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
            'display_name' => $this->displayName,
            'place_id' => $this->placeId,
            'raw' => $this->raw,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            lat: is_float($data['lat'] ?? null) ? $data['lat'] : (is_numeric($data['lat'] ?? null) ? (float) $data['lat'] : 0.0),
            lng: is_float($data['lng'] ?? null) ? $data['lng'] : (is_numeric($data['lng'] ?? null) ? (float) $data['lng'] : 0.0),
            displayName: is_string($data['display_name'] ?? null) ? $data['display_name'] : '',
            placeId: is_string($data['place_id'] ?? null) ? $data['place_id'] : null,
            raw: is_array($data['raw'] ?? null) ? $data['raw'] : [],
        );
    }
}
