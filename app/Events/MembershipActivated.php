<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
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
 *
 * ShouldDispatchAfterCommit makes that discipline STRUCTURAL, not
 * just convention: dispatches are held until the OUTERMOST transaction
 * commits (nested inner "commits" are savepoint releases — a caller
 * wrapping GmRoleService in its own DB::transaction, like the expiry
 * sweep does, would otherwise deliver the event to the queued pipeline
 * before the membership state is actually durable). No transaction
 * active: dispatch is immediate, so the post-commit call sites are
 * unchanged.
 */
final readonly class MembershipActivated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public User $user,
    ) {}
}
