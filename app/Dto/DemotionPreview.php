<?php

namespace App\Dto;

use App\Services\CapacityService;

/**
 * Pure read model for the capacity-decrease confirm UI.
 *
 * Returned by {@see CapacityService::previewDemotion()} so the
 * Livewire layer (T04) can render the confirm modal BEFORE any write happens:
 * how many players the host wants to displace, how many are actually demotable
 * (non-exempt Approved), the cap-rule effective count, who would be demoted,
 * and who is exempt (the owner + manually-promoted players).
 *
 * The CAP rule: when `requestedDisplaced` exceeds `demotableCount`, only the
 * demotable set is actually demoted (`actualDemotionCount`). The owner and
 * manually-promoted players are intentionally allowed to sit over-capacity
 * per the manuallyPromote "capacity is not enforced" contract — surfacing this
 * here prevents a confused host from expecting more demotions than occur.
 */
class DemotionPreview
{
    /**
     * @param  int  $requestedDisplaced  approved_count - newMax (what the host asked to remove).
     * @param  int  $demotableCount  How many non-exempt Approved players exist.
     * @param  int  $actualDemotionCount  min(requested, demotable) — the effective count.
     * @param  array<int, array{id: string, name: string, approved_at: string|null}>  $wouldDemote  Players selected for demotion (LIFO approved_at DESC), limited to actualDemotionCount.
     * @param  array<int, array{id: string, name: string, reason: string}>  $exempt  Owner + manually-promoted players, each with an exemption reason.
     */
    public function __construct(
        public readonly int $requestedDisplaced,
        public readonly int $demotableCount,
        public readonly int $actualDemotionCount,
        public readonly array $wouldDemote,
        public readonly array $exempt,
    ) {}
}
