<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A paid membership just ended for the user (M069, spec §2.5).
 *
 * Dispatched by GmRoleService AFTER the ending transaction commits:
 * local self-serve deactivation (deactivateGmSubscription — only when
 * NO active membership remains, so a surviving comp keeps membership
 * alive and dispatches nothing) and full lapses
 * (handleSubscriptionLapse). Repeated dispatches are expected
 * and safe; consumers must treat "already ended" as a no-op.
 *
 * Note: badge lapses are sweep-owned and quiet (spec §4) — the badge
 * engine does NOT listen to this event; SupportingMemberEvaluator's
 * nightly sweep discovers inactivity by re-evaluating, so both this
 * event and its absence stay non-load-bearing for badges.
 */
final readonly class MembershipEnded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public User $user,
    ) {}
}
