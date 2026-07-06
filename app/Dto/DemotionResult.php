<?php

namespace App\Dto;

use App\Services\CapacityService;

/**
 * Result of a capacity-decrease demotion via {@see CapacityService::demote()}.
 *
 * Mirrors the style of {@see CapacityChangeResult}. Carries how many players
 * were actually demoted (may be less than requested — see the CAP rule on
 * {@see DemotionPreview}), the demoted participant ids, and how many Approved
 * players were exempt (the owner + manually-promoted).
 */
class DemotionResult
{
    /**
     * @param  int  $demotedCount  Number of players moved to Waitlisted.
     * @param  array<int, string>  $demoted  Demoted participant ids.
     * @param  int  $exemptCount  Approved players spared (owner + manually-promoted).
     */
    public function __construct(
        public readonly int $demotedCount,
        public readonly array $demoted,
        public readonly int $exemptCount,
    ) {}
}
