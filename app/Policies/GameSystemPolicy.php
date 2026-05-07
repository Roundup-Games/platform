<?php

namespace App\Policies;

use App\Models\GameSystem;
use App\Models\User;
use App\Services\ScopedRoleService;

class GameSystemPolicy
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
     * View any game system (listing pages): always public.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * View a specific game system: always public.
     */
    public function view(?User $user, GameSystem $system): bool
    {
        return true;
    }

    /**
     * Create a game system: Games Admin or global admin.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('Games Admin')
            || app(ScopedRoleService::class)->isGlobalAdmin($user);
    }

    /**
     * Update a game system: Games Admin or global admin.
     */
    public function update(User $user, GameSystem $system): bool
    {
        return $user->hasRole('Games Admin')
            || app(ScopedRoleService::class)->isGlobalAdmin($user);
    }

    /**
     * Delete a game system: Platform Admin only.
     */
    public function delete(User $user, GameSystem $system): bool
    {
        return $user->hasRole('Platform Admin');
    }

    /**
     * Request a new game system: any authenticated user.
     * Supports GameSystemRequest flow — guests cannot request.
     */
    public function requestNew(?User $user): bool
    {
        return $user !== null;
    }
}
