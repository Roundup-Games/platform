<?php

namespace App\Dto;

use App\Services\CreateDefaultsService;

/**
 * Strongly-typed creation defaults resolved by {@see CreateDefaultsService}.
 *
 * Carries per-field defaults (from the user's prior session of the same type
 * and profile preferences) to the CreateGame / CreateCampaign Livewire forms.
 * Every property has a declared type so consumers can assign directly to their
 * typed Livewire properties without PHPStan "does not accept mixed" failures,
 * and the service is the single place that coerces mixed Eloquent attributes
 * to these clean types.
 */
class CreateDefaults
{
    /**
     * @param  array<int, string>  $gameSystems  Offered-system set carried forward for Gatherings.
     */
    public function __construct(
        public readonly ?string $language = null,
        public readonly ?string $locationId = null,
        public readonly ?string $locationInstructions = null,
        public readonly ?string $visibility = null,
        public readonly ?string $experienceLevel = null,
        public readonly ?string $expectedDuration = null,
        public readonly ?int $maxPlayers = null,
        public readonly ?int $minPlayers = null,
        public readonly ?string $gameSystemId = null,
        public readonly array $gameSystems = [],
        public readonly ?string $gameType = null,
        public readonly ?string $recurrence = null,
        /** @var array<int, string>|null */
        public readonly ?array $vibeFlags = null,
    ) {}
}
