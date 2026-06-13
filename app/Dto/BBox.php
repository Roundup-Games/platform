<?php

namespace App\Dto;

/**
 * A geographic bounding box defined by min/max latitude and longitude.
 *
 * Replaces the ad-hoc array{minLat, maxLat, minLng, maxLng} shape
 * from Geohash::bbox() and ProximityQuery internals.
 */
class BBox
{
    public function __construct(
        public readonly float $minLat,
        public readonly float $maxLat,
        public readonly float $minLng,
        public readonly float $maxLng,
    ) {}

    public function latRange(): float
    {
        return $this->maxLat - $this->minLat;
    }

    public function lngRange(): float
    {
        return $this->maxLng - $this->minLng;
    }

    public function centerLat(): float
    {
        return ($this->minLat + $this->maxLat) / 2;
    }

    public function centerLng(): float
    {
        return ($this->minLng + $this->maxLng) / 2;
    }

    /**
     * Expand the box by a margin in degrees.
     */
    public function expanded(float $margin): self
    {
        return new self(
            $this->minLat - $margin,
            $this->maxLat + $margin,
            $this->minLng - $margin,
            $this->maxLng + $margin,
        );
    }

    /**
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    public function toArray(): array
    {
        return [
            'minLat' => $this->minLat,
            'maxLat' => $this->maxLat,
            'minLng' => $this->minLng,
            'maxLng' => $this->maxLng,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            minLat: is_float($data['minLat'] ?? null) ? $data['minLat'] : 0.0,
            maxLat: is_float($data['maxLat'] ?? null) ? $data['maxLat'] : 0.0,
            minLng: is_float($data['minLng'] ?? null) ? $data['minLng'] : 0.0,
            maxLng: is_float($data['maxLng'] ?? null) ? $data['maxLng'] : 0.0,
        );
    }
}
