<?php

namespace App\Dto;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use Illuminate\Database\Eloquent\Model;

/**
 * DTO for a nearby game session or campaign item used in the NearbySessions component.
 *
 * Provides a typed container instead of anonymous stdClass objects, eliminating
 * PHPStan Collection template invariance issues.
 */
class NearbySessionItem
{
    /**
     * @param  Game|Campaign  $entity
     * @param  float  $distance_km  Distance from the user's location in kilometers
     * @param  'session'|'campaign'  $type  Discriminator for entity type
     */
    public function __construct(
        public readonly Model $entity,
        public readonly ?Location $location,
        public readonly float $distance_km,
        public readonly ?GameSystem $game_system,
        public readonly int $participant_count,
        public readonly string $type,
    ) {}
}
