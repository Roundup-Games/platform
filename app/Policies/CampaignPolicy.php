<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;
use App\Services\ScopedRoleService;

class CampaignPolicy
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
     * View any campaign (Filament resource listing).
     */
    public function viewAny(User $user): bool
    {
        return app(ScopedRoleService::class)->hasPermissionInAnyScope($user, 'view campaign');
    }

    /**
     * View a campaign: public campaigns visible to everyone;
     * protected/private only to owner (or participants).
     */
    public function view(?User $user, Campaign $campaign): bool
    {
        if ($campaign->visibility === 'public') {
            return true;
        }

        if ($campaign->visibility === 'protected') {
            return $user !== null;
        }

        // Private campaigns require auth and ownership (or participation)
        return $user !== null
            && ($campaign->owner_id === $user->id
                || $campaign->participants()->where('user_id', $user->id)->exists());
    }

    /**
     * Create a campaign: any authenticated user with permission.
     */
    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'create campaign');
    }

    /**
     * Update campaign details: owner OR scoped admin.
     */
    public function update(User $user, Campaign $campaign): bool
    {
        if ($campaign->owner_id === $user->id) {
            return true;
        }

        return $this->checkPermission($user, 'update campaign');
    }

    /**
     * Delete a campaign: owner OR scoped admin.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        if ($campaign->owner_id === $user->id) {
            return true;
        }

        return $this->checkPermission($user, 'delete campaign');
    }

    private function checkPermission(User $user, string $permission): bool
    {
        return app(ScopedRoleService::class)->checkPermission($user, $permission);
    }
}
