<?php

use App\Livewire\Billing\BillingPortal;
use App\Livewire\Billing\MembershipPage;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\GmRoleService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);

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

    URL::defaults(['locale' => 'en']);
});

describe('MembershipPage', function () {
    it('activates GM subscription via initiateCheckout for local plan', function () {
        $user = User::factory()->create();

        $gmPlan = MembershipType::where('type', 'local')->first();

        Livewire::actingAs($user)
            ->test(MembershipPage::class)
            ->call('initiateCheckout', $gmPlan->id);

        // Verify the user's GM state was actually changed
        expect($user->fresh()->isGM())->toBeTrue();
        expect($user->hasGmSubscription())->toBeTrue();
    })->group('smoke');

    it('rejects duplicate GM activation', function () {
        $user = User::factory()->create();
        $gmPlan = MembershipType::where('type', 'local')->first();

        // Activate once
        app(GmRoleService::class)->activateGmSubscription($user);

        Livewire::actingAs($user)
            ->test(MembershipPage::class)
            ->call('initiateCheckout', $gmPlan->id)
            ->assertSee(__('billing.error_you_already_have_a_gm_subscription'));
    })->group('smoke');

    it('passes gmSubscription to view when active', function () {
        $user = User::factory()->create();
        app(GmRoleService::class)->activateGmSubscription($user);

        $component = Livewire::actingAs($user)
            ->test(MembershipPage::class);

        $component->assertViewHas('gmSubscription');
        $gmSub = $component->viewData('gmSubscription');
        expect($gmSub)->not->toBeNull();
        expect($gmSub->isActive())->toBeTrue();
    });
});

describe('BillingPortal', function () {
    it('cancels GM subscription', function () {
        $user = User::factory()->create();
        app(GmRoleService::class)->activateGmSubscription($user);
        expect($user->fresh()->isGM())->toBeTrue();

        Livewire::actingAs($user)
            ->test(BillingPortal::class)
            ->call('cancelGmSubscription')
            ->assertSee(__('billing.content_gm_subscription_canceled'));

        expect($user->fresh()->isGM())->toBeFalse();
    });

    it('reactivates canceled GM subscription', function () {
        $user = User::factory()->create();
        $service = app(GmRoleService::class);
        $service->activateGmSubscription($user);
        $service->deactivateGmSubscription($user);
        expect($user->fresh()->isGM())->toBeFalse();

        Livewire::actingAs($user)
            ->test(BillingPortal::class)
            ->call('reactivateGmSubscription')
            ->assertSee(__('billing.content_gm_subscription_reactivated'));

        expect($user->fresh()->isGM())->toBeTrue();
    });

    it('rejects cancellation when no active GM subscription', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BillingPortal::class)
            ->call('cancelGmSubscription')
            ->assertSee(__('billing.error_no_active_gm_subscription_to_cancel'));
    });
});
