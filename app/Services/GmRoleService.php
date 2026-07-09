<?php

namespace App\Services;

use App\Enums\GameType;
use App\Models\GMProfile;
use App\Models\LocalSubscription;
use App\Models\MembershipType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class GmRoleService
{
    private const ROLE_NAME = 'Game Master';

    /**
     * Activate a free GM subscription for the user.
     *
     * Creates a LocalSubscription, assigns the GM role, and activates the GMProfile.
     * Idempotent — safe to call if already subscribed.
     */
    public function activateGmSubscription(User $user): bool
    {
        $gmPlan = MembershipType::active()
            ->where('type', 'local')
            ->whereJsonContains('metadata->gm_plan', true)
            ->first();

        if (! $gmPlan) {
            Log::error('GM plan not found in membership_types. Ensure MembershipTypeSeeder has been run.');

            return false;
        }

        return DB::transaction(function () use ($user, $gmPlan) {
            // Ensure the role exists (defense against unseeded DB)
            $role = Role::firstOrCreate([
                'name' => self::ROLE_NAME,
                'guard_name' => 'web',
                'team_id' => null,
            ]);

            // Create or reactivate the local subscription
            $subscription = $user->localSubscriptions()->updateOrCreate(
                [
                    'membership_type_id' => $gmPlan->id,
                ],
                [
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => null,
                    'canceled_at' => null,
                ],
            );

            // Grant public game/campaign creation for active GMs
            $user->can_create_public_entries = true;
            $user->save();

            // Assign the GM role at global scope
            if (! $user->hasRole(self::ROLE_NAME)) {
                $user->assignRole($role);
            }

            // Create or reactivate the GMProfile
            $profile = $user->gmProfile()->updateOrCreate([], ['is_active' => true]);

            Log::info('GM subscription activated', [
                'user_id' => $user->id,
                'gm_profile_id' => $profile->id,
                'local_subscription_id' => $subscription->id,
            ]);

            return true;
        });
    }

    /**
     * Deactivate the GM subscription for the user.
     *
     * Marks the LocalSubscription as canceled, removes the GM role,
     * and deactivates the GMProfile. Does NOT delete the profile or reviews.
     */
    public function deactivateGmSubscription(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Mark local subscription as canceled
            $subscription = LocalSubscription::whereBelongsTo($user)
                ->whereHas('membershipType', fn ($q) => $q->whereJsonContains('metadata->gm_plan', true))
                ->active()
                ->first();

            if ($subscription) {
                $subscription->update([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                ]);
            }

            // Remove the GM role
            if ($user->hasRole(self::ROLE_NAME)) {
                $user->removeRole(self::ROLE_NAME);
            }

            // Revoke public game/campaign creation
            $user->can_create_public_entries = false;
            $user->save();

            // Deactivate the GMProfile
            $profile = $user->gmProfile;
            if ($profile && $profile->is_active) {
                $profile->is_active = false;
                $profile->save();
            }

            Log::info('GM subscription deactivated', [
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * Assign the Game Master role to a user.
     *
     * Requirements:
     * - User must have an active subscription (Paddle or local).
     * - Creates a GMProfile if one does not already exist.
     * - Sets is_active=true on the GMProfile.
     * - Assigns the 'Game Master' Spatie role at global scope.
     *
     * @return bool True if the role was assigned (or already active).
     */
    public function assignGMRole(User $user): bool
    {
        if (! $user->hasActiveMembership()) {
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

            // Grant public game/campaign creation for active GMs
            $user->can_create_public_entries = true;
            $user->save();

            // Create or reactivate the GMProfile
            $profile = $user->gmProfile()->updateOrCreate([], ['is_active' => true]);

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

            // Revoke public game/campaign creation
            $user->can_create_public_entries = false;
            $user->save();

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
        return $user->hasRole(self::ROLE_NAME) && $user->hasActiveMembership();
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
     * Only active GMs can create TTRPG games as GM. Board games are never
     * eligible for GM creation mode.
     */
    public function canCreateAsGm(User $user, string $gameType): bool
    {
        return $this->isGmActive($user) && $gameType === GameType::Ttrpg->value;
    }
}
