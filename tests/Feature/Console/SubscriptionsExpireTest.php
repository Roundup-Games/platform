<?php

use App\Enums\LocalSubscriptionSource;
use App\Events\MembershipEnded;
use App\Models\LocalSubscription;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\CompMembershipService;
use App\Services\GmRoleService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
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

it('laps benefits on the LIVE membership state when a Paddle webhook cancels between snapshot and decision', function () {
    $gmRoleService = app(GmRoleService::class);
    $user = User::factory()->create();
    $admin = User::factory()->create();

    // A due comp row carrying GM benefits (granted live, then aged
    // past its period — the row state the 03:15 sweep finds).
    $subscription = app(CompMembershipService::class)
        ->grant($user, now()->addMonth(), $admin, 'Community recognition comp');
    expect($gmRoleService->isGmActive($user))->toBeTrue();
    $subscription->update(['ends_at' => now()->subDay()]);

    // …plus an ACTIVE Cashier subscription when the due set loads: the
    // eager snapshot says "still a member".
    $user->subscriptions()->create([
        'type' => 'default',
        'paddle_id' => 'ctn_test_'.str()->random(10),
        'status' => 'active',
    ]);

    // The webhook races in between the snapshot and the per-row
    // transaction: the moment the sweep opens the row's transaction,
    // the Cashier sub cancels — before the conditional flip (the row
    // itself stays due, so the flip proceeds) but before the membership
    // decision. TransactionBeginning fires once per DB::transaction,
    // so a one-shot armed listener lands exactly in that window.
    $raceFired = false;
    Event::listen(TransactionBeginning::class, function () use (&$raceFired, $user): void {
        if ($raceFired) {
            return;
        }

        $raceFired = true;

        $user->subscriptions()->update([
            'status' => 'canceled',
            'ends_at' => now(),
        ]);
    });

    $this->artisan('subscriptions:expire')->assertSuccessful();

    // Live state at decision time: no active membership anywhere — the
    // stale snapshot would have kept the GM benefits stranded forever
    // (the due query never revisits an expired row).
    expect($subscription->fresh()->status)->toBe('expired')
        ->and($gmRoleService->isGmActive($user))->toBeFalse()
        ->and($user->fresh()->hasRole('Game Master'))->toBeFalse();
});

it('skips a row a comp re-grant renewed between snapshot and flip (conditional expiry)', function () {
    $gmRoleService = app(GmRoleService::class);
    $user = User::factory()->create();
    $admin = User::factory()->create();

    $subscription = subscriptionsExpireAgedComp($user, $admin);
    // Aged past its period but not yet swept: the role is still held
    // (isGmActive is false only because scopeActive hides the due row).
    expect($user->fresh()->hasRole('Game Master'))->toBeTrue();

    Event::fake([MembershipEnded::class]);

    // An admin re-grant lands between the snapshot and the per-row
    // transaction: CompMembershipService::persistGrant RENEWS the
    // in-place row (ends_at into the future, still status=active). The
    // sweep's flip must be conditional on live due-ness — an
    // unconditional flip would expire the fresh grant, lapse benefits
    // off it, and dispatch a false MembershipEnded.
    $raceFired = false;
    Event::listen(TransactionBeginning::class, function () use (&$raceFired, $subscription): void {
        if ($raceFired) {
            return;
        }

        $raceFired = true;

        $subscription->update(['ends_at' => now()->addMonths(3)]);
    });

    // The command output must report what the sweep actually did: a
    // declined flip expired nothing — zero, not one.
    $this->artisan('subscriptions:expire')
        ->expectsOutputToContain('0 expired, 0 GM benefit lapse(s)')
        ->assertSuccessful();

    expect($subscription->fresh()->status)->toBe('active')
        ->and($subscription->fresh()->ends_at)->isFuture()
        ->and($gmRoleService->isGmActive($user))->toBeTrue();

    Event::assertNotDispatched(MembershipEnded::class);
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

it('skips a row whose processing throws and still sweeps the rest (per-row failure isolation)', function () {
    $admin = User::factory()->create();

    // Created first so the un-ordered due query yields the poisoned row
    // ahead of the healthy one — the sweep must survive past it.
    $failing = User::factory()->create();
    $failingSub = subscriptionsExpireAgedComp($failing, $admin);

    $healthy = User::factory()->create();
    $healthySub = subscriptionsExpireAgedComp($healthy, $admin);

    $thrownFor = [];
    $this->partialMock(GmRoleService::class)
        ->shouldReceive('handleSubscriptionLapse')
        ->andReturnUsing(function (User $user) use ($failing, &$thrownFor): bool {
            // Transient failure: throws exactly once for the poisoned
            // row (run 1), succeeds for everyone and on run 2 — the
            // "next night picks it up" convergence below.
            if ($user->is($failing) && ! in_array($user->id, $thrownFor, true)) {
                $thrownFor[] = $user->id;

                throw new RuntimeException('lapse exploded');
            }

            return true;
        });

    // The documented always-SUCCESS contract: the throw is logged and
    // skipped, and the healthy row behind it still expires.
    $this->artisan('subscriptions:expire')->assertSuccessful();

    // Flip and lapse are one transaction: the poisoned row rolled back
    // whole (still active, still due tomorrow — a committed flip whose
    // lapse threw would strand the user's GM benefits forever, because
    // the due query can never re-find an expired row), the healthy row
    // expired regardless.
    expect($failingSub->fresh()->status)->toBe('active')
        ->and($healthySub->fresh()->status)->toBe('expired');

    // Convergence: once the transient failure is gone, the next night's
    // sweep finds the still-due row again and completes it.
    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($failingSub->fresh()->status)->toBe('expired');
});
