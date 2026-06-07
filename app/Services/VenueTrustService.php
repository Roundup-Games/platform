<?php

namespace App\Services;

use App\Models\Location;
use App\Models\User;

class VenueTrustService
{
    /**
     * Determine whether the given user can create a public game/campaign.
     *
     * A user may create public entries if:
     * 1. They have the can_create_public_entries flag (GM users), OR
     * 2. They are creating at a verified venue (location is verified).
     */
    public function canCreatePublic(User $user, ?string $locationId): bool
    {
        if ($user->can_create_public_entries) {
            return true;
        }

        if ($locationId && Location::where('id', $locationId)->where('is_verified', true)->exists()) {
            return true;
        }

        return false;
    }
}
