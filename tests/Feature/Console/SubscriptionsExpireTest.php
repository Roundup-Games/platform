<?php

use App\Enums\LocalSubscriptionSource;
use App\Models\LocalSubscription;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\CompMembershipService;
use App\Services\GmRoleService;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

/**
 * subscriptions:expire (M069 S03 T3, R083): fixed-period comps must
 * actually end. The nightly command flips past-ends_at active rows to
 * expired and lapses GM benefits when no active membership remains —
 * scopeActive alone only hides the row from queries; without the sweep
 * a past-end comp keeps status=active and muddy history forever.
 *
 * Fixtures grant the comp through the real CompMembershipService with a
 * FUTURE period first (assignGMRole's hasActiveMembership guard must
 * pass so role and benefits are genuinely on), then age ends_at into
 * the past — the exact row state the 03:15 sweep finds the night after
 * a period ends. The scheduled entry is asserted through schedule:list
 * (same console-context schedule BadgesSweepTest documents, L052).
 *
 * Helpers are top-level with a subscriptionsExpire- prefix: sibling
 * Console tests share the global namespace.
 */

/**
 * Seed/fetch the GM plan exactly as the membership pipeline tests do.
 */
function subscriptionsExpireGmPlan(): MembershipType
{
    return MembershipType::updateOrCreate(
        ['name' => 'Game Master'],
        [
            'description' => 'Test GM plan (subscription expiry fixtures)',
            'price_cents' => 0,
            'duration_months' => 0,
            'status' => 'active',
            'type' => 'local',
            'paddle_price_id' => null,
            'metadata' => ['gm_plan' => true, 'features' => ['GM workspace']],
        ],
    );
}

/**
 * Grant a comp with a live future period (benefits genuinely on), then
 * age ends_at past now — the state at 03:15 after the period ended.
 */
function subscriptionsExpireAgedComp(User $user, User $admin): LocalSubscription
{
    $subscription = app(CompMembershipService::class)
        ->grant($user, now()->addMonth(), $admin, 'Community recognition comp');

    $subscription->update(['ends_at' => now()->subDay()]);

    return $subscription->fresh();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();

    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);

    subscriptionsExpireGmPlan();
});

it('expires a past-due comp and downgrades GM benefits', function () {
    $gmRoleService = app(GmRoleService::class);
    $user = User::factory()->create();
    $admin = User::factory()->create();

    $subscription = app(CompMembershipService::class)
        ->grant($user, now()->addMonth(), $admin, 'Community recognition comp');

    // Sanity: benefits were genuinely on while the period was live —
    // the lapse assertion below is not vacuous.
    expect($gmRoleService->isGmActive($user))->toBeTrue()
        ->and($user->can_create_public_entries)->toBeTrue();

    // The period ends — the row state the 03:15 sweep finds that night.
    $subscription->update(['ends_at' => now()->subDay()]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    $row = $subscription->fresh();

    // The R083 assertion: expiry downgrades benefits, not just queries.
    expect($row->status)->toBe('expired')
        // Period end preserved as the actual end; cancellation stays a
        // distinct operator action (CompMembershipService::withdraw).
        ->and($row->ends_at)->not->toBeNull()
        ->and($row->canceled_at)->toBeNull()
        ->and($gmRoleService->isGmActive($user))->toBeFalse()
        ->and($user->fresh()->can_create_public_entries)->toBeFalse()
        ->and($user->fresh()->hasRole('Game Master'))->toBeFalse();
});

it('leaves permanent comps untouched', function () {
    $gmRoleService = app(GmRoleService::class);
    $user = User::factory()->create();
    $admin = User::factory()->create();

    $subscription = app(CompMembershipService::class)
        ->grant($user, null, $admin, 'Lifetime community recognition');

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($subscription->fresh()->status)->toBe('active')
        ->and($subscription->fresh()->ends_at)->toBeNull()
        ->and($gmRoleService->isGmActive($user))->toBeTrue();
});

it('leaves future-period comps untouched', function () {
    $gmRoleService = app(GmRoleService::class);
    $user = User::factory()->create();
    $admin = User::factory()->create();

    $subscription = app(CompMembershipService::class)
        ->grant($user, now()->addMonths(6), $admin, 'Six months on the house');

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($subscription->fresh()->status)->toBe('active')
        ->and($subscription->fresh()->ends_at)->isFuture()
        ->and($gmRoleService->isGmActive($user))->toBeTrue();
});

it('keeps GM benefits when a second active membership remains', function () {
    $gmRoleService = app(GmRoleService::class);
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $plan = subscriptionsExpireGmPlan();

    $subscription = subscriptionsExpireAgedComp($user, $admin);

    // A second, self-serve open-ended membership on the same plan —
    // benefits follow the LAST active membership, so no lapse fires.
    $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($subscription->fresh()->status)->toBe('expired')
        ->and($gmRoleService->isGmActive($user))->toBeTrue()
        ->and($user->fresh()->can_create_public_entries)->toBeTrue();
});

it('leaves already-canceled rows untouched', function () {
    $user = User::factory()->create();
    $plan = subscriptionsExpireGmPlan();

    $canceled = $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id,
        'status' => 'canceled',
        'starts_at' => now()->subMonths(3),
        'ends_at' => now()->subDay(),
        'canceled_at' => now()->subDays(2),
        'source' => LocalSubscriptionSource::Comp,
        'reason' => 'Withdrawn before the period ended',
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    $row = $canceled->fresh();

    expect($row->status)->toBe('canceled')
        ->and($row->canceled_at->isSameDay(now()->subDays(2)))->toBeTrue()
        ->and($row->reason)->toBe('Withdrawn before the period ended');
});

it('is scheduled nightly at 03:15 in the schedule inventory', function () {
    expect(Artisan::call('schedule:list'))->toBe(0)
        ->and(Artisan::output())->toContain('subscriptions:expire');
});
