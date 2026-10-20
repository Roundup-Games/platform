<?php

use App\Enums\BadgeEventAction;
use App\Events\MembershipActivated;
use App\Events\MembershipEnded;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\Badges\SupportingMemberEvaluator;
use App\Services\GmRoleService;
use Illuminate\Contracts\Events\Dispatcher;
use Spatie\Permission\Models\Role;

/**
 * The membership → badge award pipeline (M069 S02 T2, spec §2.2/§2.5):
 * GmRoleService dispatches membership domain events after commit, the
 * queued BadgePipeline listener runs every registered evaluator, and
 * SupportingMemberEvaluator proposes the tier the user's full support
 * history has earned. QUEUE_CONNECTION=sync (phpunit.xml) runs the
 * queued listener inline, so every dispatch below is synchronous —
 * that is what makes "instantly awarded" assertable.
 *
 * Lapse/requalification semantics on display here are the sweep-side
 * counterpart of the BadgeServiceTest engine tests.
 */

/**
 * Seed/fetch the GM plan exactly as GmSubscriptionTest does.
 */
function membershipBadgeGmPlan(): MembershipType
{
    return MembershipType::updateOrCreate(
        ['name' => 'Game Master'],
        [
            'description' => 'Test GM plan (membership badge pipeline fixtures)',
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
function membershipBadgeSupportingHold(): ?BadgeUser
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

    membershipBadgeGmPlan();

    // Founding window closed for this suite (the D208 default is open —
    // every fresh user would otherwise also earn founding_table on the
    // very MembershipActivated events under test, via the pipeline's
    // every-evaluator fan-out). These tests pin the Supporting Member
    // pipeline semantics in isolation; FoundingTableEvaluatorTest owns
    // the window logic and the fan-out pin.
    config(['badges.founding_table.closes_at' => '2020-01-01T00:00:00+00:00']);
});

it('awards Supporting Member instantly when a GM subscription is activated', function () {
    $user = User::factory()->create();

    expect(app(GmRoleService::class)->activateGmSubscription($user))->toBeTrue();

    $subscription = $user->localSubscriptions()->first();
    $hold = membershipBadgeSupportingHold();

    // The slice-demo equivalence: badge_user exists immediately after
    // activation, no sweep involved — exactly one shelf row + one audit row.
    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBe('year_1')
        // Evidence pointer via the D170 morph alias, never the FQCN.
        ->and($hold->evidence_type)->toBe('local_subscription')
        ->and($hold->evidence_id)->toBe($subscription->id)
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->first()->action)->toBe(BadgeEventAction::Awarded);
});

it('awards Supporting Member through the assignGMRole path (Paddle-path equivalence)', function () {
    $user = User::factory()->create();
    $plan = membershipBadgeGmPlan();

    $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    // The Paddle webhook funnel: activation lands in assignGMRole, and
    // the event fires from the same service — the pipeline cannot tell
    // the local and Paddle paths apart, by design.
    expect(app(GmRoleService::class)->assignGMRole($user))->toBeTrue();

    $hold = membershipBadgeSupportingHold();

    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBe('year_1')
        ->and($hold->evidence_type)->toBe('local_subscription')
        ->and($hold->evidence_id)->toBe($user->localSubscriptions()->first()->id);
});

