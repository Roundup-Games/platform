<?php

namespace App\Policies;

use App\Models\MembershipType;
use App\Models\User;
use App\Services\ScopedRoleService;

class MembershipTypePolicy
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
     * View any membership type (Filament resource listing).
     */
    public function viewAny(User $user): bool
    {
        return app(ScopedRoleService::class)->hasPermissionInAnyScope($user, 'view membership');
    }

    /**
     * View a membership type. Active types are publicly viewable.
     */
    public function view(?User $user, MembershipType $membershipType): bool
    {
        // Active membership types are publicly browsable
        if ($membershipType->status === 'active') {
            return true;
        }

        // Non-active types require authentication + permission
        return $user !== null && $this->checkPermission($user, 'view membership');
    }

    /**
     * Create a membership type.
     */
    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'create membership');
    }

    /**
     * Update a membership type.
     */
    public function update(User $user, MembershipType $membershipType): bool
    {
        return $this->checkPermission($user, 'update membership');
    }

    /**
     * Delete a membership type.
     */
    public function delete(User $user, MembershipType $membershipType): bool
    {
        return $this->checkPermission($user, 'delete membership');
    }

    /**
     * Check permission without throwing on missing permission.
     */
    private function checkPermission(User $user, string $permission): bool
    {
        return app(ScopedRoleService::class)->checkPermission($user, $permission);
    }
}
