<?php

namespace App\Policies;

use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\User;
use App\Services\ScopedRoleService;
use Illuminate\Support\Facades\Log;

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
     * protected visible to friends/teammates of the owner, plus participants;
     * private only to owner and participants.
     */
    public function view(?User $user, Campaign $campaign): bool
    {
        if ($campaign->visibility === Visibility::Public) {
            return true;
        }

        // Share token bypass: valid token grants access regardless of visibility
        if ($campaign->hasValidShareToken()) {
            Log::info('Share token granted access', [
                'entity_type' => 'campaign',
                'entity_id' => $campaign->id,
                'share_token' => substr($campaign->share_token, 0, 8) . '…',
            ]);

            return true;
        }

        if ($campaign->visibility === Visibility::Protected) {
            return $user !== null
                && ($campaign->owner_id === $user->id
                    || $user->isFriendOrTeammate($campaign->owner)
                    || $campaign->participants()->where('user_id', $user->id)->exists());
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
