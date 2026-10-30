<?php

namespace App\Policies;

use App\Models\GMProfile;
use App\Models\User;
use App\Services\ScopedRoleService;

class GMProfilePolicy
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
     * View a GM profile: always public (GM directory).
     */
    public function view(?User $user, GMProfile $profile): bool
    {
        return true;
    }

    /**
     * Update a GM profile: only the GM who owns it.
     */
    public function update(User $user, GMProfile $profile): bool
    {
        return $user->id === $profile->user_id;
    }
}
