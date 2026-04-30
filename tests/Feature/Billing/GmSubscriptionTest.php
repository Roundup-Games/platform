<?php

use App\Models\GMProfile;
use App\Models\LocalSubscription;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\GmRoleService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Ensure the GM role exists
    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);

    // Create the GM membership plan
    MembershipType::updateOrCreate(
        ['name' => 'Game Master'],
        [
            'description' => 'Test GM plan',
            'price_cents' => 0,
            'duration_months' => 0,
            'status' => 'active',
            'type' => 'local',
            'paddle_price_id' => null,
            'metadata' => ['gm_plan' => true, 'features' => ['GM workspace']],
        ],
    );
});

describe('GmRoleService', function () {
    it('activates GM subscription and assigns role', function () {
        $user = User::factory()->create();
        $service = app(GmRoleService::class);

        $result = $service->activateGmSubscription($user);

        expect($result)->toBeTrue();
        expect($user->fresh()->isGM())->toBeTrue();
        expect($user->gmProfile)->not->toBeNull();
        expect($user->gmProfile->is_active)->toBeTrue();
        expect($user->localSubscriptions()->active()->count())->toBe(1);
    })->group('smoke');

    it('creates a GMProfile on activation', function () {
        $user = User::factory()->create();
        expect($user->gmProfile)->toBeNull();

        app(GmRoleService::class)->activateGmSubscription($user);

        $freshUser = $user->fresh();
        expect($freshUser->gmProfile)->not->toBeNull();
        expect($freshUser->gmProfile->is_active)->toBeTrue();
    });

    it('is idempotent — safe to activate twice', function () {
        $user = User::factory()->create();
        $service = app(GmRoleService::class);

        $service->activateGmSubscription($user);
        $result = $service->activateGmSubscription($user);

        expect($result)->toBeTrue();
        expect($user->fresh()->isGM())->toBeTrue();
        expect($user->localSubscriptions()->count())->toBe(1); // no duplicates
    });

    it('deactivates GM subscription and revokes role', function () {
        $user = User::factory()->create();
        $service = app(GmRoleService::class);

        $service->activateGmSubscription($user);
        expect($user->fresh()->isGM())->toBeTrue();

        $service->deactivateGmSubscription($user);

        expect($user->fresh()->isGM())->toBeFalse();
        expect($user->gmProfile->fresh()->is_active)->toBeFalse();
        $subscription = $user->localSubscriptions()->first();
        expect($subscription->status)->toBe('canceled');
        expect($subscription->canceled_at)->not->toBeNull();
    });

    it('preserves GMProfile and reviews on deactivation', function () {
        $user = User::factory()->create();
        $service = app(GmRoleService::class);
        $service->activateGmSubscription($user);
        $profileId = $user->gmProfile->id;

        $service->deactivateGmSubscription($user);

        // Profile still exists, just inactive
        expect(GMProfile::find($profileId))->not->toBeNull();
        expect($user->gmProfile->fresh()->is_active)->toBeFalse();
    });

    it('reactivates after deactivation', function () {
        $user = User::factory()->create();
        $service = app(GmRoleService::class);

        $service->activateGmSubscription($user);
        $service->deactivateGmSubscription($user);
        expect($user->fresh()->isGM())->toBeFalse();

        $service->activateGmSubscription($user);

        expect($user->fresh()->isGM())->toBeTrue();
        expect($user->gmProfile->fresh()->is_active)->toBeTrue();
        $subscription = $user->localSubscriptions()->first();
        expect($subscription->status)->toBe('active');
        expect($subscription->canceled_at)->toBeNull();
    });
});

describe('User model GM helpers', function () {
    it('hasGmSubscription returns true when active local GM sub exists', function () {
        $user = User::factory()->create();
        expect($user->hasGmSubscription())->toBeFalse();

        app(GmRoleService::class)->activateGmSubscription($user);

        expect($user->fresh()->hasGmSubscription())->toBeTrue();
    });

    it('hasGmSubscription returns false when GM sub is canceled', function () {
        $user = User::factory()->create();
        $service = app(GmRoleService::class);
        $service->activateGmSubscription($user);
        $service->deactivateGmSubscription($user);

        expect($user->fresh()->hasGmSubscription())->toBeFalse();
    });

    it('hasActiveMembership includes local subscriptions', function () {
        $user = User::factory()->create();
        expect($user->hasActiveMembership())->toBeFalse();

        app(GmRoleService::class)->activateGmSubscription($user);

        expect($user->fresh()->hasActiveMembership())->toBeTrue();
    });

    it('isGM checks role, not subscription', function () {
        $user = User::factory()->create();
        expect($user->isGM())->toBeFalse();

        // Assign role directly without subscription
        $role = Role::where('name', 'Game Master')->first();
        $user->assignRole($role);

        expect($user->fresh()->isGM())->toBeTrue();
    });
});

describe('LocalSubscription model', function () {
    it('isGmPlan detects GM membership type', function () {
        $user = User::factory()->create();
        app(GmRoleService::class)->activateGmSubscription($user);

        $sub = $user->localSubscriptions()->first();
        expect($sub->isGmPlan())->toBeTrue();
    });

    it('active scope filters correctly', function () {
        $user = User::factory()->create();
        $service = app(GmRoleService::class);
        $service->activateGmSubscription($user);

        expect(LocalSubscription::active()->count())->toBe(1);

        $service->deactivateGmSubscription($user);

        expect(LocalSubscription::active()->count())->toBe(0);
    });
});
