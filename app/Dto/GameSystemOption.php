<?php

namespace App\Dto;

/**
 * DTO for a game system option (base game or expansion) used in the
 * GameSystemPicker and GameSystemPreferencePicker components.
 *
 * Provides a typed container instead of anonymous stdClass objects, eliminating
 * PHPStan Collection template invariance issues.
 */
class GameSystemOption
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $is_base,
        public readonly ?int $bgg_rank,
        public readonly ?float $bgg_average_rating,
        public readonly ?string $thumbnail_url,
    ) {}
}
