<?php

use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Enums\LocalSubscriptionSource;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\LocalSubscription;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\Badges\SupportingMemberEvaluator;
use App\Services\CompMembershipService;
use App\Services\GmRoleService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Traits\InjectsConcurrentDuplicates;

/**
 * CompMembershipService (M069 S03 T2, R083): comp grants must create a
 * flagged local subscription carrying full GM benefits AND the
 * Supporting Member badge, reversibly. The service owns the subscription
 * row only — the badge arrives through the S02 seam (assignGMRole →
 * MembershipActivated after commit → queued BadgePipeline), and
 * QUEUE_CONNECTION=sync (phpunit.xml) runs the pipeline inline, so the
 * assertions below see the badge immediately after grant().
 *
 * Withdraw cancels the row and lapses benefits only when no other
 * active membership remains; badge lapse stays sweep-owned (quiet, then
 * SupportingMemberEvaluator::sweep() stamps it with a Lapsed audit row).
 */
uses(InjectsConcurrentDuplicates::class);

/**
 * Seed/fetch the GM plan (unique prefix: Pest compiles every test file
 * into one process — a redeclared helper name is fatal).
 */
function compMembershipGmPlan(): MembershipType
{
    return MembershipType::updateOrCreate(
        ['name' => 'Game Master'],
        [
            'description' => 'Test GM plan (comp membership fixtures)',
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
 * The user's supporting_member hold row, or null when not held.
 */
function compMembershipHold(): ?BadgeUser
{
    return BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'supporting_member'))
        ->first();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();

    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);

    compMembershipGmPlan();

    // Founding window closed for this suite (the D208 default is open —
    // every fresh user would otherwise also earn founding_table on the
    // very MembershipActivated events under test, via the pipeline's
    // every-evaluator fan-out). These tests pin the comp → Supporting
    // Member pipeline semantics in isolation; FoundingTableEvaluatorTest
    // owns the window logic and the fan-out pin.
    config(['badges.founding_table.closes_at' => '2020-01-01T00:00:00+00:00']);
});

it('grants a permanent comp with full GM benefits and the Supporting Member badge', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();

    $subscription = app(CompMembershipService::class)
        ->grant($user, null, $admin, 'Community recognition comp');

    // Comp provenance on the row; ends_at still null AFTER assignGMRole
    // ran — pins the ordering trap (activateGmSubscription would have
    // wiped it, the comp path must not).
    expect($subscription->source)->toBe(LocalSubscriptionSource::Comp)
        ->and($subscription->status)->toBe('active')
        ->and($subscription->grantedBy->is($admin))->toBeTrue()
        ->and($subscription->reason)->toBe('Community recognition comp')
        ->and($subscription->fresh()->ends_at)->toBeNull()
        ->and(app(GmRoleService::class)->isGmActive($user))->toBeTrue();

    // The badge rode the S02 pipeline: automatic source, tier year_1,
    // evidence pointing at the comp row — exactly one of each row kind.
    $hold = compMembershipHold();

    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBe('year_1')
        ->and($hold->source)->toBe(BadgeSource::Automatic)
        ->and($hold->evidence_type)->toBe('local_subscription')
        ->and($hold->evidence_id)->toBe($subscription->id)
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('preserves a fixed comp period through the role assignment', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();

    $subscription = app(CompMembershipService::class)
        ->grant($user, now()->addMonths(6), $admin, 'Six months on the house');

    $endsAt = $subscription->fresh()->ends_at;

    expect($endsAt)->not->toBeNull()
        ->and(now()->diffInMonths($endsAt))->toBeGreaterThanOrEqual(5);
});

