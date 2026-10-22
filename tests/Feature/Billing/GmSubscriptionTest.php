<?php

use App\Enums\LocalSubscriptionSource;
use App\Events\MembershipEnded;
use App\Models\GMProfile;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\GmRoleService;
use Illuminate\Support\Facades\Event;
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

    it('preserves the original starts_at across a deactivate/reactivate cycle (tenure history)', function () {
        $user = User::factory()->create();
        $service = app(GmRoleService::class);

        $service->activateGmSubscription($user);
        $originalStart = $user->localSubscriptions()->first()->starts_at;

        $service->deactivateGmSubscription($user);

        // A churned supporter returns a year later: the single physical
        // row reactivates, and its start date must survive (the comp
        // path's "renews, never rewrites history" rule) —
        // SupportingMemberEvaluator reads the EARLIEST starts_at for
        // tenure tiers, so a rewritten start would erase the history.
        $this->travelTo(now()->addYear());

        $service->activateGmSubscription($user);

        $row = $user->localSubscriptions()->first();
        expect($row->status)->toBe('active')
            ->and($row->starts_at->equalTo($originalStart))->toBeTrue();
    });

    it('dispatches MembershipEnded when deactivation ends the last membership', function () {
        Event::fake([MembershipEnded::class]);

        $user = User::factory()->create();
        $service = app(GmRoleService::class);
        $service->activateGmSubscription($user);

        $service->deactivateGmSubscription($user);

        // The positive dispatch pin: the event is the contract future
        // consumers hang off (only its non-dispatch was pinned before).
        Event::assertDispatched(MembershipEnded::class, fn (MembershipEnded $event) => $event->user->is($user));
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

// ── Comp coexistence (review pass 4: source-scoped cancel) ─────

describe('deactivateGmSubscription with comp coexistence', function () {
    it('cancels only the self-serve row — an active comp keeps benefits alive', function () {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $service = app(GmRoleService::class);
        $plan = MembershipType::where('metadata->gm_plan', true)->firstOrFail();

        $service->activateGmSubscription($user);

        $comp = $user->localSubscriptions()->create([
            'membership_type_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
            'source' => LocalSubscriptionSource::Comp,
            'granted_by' => $admin->id,
            'reason' => 'ops comp',
        ]);

        expect($service->deactivateGmSubscription($user))->toBeTrue();

        $selfServe = $user->localSubscriptions()
            ->where('source', LocalSubscriptionSource::SelfServe)->first();
        expect($selfServe->fresh()->status)->toBe('canceled')
            // The admin grant survived the user's cancel untouched.
            ->and($comp->fresh()->status)->toBe('active')
            ->and($comp->fresh()->canceled_at)->toBeNull()
            // Benefits follow the surviving comp.
            ->and($user->fresh()->isGM())->toBeTrue()
            ->and($user->fresh()->can_create_public_entries)->toBeTrue()
            ->and($user->gmProfile->fresh()->is_active)->toBeTrue();
    });

    it('does not dispatch MembershipEnded while another membership remains', function () {
        Event::fake([MembershipEnded::class]);

        $user = User::factory()->create();
        $admin = User::factory()->create();
        $service = app(GmRoleService::class);
        $plan = MembershipType::where('metadata->gm_plan', true)->firstOrFail();

        $service->activateGmSubscription($user);
        $user->localSubscriptions()->create([
            'membership_type_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
            'source' => LocalSubscriptionSource::Comp,
            'granted_by' => $admin->id,
            'reason' => 'ops comp',
        ]);

        $service->deactivateGmSubscription($user);

        Event::assertNotDispatched(MembershipEnded::class);
    });

    it('touches nothing for a comp-only user: the grant is not cancellable here', function () {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $service = app(GmRoleService::class);
        $plan = MembershipType::where('metadata->gm_plan', true)->firstOrFail();

        $comp = $user->localSubscriptions()->create([
            'membership_type_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
            'source' => LocalSubscriptionSource::Comp,
            'granted_by' => $admin->id,
            'reason' => 'ops comp',
        ]);
        $service->assignGMRole($user);

        expect($service->deactivateGmSubscription($user))->toBeFalse()
            ->and($comp->fresh()->status)->toBe('active')
            ->and($user->fresh()->isGM())->toBeTrue()
            ->and($user->fresh()->can_create_public_entries)->toBeTrue();
    });
});
