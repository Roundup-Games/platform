<?php

use App\Enums\AttendanceStatus;
use App\Enums\BadgeEventAction;
use App\Models\BadgeDefinition;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\Badges\BadgeRegistry;
use App\Services\Badges\SupportingMemberEvaluator;
use App\Services\GmRoleService;
use App\Support\Scheduling\TaskIdentity;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * badges:sweep (M069 S02 T4): the nightly orchestrator behind the two
 * halves of the slice demo — prepends badges:sync (the deploy wiring
 * S01 deferred), then runs every registered evaluator's sweep() with
 * per-evaluator failure isolation.
 *
 * Time is frozen to mid-month 2026-06 for the attendance fixtures
 * (same convention as RegularsTableEvaluatorTest, so month buckets
 * and the open-current-month rule stay deterministic). The scheduled
 * entry is asserted through the console-context schedule: tests run
 * under the console kernel, so routes/console.php IS loaded here
 * (L052) — no ScheduleInventory needed.
 *
 * Helpers are top-level with a badgesSweep- prefix: sibling Console
 * tests share the global namespace.
 */

/**
 * Seed/fetch the GM plan exactly as the membership pipeline test does
 * (GmSubscriptionTest shape) — activateGmSubscription needs it.
 */
function badgesSweepGmPlan(): MembershipType
{
    return MembershipType::updateOrCreate(
        ['name' => 'Game Master'],
        [
            'description' => 'Test GM plan (badges sweep fixtures)',
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
 * One resolved Attended session for the user in the given Y-m month.
 */
function badgesSweepAttendMonth(User $user, string $month): GameParticipant
{
    $game = Game::factory()->create([
        'date_time' => Carbon::parse("{$month}-15 18:00:00"),
        'attendance_resolved_at' => Carbon::parse("{$month}-20 12:00:00"),
    ]);

    return GameParticipant::factory()->create([
        'game_id' => $game,
        'user_id' => $user,
        'attendance_status' => AttendanceStatus::Attended,
    ]);
}

/**
 * One resolved Attended session per month, ending at the current month
 * and reaching back $count months.
 */
function badgesSweepAttendMonths(User $user, int $count): void
{
    for ($monthsAgo = $count - 1; $monthsAgo >= 0; $monthsAgo--) {
        badgesSweepAttendMonth($user, now()->subMonths($monthsAgo)->format('Y-m'));
    }
}

/**
 * The user's hold row for a badge key, or null when not held.
 */
function badgesSweepHold(string $key): ?BadgeUser
{
    return BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', $key))
        ->first();
}

/**
 * Count audit rows for one badge key, optionally filtered by action —
 * the sweep now lights several evaluators per fixture set, so the
 * command tests assert per-badge instead of on table totals.
 */
function badgesSweepAudits(string $key, ?BadgeEventAction $action = null): int
{
    return BadgeEvent::query()
        ->when($action !== null, fn ($query) => $query->where('action', $action))
        ->whereHas('badgeUser.badgeDefinition', fn ($query) => $query->where('key', $key))
        ->count();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();

    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);

    badgesSweepGmPlan();

    // Founding window closed for this suite (the D208 default is open —
    // the sweep would otherwise award founding_table to every fixture
    // user). Debut and first_seat still fire from these fixtures on
    // purpose; assertions below scope to the badge under test.
    // FoundingTableEvaluatorTest owns the window logic.
    config(['badges.founding_table.closes_at' => '2020-01-01T00:00:00+00:00']);
});

it('backfills the regulars table from months of attendance through the command', function (int $months, string $tier) {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    badgesSweepAttendMonths($user, $months);

    $this->artisan('badges:sweep')->assertSuccessful();

    $hold = badgesSweepHold('regulars_table');

    // The slice demo's second half as an equivalence through the real
    // command surface: months of fixtures, zero badge state, one sweep.
    // The live debut/first_seat evaluators also fire off these fixtures
    // (every fixture host, the attendee) — the regulars audit is the
    // one under test, scoped to its badge.
    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBe($tier)
        ->and($hold->evidence_type)->toBe('game_participant')
        ->and(badgesSweepAudits('regulars_table', BadgeEventAction::Awarded))->toBe(1);
})->with([
    '3 consecutive months → bronze' => [3, 'bronze'],
    '6 consecutive months → silver' => [6, 'silver'],
    '12 consecutive months → gold' => [12, 'gold'],
]);

it('lapses a deactivated supporting member through the sweep', function () {
    $service = app(GmRoleService::class);
    $user = User::factory()->create();

    $service->activateGmSubscription($user); // instant award via the pipeline
    $service->deactivateGmSubscription($user);

    // The event path never lapses (spec §4: sweep-owned, quiet) — the
    // command is what greys the badge out.
    expect(badgesSweepHold('supporting_member')->lapsed_at)->toBeNull();

    $this->artisan('badges:sweep')->assertSuccessful();

    $hold = badgesSweepHold('supporting_member');
    $audit = BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->first();

    expect($hold->lapsed_at)->not->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and($audit)->not->toBeNull()
        ->and($audit->reason)->toBe('membership_inactive');
});

it('stays strict-mode clean with a multi-user supporting cohort', function () {
    // Regression (M069 milestone validation): Builder::hydrate() only
    // stamps the preventsLazyLoading flag on multi-row result sets, so
    // sweeps whose supporting-member cohort held a single user silently
    // masked Cashier's lazy ->subscriptions read inside subscribed()
    // (hasActiveMembership). Two cohort members surface the violation —
    // the evaluator's cohort must eager-load subscriptions, which is
    // also the bulk-path N+1 fix.
    $service = app(GmRoleService::class);

    $active = User::factory()->create();
    $service->activateGmSubscription($active);

    $ended = User::factory()->create();
    $service->activateGmSubscription($ended);
    $service->deactivateGmSubscription($ended);

    // Pre-fix: supporting_member aborts with a LazyLoadingViolationException
    // and the command exits non-zero under strict mode (dev/CI).
    $this->artisan('badges:sweep')->assertSuccessful();

    $holdFor = fn (User $user) => BadgeUser::query()
        ->where('user_id', $user->id)
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'supporting_member'))
        ->first();

    expect($holdFor($active)?->lapsed_at)->toBeNull()
        ->and($holdFor($ended)?->lapsed_at)->not->toBeNull()
        ->and($holdFor($active)?->revoked_at)->toBeNull();
});