it('updates the comp row in place on re-grant instead of duplicating', function () {
    $service = app(CompMembershipService::class);
    $user = User::factory()->create();
    $admin = User::factory()->create();

    $service->grant($user, null, $admin, 'First grant');
    $service->grant($user, now()->addMonths(3), $admin, 'Converted to fixed period');

    expect($user->localSubscriptions()->count())->toBe(1)
        ->and($user->localSubscriptions()->first()->reason)->toBe('Converted to fixed period')
        ->and($user->localSubscriptions()->first()->ends_at)->not->toBeNull()
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);

    // A re-grant renews, it never rewrites history: the FIRST grant's
    // starts_at survives (the row keeps the original grant date).
    $originalStart = $user->localSubscriptions()->first()->starts_at;
    Carbon::setTestNow(Carbon::parse('+6 months'));

    $service->grant($user, null, $admin, 'Third grant');

    Carbon::setTestNow();

    expect($user->localSubscriptions()->count())->toBe(1)
        ->and($user->localSubscriptions()->first()->starts_at->equalTo($originalStart))->toBeTrue()
        ->and($user->localSubscriptions()->first()->reason)->toBe('Third grant');
});

it('enforces one active row per (user, plan, source) structurally — and allows comp beside self-serve', function () {
    $service = app(CompMembershipService::class);
    $user = User::factory()->create();
    $admin = User::factory()->create();

    $service->grant($user, null, $admin, 'Comp grant');

    $plan = MembershipType::active()
        ->where('type', 'local')
        ->whereJsonContains('metadata->gm_plan', true)
        ->firstOrFail();

    // Same source, second active row: the partial unique index refuses
    // it outright — the double-grant/double-click race can never land.
    // Own savepoint so the violation cannot abort the test transaction.
    expect(function () use ($user, $plan): void {
        DB::transaction(fn () => $user->localSubscriptions()->create([
            'membership_type_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'source' => LocalSubscriptionSource::Comp,
        ]));
    })->toThrow(QueryException::class);

    // Different source on the same plan: allowed — that is the "second
    // active membership remains" semantics the expiry sweep depends on.
    $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
        'source' => LocalSubscriptionSource::SelfServe,
    ]);

    expect($user->localSubscriptions()->active()->count())->toBe(2);

    // Expired history stays unlimited: expire the comp, re-grant — the
    // expired row is history, the fresh comp is the only active comp.
    $expired = $user->localSubscriptions()->where('source', LocalSubscriptionSource::Comp)->first();
    $expired->update(['status' => 'expired']);
    $service->grant($user, null, $admin, 'Fresh comp after expiry');

    expect($user->localSubscriptions()->count())->toBe(3)
        ->and($user->localSubscriptions()->active()->where('source', LocalSubscriptionSource::Comp->value)->count())->toBe(1);
});

it('withdraws the comp: benefits lapse, the badge follows only via the sweep', function () {
    $service = app(CompMembershipService::class);
    $gmRoleService = app(GmRoleService::class);
    $user = User::factory()->create();
    $admin = User::factory()->create();

    $service->grant($user, null, $admin, 'Community recognition comp');

    expect($service->withdraw($user, $admin, 'Conduct issue'))->toBeTrue();

    $canceled = $user->localSubscriptions()->first();

    expect($canceled->status)->toBe('canceled')
        ->and($canceled->canceled_at)->not->toBeNull()
        // Grant reason stays on the row for forensics.
        ->and($canceled->reason)->toBe('Community recognition comp')
        ->and($gmRoleService->isGmActive($user))->toBeFalse();

    // Lapse is sweep-owned: the hold survives withdraw with lapsed_at
    // null until SupportingMemberEvaluator::sweep() runs (R083: badge
    // follows membership state).
    $hold = compMembershipHold();

    expect($hold)->not->toBeNull()
        ->and($hold->lapsed_at)->toBeNull();

    app(SupportingMemberEvaluator::class)->sweep();

    $lapsed = compMembershipHold();
    $audit = BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->first();

    expect($lapsed->lapsed_at)->not->toBeNull()
        ->and($audit)->not->toBeNull();
});

