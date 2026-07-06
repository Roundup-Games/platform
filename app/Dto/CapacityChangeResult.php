<?php

namespace App\Dto;

use App\Services\CapacityService;

/**
 * Result of a max_players change via {@see CapacityService}.
 *
 * Mirrors the style of {@see ParticipantResult}. `promotedCount` is the number
 * of waitlisted players auto-promoted to Pending — only non-zero on the
 * increase path. The silent-decrease path reports 0 here; the LIFO demotion
 * flow (T03) returns a richer DemotionResult instead.
 */
class CapacityChangeResult
{
    /**
     * @param  int|null  $oldMax  Previous max_players (null = was unlimited).
     * @param  int  $newMax  Resulting max_players. CapacityService::increase()/decrease()
     *                       always produce a non-null int (they reject newMax < 1); null is
     *                       never produced in practice.
     * @param  int  $promotedCount  Waitlisted players moved to Pending (increase path only).
     */
    public function __construct(
        public readonly ?int $oldMax,
        public readonly ?int $newMax,
        public readonly int $promotedCount,
    ) {}
}
