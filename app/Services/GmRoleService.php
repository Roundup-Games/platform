<?php

namespace App\Services;

use App\Models\GMProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class GmRoleService
{
    private const ROLE_NAME = 'Game Master';

    /**
     * Assign the Game Master role to a user.
     *
     * Requirements:
     * - User must have an active subscription.
     * - Creates a GMProfile if one does not already exist.
     * - Sets is_active=true on the GMProfile.
     * - Assigns the 'Game Master' Spatie role at global scope.
     *
     * @return bool True if the role was assigned (or already active).
     */
    public function assignGMRole(User $user): bool
    {
        if (! $user->subscribed()) {
            Log::warning('GM role assignment denied: no active subscription', [
                'user_id' => $user->id,
                'action' => 'assignGMRole',
            ]);

            return false;
        }

        return DB::transaction(function () use ($user) {
            // Ensure the role exists (defense against unseeded DB)
            $role = Role::firstOrCreate([
                'name' => self::ROLE_NAME,
                'guard_name' => 'web',
                'team_id' => null,
            ]);

            // Assign at global scope
            if (! $user->hasRole(self::ROLE_NAME)) {
                $user->assignRole($role);
            }

            // Create or reactivate the GMProfile
            $profile = GMProfile::firstOrNew(['user_id' => $user->id]);
            $profile->is_active = true;
            $profile->save();

            Log::info('GM role assigned', [
                'user_id' => $user->id,
                'gm_profile_id' => $profile->id,
            ]);

            return true;
        });
    }

    /**
     * Revoke the Game Master role from a user.
     *
     * - Removes the 'Game Master' Spatie role.
     * - Sets gmProfile.is_active = false.
     * - Does NOT delete the GMProfile or its reviews.
     */
    public function revokeGMRole(User $user): void
    {
        DB::transaction(function () use ($user) {
            if ($user->hasRole(self::ROLE_NAME)) {
                $user->removeRole(self::ROLE_NAME);
            }

            $profile = $user->gmProfile;
            if ($profile && $profile->is_active) {
                $profile->is_active = false;
                $profile->save();
            }

            Log::info('GM role revoked', [
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * Check if a user is an active GM (has role AND active subscription).
     */
    public function isGmActive(User $user): bool
    {
        return $user->hasRole(self::ROLE_NAME) && $user->subscribed();
    }

    /**
     * Handle a subscription lapse (e.g., from Paddle webhook).
     *
     * Revokes the GM role but preserves the GMProfile for historical data.
     */
    public function handleSubscriptionLapse(User $user): void
    {
        Log::info('GM subscription lapse handling started', [
            'user_id' => $user->id,
        ]);

        $this->revokeGMRole($user);

        Log::info('GM subscription lapse handling completed', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Can the given user create a game in GM mode?
     *
     * GM mode means the game is being offered as a professional/paid GM session.
     * Only active GMs can create games as GM. Non-GMs can still create casual
     * board game sessions.
     */
    public function canCreateAsGm(User $user): bool
    {
        return $this->isGmActive($user);
    }
}