it('keeps GM benefits on withdraw when another active membership remains', function () {
    $service = app(CompMembershipService::class);
    $gmRoleService = app(GmRoleService::class);
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $plan = compMembershipGmPlan();

    $service->grant($user, null, $admin, 'Community recognition comp');

    // A second, self-serve membership on the same plan.
    $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    expect($service->withdraw($user, $admin, 'Comp withdrawn, self-serve remains'))->toBeTrue()
        ->and($user->localSubscriptions()->where('source', LocalSubscriptionSource::Comp)->first()->status)->toBe('canceled')
        ->and($gmRoleService->isGmActive($user))->toBeTrue();
});

it('returns false from withdraw when there is no active comp', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();

    expect(app(CompMembershipService::class)->withdraw($user, $admin, 'Nothing to withdraw'))->toBeFalse();
});

it('throws and writes nothing when the GM plan is not seeded', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();

    compMembershipGmPlan()->delete();

    app(CompMembershipService::class)->grant($user, null, $admin, 'No plan to grant on');
})->throws(RuntimeException::class);

it('writes no rows when the GM plan is missing', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();

    compMembershipGmPlan()->delete();

    try {
        app(CompMembershipService::class)->grant($user, null, $admin, 'No plan to grant on');
        $this->fail('grant should have thrown without the GM plan');
    } catch (RuntimeException) {
        // Expected — assert the failure wrote nothing at all.
    }

    expect($user->localSubscriptions()->count())->toBe(0)
        ->and(LocalSubscription::query()->count())->toBe(0)
        ->and(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

// ── Converge-on-violation backstop (partial unique index race) ──

it('converges when a concurrent grant wins the insert race — one active comp row, winner semantics preserved', function () {
    $plan = compMembershipGmPlan();
    $user = User::factory()->create();
    $admin = User::factory()->create();

    // The lost race: a conflicting active comp row lands between the
    // locked read and the INSERT — the loser's insert hits the real
    // partial unique index and must converge, not 500.
    $disarm = $this->injectDuplicateOnCreate(LocalSubscription::class, fn () => [
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'membership_type_id' => $plan->id,
        'status' => 'active',
        'source' => LocalSubscriptionSource::Comp->value,
        'starts_at' => now()->subDays(3)->toDateTimeString(),
        'ends_at' => null,
        'canceled_at' => null,
        'granted_by' => $admin->id,
        'reason' => 'concurrent winner',
    ]);

    try {
        $subscription = app(CompMembershipService::class)
            ->grant($user, now()->addMonths(3), $admin, 'loser grant that must converge');
    } finally {
        $disarm();
    }

    // The catch converged instead of surfacing a 500: exactly one
    // active comp row carrying the caller's reason/period, returned to
    // the caller. (The injected winner rolls back with the loser's
    // savepoint — the trait's documented parity limitation — so the
    // retry re-creates from scratch with starts_at=now; winner-row
    // starts_at survival is therefore not assertable through this
    // harness, only through the re-grant tests above.)
    $active = $user->localSubscriptions()
        ->where('source', LocalSubscriptionSource::Comp)
        ->where('status', 'active')
        ->get();

    expect($active)->toHaveCount(1)
        ->and($active->first()->reason)->toBe('loser grant that must converge')
        ->and($active->first()->ends_at?->isSameDay(now()->addMonths(3)))->toBeTrue()
        ->and($subscription->is($active->first()))->toBeTrue();
});

it('rejects a past period end instead of writing a benefitless active comp', function () {
    $plan = compMembershipGmPlan();
    $user = User::factory()->create();
    $admin = User::factory()->create();

    try {
        app(CompMembershipService::class)->grant($user, now()->subDay(), $admin, 'stale period');
        $this->fail('grant should refuse a past ends_at');
    } catch (InvalidArgumentException) {
        // Loud refusal, no rows, no role.
    }

    expect($user->localSubscriptions()->count())->toBe(0)
        ->and($user->isGM())->toBeFalse();
});
