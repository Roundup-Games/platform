<?php

namespace App\Dto;

use App\Models\Location;
use Illuminate\Database\Eloquent\Model;

/**
 * A model with its nearest Location and computed distance.
 *
 * Replaces the (object) ['entity' => ..., 'location' => ..., 'distance_km' => ...]
 * stdClass casts from ProximityQuery.
 *
 * @template TEntity of Model
 */
class ProximityResult
{
    /**
     * @param  TEntity  $entity  The hydrated model (Game, Campaign, etc.)
     * @param  Location  $location  The associated location
     * @param  float  $distanceKm  Distance in kilometers, rounded to 2 decimals
     */
    public function __construct(
        public readonly Model $entity,
        public readonly Location $location,
        public readonly float $distanceKm,
    ) {}

    /**
     * @return array{entity_id: string, distance_km: float}
     */
    public function toDistanceMapEntry(): array
    {
        $key = $this->entity->getKey();

        return [
            'entity_id' => is_string($key) ? $key : '',
            'distance_km' => $this->distanceKm,
        ];
    }
}
