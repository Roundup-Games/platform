<?php

namespace App\Services\Charter;

use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Services\GmMembershipService;

/**
 * Charter membership seam (M070/S04).
 *
 * R084: free GMs may run at most FREE_ACTIVE_CHARTERS concurrently ACTIVE
 * charters — the concurrency cap is the only membership-coupled rule the
 * charter lifecycle ever consults. Drafting, stepping, signing, and
 * amendments are free-tier safety and never ask about membership here.
 * R085: active GM members are unlimited.
 *
 * Static methods (CharterService pattern) with the member-capability
 * seam delegated to GmMembershipService::canUseMemberFeatures (D223):
 * charter conveniences and Previously On share that one seam, and it is
 * the pricing milestone's single tightening point — the lifecycle code
 * below never asks about membership anywhere else.
 */
class CharterMemberService
{
    /** Free-tier cap on concurrently ACTIVE charters per owner (R084). */
    public const FREE_ACTIVE_CHARTERS = 2;

    // ── Member seam ────────────────────────────────────

    /**
     * May this user use member conveniences (unlimited active charters,
     * the S04 template/reuse conveniences)? Pure delegation to the D223
     * membership-capability seam so charter conveniences and Previously
     * On tighten together (R085).
     */
    public static function canUseConveniences(User $user): bool
    {
        return GmMembershipService::canUseMemberFeatures($user);
    }

    // ── Active-charter accounting ──────────────────────

    /**
     * The owner's concurrently ACTIVE charters: kind='charter' rows in
     * the active state on campaigns they own. Drafts and archived
     * charters never consume a slot.
     */
    public static function activeCharterCount(User $user): int
    {
        return SessionZeroSurvey::query()
            ->active()
            ->whereHas('campaign', fn ($query) => $query->whereBelongsTo($user, 'owner'))
            ->count();
    }

    /**
     * Is the owner at the free-tier cap? True only when the count has
     * reached the limit AND the member seam says no conveniences —
     * members are never limited.
     */
    public static function atCharterLimit(User $user): bool
    {
        return self::activeCharterCount($user) >= self::FREE_ACTIVE_CHARTERS
            && ! self::canUseConveniences($user);
    }
}
