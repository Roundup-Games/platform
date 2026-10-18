<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A paid membership just became active for the user (M069, spec §2.5).
 *
 * Dispatched by GmRoleService AFTER the activating transaction commits
 * (after-commit discipline, cf. BadgeService domain events) so queued
 * consumers — first and foremost the BadgePipeline listener — only ever
 * observe committed membership state. Covers every activation path:
 * local/demo GM-plan activations (activateGmSubscription), GM-role
 * assignment on an active membership (assignGMRole — the funnel the
 * Paddle webhook takes), so repeated dispatches are expected and safe
 * (the badge pipeline is idempotent).
 *
 * Carries the acting user only; anything a consumer needs beyond the
 * user is a query away against committed state.
 */
final readonly class MembershipActivated
{
    use Dispatchable;

    public function __construct(
        public User $user,
    ) {}
}
