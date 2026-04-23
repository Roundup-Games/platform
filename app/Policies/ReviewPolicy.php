<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;
use App\Services\ScopedRoleService;

class ReviewPolicy
{
    /**
     * Global admin bypass.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (app(ScopedRoleService::class)->isGlobalAdmin($user)) {
            return true;
        }

        return null;
    }

    /**
     * Create a review: authenticated user who is a confirmed participant
     * of the reviewable entity, the scheduled date has passed, and they
     * haven't already reviewed it.
     *
     * The actual eligibility check is delegated to ReviewEligibilityService.
     * The policy gate is the final gate — controllers should check eligibility
     * via the service first for clearer error messages.
     */
    public function create(User $user): bool
    {
        // The real checks happen in ReviewEligibilityService, but we allow
        // the gate to pass for any authenticated user. The service + controller
        // are responsible for the participant/date/duplicate checks.
        return true;
    }

    /**
     * Update a review: only the reviewer who wrote it.
     */
    public function update(User $user, Review $review): bool
    {
        return $review->reviewer_id === $user->id;
    }

    /**
     * Delete a review: admin only (via before() bypass).
     * The reviewer cannot delete their own review.
     */
    public function delete(User $user, Review $review): bool
    {
        // Intentionally NOT allowing reviewer to delete.
        // Only global admins can delete (handled by before()).
        return false;
    }

    /**
     * Report a review: any authenticated user can report any review
     * they did not write.
     */
    public function report(User $user, Review $review): bool
    {
        return $review->reviewer_id !== $user->id;
    }
}
