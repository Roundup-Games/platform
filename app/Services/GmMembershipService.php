<?php

namespace App\Services;

use App\Models\User;

/**
 * THE membership-capability seam (M071/S04, D223).
 *
 * Extracted from CharterMemberService::canUseConveniences before the
 * second caller landed: charter conveniences and the Previously On
 * digest (D185 gate — CampaignPolicy::managePreviouslyOn and both D221
 * composition triggers) ask this single service whether a user may use
 * member features. Semantics today are identical to the charter check
 * that lived here — active GM role plus an active subscription
 * (GmRoleService::isGmActive) — and this is the ONE place a future
 * pricing milestone can tighten from "active GM role" to "paid
 * membership" without touching either caller (the single-seam invariant
 * of D223, R085).
 */
class GmMembershipService
{
    /**
     * May this user use member features (unlimited active charters, the
     * charter template/reuse conveniences, the Previously On digest)?
     * Delegates to the active-GM check today; tighten to paid-only here
     * when the pricing milestone lands.
     */
    public static function canUseMemberFeatures(User $user): bool
    {
        return app(GmRoleService::class)->isGmActive($user);
    }
}
