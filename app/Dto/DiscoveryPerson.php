<?php

namespace App\Dto;

use App\Models\User;

/**
 * A person discovered through the People Discovery pipeline.
 *
 * Replaces the ad-hoc array{user, compatibility_score, match_reasons, tier, distance_km}
 * shape from PeopleDiscoveryService.
 */
class DiscoveryPerson
{
    /**
     * @param  User  $user  The discovered user
     * @param  float  $compatibilityScore  0.0 – 1.0
     * @param  list<string>  $matchReasons  Human-readable reasons for the match
     * @param  int  $tier  Match tier (1 = best)
     * @param  float|null  $distanceKm  Distance in km, null if unavailable
     */
    public function __construct(
        public readonly User $user,
        public readonly float $compatibilityScore,
        public readonly array $matchReasons,
        public readonly int $tier,
        public readonly ?float $distanceKm,
    ) {}

    /**
     * @return array{user_id: int|string, compatibility_score: float, match_reasons: list<string>, tier: int, distance_km: float|null}
     */
    public function toArray(): array
    {
        $key = $this->user->getKey();

        return [
            'user_id' => is_string($key) ? $key : '',
            'compatibility_score' => $this->compatibilityScore,
            'match_reasons' => $this->matchReasons,
            'tier' => $this->tier,
            'distance_km' => $this->distanceKm,
        ];
    }

    /**
     * Reconstruct from cached array data (requires a hydrated User).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, User $user): self
    {
        $reasons = is_array($data['match_reasons'] ?? null) ? $data['match_reasons'] : [];

        return new self(
            user: $user,
            compatibilityScore: is_float($data['compatibility_score'] ?? null) ? $data['compatibility_score'] : 0.0,
            matchReasons: array_values(array_filter($reasons, 'is_string')),
            tier: is_int($data['tier'] ?? null) ? $data['tier'] : 99,
            distanceKm: is_float($data['distance_km'] ?? null) ? $data['distance_km'] : null,
        );
    }
}
