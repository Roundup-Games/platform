<?php

namespace Tests\Traits;

use App\Models\GMProfile;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Paddle\Cashier;
use Spatie\Permission\Models\Role;

/**
 * Shared helpers for creating users with subscription/GM state.
 *
 * Consolidates duplicated helpers from:
 * - GmWorkspaceTest::createSubscribedGm / createSubscribedNonGm
 * - SessionZeroWorkspaceTest::createSubscribedGmForWorkspace
 * - CreateSessionZeroTest::createSubscribedGmForSurvey
 * - GmRoleServiceTest::createSubscribedUser
 * - GMProfileTest::createSubscribedUser
 */
trait CreatesUsers
{
    /**
     * Create a user with an active Paddle subscription.
     */
    public function createSubscribedUser(array $overrides = []): User
    {
        $user = User::factory()->create($overrides);

        Cashier::$subscriptionModel::create([
            'billable_type' => get_class($user),
            'billable_id' => $user->id,
            'type' => 'default',
            'paddle_id' => 'sub_'.Str::random(12),
            'status' => 'active',
            'trial_ends_at' => null,
            'paused_at' => null,
            'ends_at' => null,
        ]);

        return $user;
    }

    /**
     * Create a subscribed user with the Game Master role and an active GM profile.
     */
    public function createSubscribedGm(array $userOverrides = [], array $gmOverrides = []): User
    {
        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
            ...$userOverrides,
        ]);

        Cashier::$subscriptionModel::create([
            'billable_type' => get_class($user),
            'billable_id' => $user->id,
            'type' => 'default',
            'paddle_id' => 'sub_'.Str::random(12),
            'status' => 'active',
            'trial_ends_at' => null,
            'paused_at' => null,
            'ends_at' => null,
        ]);

        $user->assignRole('Game Master');

        GMProfile::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
            ...$gmOverrides,
        ]);

        return $user;
    }

    /**
     * Create a subscribed user who does NOT have the GM role or profile.
     */
    public function createSubscribedNonGm(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);

        Cashier::$subscriptionModel::create([
            'billable_type' => get_class($user),
            'billable_id' => $user->id,
            'type' => 'default',
            'paddle_id' => 'sub_'.Str::random(12),
            'status' => 'active',
            'trial_ends_at' => null,
            'paused_at' => null,
            'ends_at' => null,
        ]);

        return $user;
    }

    /**
     * Create a user with a location at the given coordinates.
     */
    public function createUserWithLocation(float $lat, float $lng, array $overrides = []): User
    {
        $location = Location::factory()->create([
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        return User::factory()->create(array_merge([
            'location_id' => $location->id,
            'profile_complete' => true,
            'is_disabled' => false,
        ], $overrides));
    }
}