it('is idempotent — a second sweep writes nothing new', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $regular = User::factory()->create();
    badgesSweepAttendMonths($regular, 12);

    $service = app(GmRoleService::class);
    $member = User::factory()->create();
    $service->activateGmSubscription($member);
    $service->deactivateGmSubscription($member);

    $this->artisan('badges:sweep')->assertSuccessful();

    $holds = BadgeUser::query()->count();
    $audits = BadgeEvent::query()->count();

    // Both halves of the sweep wrote their rows (gold backfill +
    // membership lapse), alongside the live trio's own backfills —
    // every fixture host earns debut, the attendee a first seat — so
    // totals run past the two-actor minimum. Re-running the night
    // must converge to zero churn — every write flows through
    // BadgeService's dedupe.
    expect(badgesSweepHold('regulars_table')?->tier)->toBe('gold')
        ->and(badgesSweepHold('supporting_member')?->lapsed_at)->not->toBeNull()
        ->and($holds)->toBeGreaterThan(2)
        ->and($audits)->toBeGreaterThan(2);

    $this->artisan('badges:sweep')->assertSuccessful();

    expect(BadgeUser::query()->count())->toBe($holds)
        ->and(BadgeEvent::query()->count())->toBe($audits);
});

it('sweeps every live evaluator over an empty database without writing', function () {
    // Zero dormant definitions remain (campaign_compleat activated in
    // M071/S05 — NoOpEvaluator holds no live registrations), so with no
    // users and no holds, every live evaluator's empty cohort sweeps
    // and must neither write nor fail.
    $this->artisan('badges:sweep')->assertSuccessful();

    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('prepends badges:sync — an unprovisioned registry self-heals', function () {
    // The deploy wiring S01 deferred: a fresh environment where
    // badges:sync never ran converges on the sweep's first night.
    BadgeDefinition::query()->delete();

    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    badgesSweepAttendMonths($user, 12);

    $this->artisan('badges:sweep')->assertSuccessful();

    // The sweep healed the empty registry mirror before any evaluator
    // read it — definitions back, award landed. Pinned to the registry
    // size rather than a literal (MEM1275): the literal went stale
    // when S05 grew the registry 7 → 9 → 10.
    expect(BadgeDefinition::query()->count())->toBe(count((new BadgeRegistry)->all()))
        ->and(badgesSweepHold('regulars_table')?->tier)->toBe('gold');
});

it('isolates a failing evaluator — the rest of the sweep still lands', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    badgesSweepAttendMonths($user, 12);

    // Container-swapped broken evaluator: sweep() always throws.
    $this->app->instance(SupportingMemberEvaluator::class, new class extends SupportingMemberEvaluator
    {
        public function sweep(): void
        {
            throw new RuntimeException('tenure recompute exploded');
        }
    });

    $this->artisan('badges:sweep')->assertFailed();

    // The failure is reported (non-zero exit) AND the healthy
    // evaluators' effects landed anyway: gold in place with exactly
    // one regulars audit row — and the broken evaluator wrote nothing
    // at all (no supporting_member row of any kind).
    expect(badgesSweepHold('regulars_table')?->tier)->toBe('gold')
        ->and(badgesSweepAudits('regulars_table', BadgeEventAction::Awarded))->toBe(1)
        ->and(badgesSweepAudits('supporting_member'))->toBe(0);
});

it('schedules the sweep nightly at 06:30 behind the usual overlap guards', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $matches = collect($schedule->events())
        ->filter(fn (Event $event) => TaskIdentity::command($event) === 'badges:sweep');

    expect($matches)->toHaveCount(1);

    $event = $matches->first();

    // 06:30 clears the crowded 02:00–06:00 window; the guards match
    // every other long-running entry in routes/console.php.
    expect($event->expression)->toBe('30 6 * * *')
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