it('never awards Supporting Member without an active membership', function () {
    $user = User::factory()->create();

    MembershipActivated::dispatch($user);

    // The evaluator gate: no membership → no proposal → no writes at all.
    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('is idempotent: a repeated activation event writes nothing new', function () {
    $user = User::factory()->create();

    app(GmRoleService::class)->activateGmSubscription($user);

    // A duplicate event (activation followed by role assignment on the
    // same membership both dispatch) must land in AlreadyHeld — one row
    // of each kind, never two.
    MembershipActivated::dispatch($user);

    expect(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('does not lapse on deactivation — the nightly sweep owns lapses', function () {
    $service = app(GmRoleService::class);
    $user = User::factory()->create();

    $service->activateGmSubscription($user);
    $service->deactivateGmSubscription($user);

    $hold = membershipBadgeSupportingHold();

    // Deactivation dispatched MembershipEnded, but no badge listener
    // exists for it (spec §4: lapse is quiet and sweep-owned).
    expect($hold->lapsed_at)->toBeNull()
        ->and(BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->count())->toBe(0);

    app(SupportingMemberEvaluator::class)->sweep();

    $lapsed = membershipBadgeSupportingHold();
    $audit = BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->first();

    expect($lapsed->lapsed_at)->not->toBeNull()
        ->and($lapsed->revoked_at)->toBeNull()
        ->and($audit)->not->toBeNull()
        ->and($audit->reason)->toBe('membership_inactive');
});

it('pins tenure tiers to completed months of support history', function (int $months, string $tier) {
    // Frozen clock: subMonths(N) is exactly N completed months when the
    // evaluator's now() is the same instant, so the boundaries are exact.
    $this->freezeTime();

    $user = User::factory()->create();
    $plan = membershipBadgeGmPlan();

    $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now()->subMonths($months),
    ]);

    MembershipActivated::dispatch($user);

    expect(membershipBadgeSupportingHold()?->tier)->toBe($tier);
})->with([
    '11 completed months → year_1' => [11, 'year_1'],
    '12 completed months → year_2' => [12, 'year_2'],
    '24 completed months → year_3' => [24, 'year_3'],
    '60 completed months → year_5' => [60, 'year_5'],
]);

it('upgrades the tier in place when the sweep finds longer tenure', function () {
    $service = app(GmRoleService::class);
    $user = User::factory()->create();

    $service->activateGmSubscription($user); // year_1 at activation

    // Backdate the support history past the year_2 threshold — 13 full
    // months; the buffer day absorbs clock drift between fixture and sweep.
    $user->localSubscriptions()->update(['starts_at' => now()->subMonths(13)->subDay()]);

    app(SupportingMemberEvaluator::class)->sweep();

    expect(membershipBadgeSupportingHold()?->tier)->toBe('year_2')
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->where('action', BadgeEventAction::Upgraded)->count())->toBe(1);
});

it('re-qualifies a lapsed Supporting Member on rejoin through the pipeline', function () {
    $service = app(GmRoleService::class);
    $user = User::factory()->create();

    $service->activateGmSubscription($user);

    // Distinct created_at per phase: badge_events timestamps are
    // second-precision, so ties would make the sequence below ambiguous.
    $this->travel(1)->seconds();
    $service->deactivateGmSubscription($user);
    app(SupportingMemberEvaluator::class)->sweep(); // quiet lapse

    expect(membershipBadgeSupportingHold()->lapsed_at)->not->toBeNull();

    $this->travel(1)->seconds();
    $service->activateGmSubscription($user); // rejoin → event → pipeline

    $hold = membershipBadgeSupportingHold();

    $actions = BadgeEvent::query()
        ->orderBy('created_at')
        ->get()
        ->map(fn (BadgeEvent $event) => $event->action)
        ->all();

    expect($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and($hold->tier)->toBe('year_1')
        ->and($actions)->toBe([
            BadgeEventAction::Awarded,
            BadgeEventAction::Lapsed,
            BadgeEventAction::Requalified,
        ]);
});

it('requalifies a lapsed year-tier holder at their CURRENT tenure tier — requalification can downgrade', function () {
    $service = app(GmRoleService::class);
    $user = User::factory()->create();

    $service->activateGmSubscription($user);

    // Backdate past the year_2 threshold, then sweep the tier up.
    $user->localSubscriptions()->update(['starts_at' => now()->subMonths(13)->subDay()]);
    app(SupportingMemberEvaluator::class)->sweep();

    expect(membershipBadgeSupportingHold()->tier)->toBe('year_2');

    // Quiet lapse (sweep-owned), then a rejoin whose tenure restarted —
    // the requalify path stamps the CURRENT tier, so the year_2 hold
    // downgrades to year_1: requalification reflects standing as of the
    // rejoin, never a grandfathered peak.
    $this->travel(1)->seconds();
    $service->deactivateGmSubscription($user);
    app(SupportingMemberEvaluator::class)->sweep();

    expect(membershipBadgeSupportingHold()->lapsed_at)->not->toBeNull();

    $user->localSubscriptions()->update(['starts_at' => now()]);

    $this->travel(1)->seconds();
    $service->activateGmSubscription($user); // event → pipeline

    $hold = membershipBadgeSupportingHold();

    expect($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and($hold->tier)->toBe('year_1');
});

it('skips the pipeline quietly when the user no longer exists', function () {
    $user = User::factory()->create();
    $plan = membershipBadgeGmPlan();

    $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now()->subMonth(),
    ]);

    $user->delete();

    // Deleted between dispatch and delivery: skip quietly, never throw.
    MembershipActivated::dispatch($user);

    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('registers the BadgePipeline listener exactly once for MembershipActivated (auto-discovery is the only wiring)', function () {
    $targets = collect(app(Dispatcher::class)->getListeners(MembershipActivated::class))
        ->map(function (callable $listener): string {
            // Both registration shapes carry the listener target in the
            // closure's static vars: explicit listen() a bare class
            // string, discovery a "class@method" string.
            $static = (new ReflectionFunction($listener))->getStaticVariables();
            $target = $static['listener'] ?? null;

            return is_string($target) ? $target : 'closure';
        });

    expect($targets->filter(fn (string $target): bool => str_starts_with($target, 'App\\Listeners\\BadgePipeline'))->count())
        ->toBe(1)
        // Lapses stay sweep-owned (spec §4): no badge listener at all.
        ->and(collect(app(Dispatcher::class)->getListeners(MembershipEnded::class))
            ->filter(fn (callable $listener): bool => str_contains((string) (new ReflectionFunction($listener))->getStaticVariables()['listener'] ?? '', 'BadgePipeline'))
            ->count())
        ->toBe(0);
});
