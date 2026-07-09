<?php

namespace App\Policies;

use App\Models\User;
use App\Services\ScopedRoleService;

class UserPolicy
{
    /**
     * Global admin bypass: Platform Admin and Games Admin can do anything.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (app(ScopedRoleService::class)->isGlobalAdmin($user)) {
            return true;
        }

        return null; // fall through to individual policy methods
    }

    /**
     * View any user (Filament resource listing).
     */
    public function viewAny(User $user): bool
    {
        return app(ScopedRoleService::class)->hasPermissionInAnyScope($user, 'view user');
    }

    /**
     * View a user profile.
     * Guests (null $user) can view any public profile — ProfileVisibilityResolver
     * controls what fields are actually shown.
     */
    public function view(?User $user, User $targetUser): bool
    {
        // Guests can view any profile (visibility is handled by ProfileVisibilityResolver)
        if ($user === null) {
            return true;
        }

        // Users can always view their own profile
        if ($user->is($targetUser)) {
            return true;
        }

        return $this->checkPermission($user, 'view user');
    }

    /**
     * Create a new user.
     */
    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'create user');
    }

    /**
     * Update a user profile.
     */
    public function update(User $user, User $targetUser): bool
    {
        // Users can always update their own profile
        if ($user->is($targetUser)) {
            return true;
        }

        return $this->checkPermission($user, 'update user');
    }

    /**
     * Delete a user.
     */
    public function delete(User $user, User $targetUser): bool
    {
        // Cannot delete yourself
        if ($user->is($targetUser)) {
            return false;
        }

        return $this->checkPermission($user, 'delete user');
    }

    /**
     * Check permission without throwing on missing permission.
     */
    private function checkPermission(User $user, string $permission): bool
    {
        return app(ScopedRoleService::class)->checkPermission($user, $permission);
    }
}
