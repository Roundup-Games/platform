<?php

namespace App\Policies;

use App\Enums\CampaignStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\User;
use App\Services\ScopedRoleService;
use App\Services\ShortLinkService;
use App\Traits\ValidatesShortLinkCookie;

class CampaignPolicy
{
    use ValidatesShortLinkCookie;
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

        // Short link bypass: valid short link grants access unless campaign is completed/cancelled.
        // Terminal-status check also lives inside isValidShortLinkForEntity() via the trait,
        // but we guard here too for defense-in-depth consistency with the share-token path.
        if ($campaign->status !== CampaignStatus::Cancelled
            && $campaign->status !== CampaignStatus::Completed
            && $this->hasValidShortLink($campaign)) {
            return true;
        }

        // Share token bypass: valid token grants access unless campaign is completed/cancelled
        if ($campaign->hasValidShareToken()
            && $campaign->status !== CampaignStatus::Cancelled
            && $campaign->status !== CampaignStatus::Completed) {
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
     * Create a campaign: any authenticated user with a complete profile.
     * Admin roles (Games Admin, Platform Admin) are already handled by before().
     */
    public function create(User $user): bool
    {
        return $user->profile_complete;
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

    /**
     * Check if the current request carries a valid short link for this campaign.
     */
    private function hasValidShortLink(Campaign $campaign): bool
    {
        return $this->isValidShortLinkForEntity($campaign);
    }
}
