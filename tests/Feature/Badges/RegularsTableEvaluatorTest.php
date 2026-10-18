<?php

use App\Enums\AttendanceStatus;
use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\Badges\BadgeService;
use App\Services\Badges\RegularsTableEvaluator;
use Carbon\Carbon;

/**
 * RegularsTableEvaluator (M069 S02 T3, spec §3 Participation row): the
 * recurring attendance streak — "consecutive months with ≥1 verified
 * attendance; 1 grace skip per 6 months", tiers 3/6/12.
 *
 * Every test pins the evaluator + sweep semantics directly; the nightly
 * command wiring (badges:sweep prepending badges:sync) is T4. Time is
 * frozen to mid-month 2026-06 so month buckets and the "open current
 * month" trailing-miss rule are deterministic. Only organizer-resolved
 * Attended rows count (never self check-in — spec anti-gaming note),
 * bucketed by session date (games.date_time), never resolution date.
 *
 * Lapse/requalify semantics ride BadgeService (T1 engine) — these tests
 * pin that the sweep routes through it: audited rows, in-place tier
 * changes, quiet lapses.
 */

/**
 * One resolved Attended session for the user in the given Y-m month,
 * returning the participant row (callers use the last one as evidence).
 */
function regularsAttendMonth(User $user, string $month, AttendanceStatus $status = AttendanceStatus::Attended, bool $resolved = true): GameParticipant
{
    $game = Game::factory()->create([
        'date_time' => Carbon::parse("{$month}-15 18:00:00"),
        'attendance_resolved_at' => $resolved ? Carbon::parse("{$month}-20 12:00:00") : null,
    ]);

    return GameParticipant::factory()->create([
        'game_id' => $game,
        'user_id' => $user,
        'attendance_status' => $status,
    ]);
}

/**
 * One resolved Attended session per month, ending at the current month
 * and reaching back $count months. Returns the final participant row.
 */
function regularsAttendMonths(User $user, int $count): GameParticipant
{
    $participant = null;

    for ($monthsAgo = $count - 1; $monthsAgo >= 0; $monthsAgo--) {
        $participant = regularsAttendMonth($user, now()->subMonths($monthsAgo)->format('Y-m'));
    }

    return $participant;
}

/**
 * The user's regulars_table hold row, or null when not held.
 */
function regularsTableHold(): ?BadgeUser
{
    return BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'regulars_table'))
        ->first();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();
});

it('pins tier thresholds to consecutive attended months', function (int $months, string $tier) {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    regularsAttendMonths($user, $months);

    $award = app(RegularsTableEvaluator::class)->evaluate($user);

    expect($award)->not->toBeNull()
        ->and($award->badgeKey)->toBe('regulars_table')
        ->and($award->tierKey)->toBe($tier);
})->with([
    '3 consecutive months → bronze' => [3, 'bronze'],
    '6 consecutive months → silver' => [6, 'silver'],
    '12 consecutive months → gold' => [12, 'gold'],
]);

it('proposes nothing below the bronze threshold', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    regularsAttendMonths($user, 2);

    expect(app(RegularsTableEvaluator::class)->evaluate($user))->toBeNull();
});

it('is a pure proposal — evaluate never writes badge state', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    regularsAttendMonths($user, 6);

    app(RegularsTableEvaluator::class)->evaluate($user);

    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('forgives one skipped month per rolling six-month window', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();

    foreach (['2026-01', '2026-02', '2026-04', '2026-05', '2026-06'] as $month) {
        regularsAttendMonth($user, $month);
    }

    // March skipped: the window's only miss, so the run Jan..Jun spans
    // six calendar months with the forgiven gap included — silver.
    expect(app(RegularsTableEvaluator::class)->evaluate($user)?->tierKey)->toBe('silver');
});

it('breaks the run on a second miss inside one six-month window', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();

    foreach (['2026-01', '2026-02', '2026-05', '2026-06'] as $month) {
        regularsAttendMonth($user, $month);
    }

    // March burns the window's only grace, April ends the January run —
    // the fresh May..June start is two months, below every threshold.
    expect(app(RegularsTableEvaluator::class)->evaluate($user))->toBeNull();
});

it('keeps the run alive through a single trailing miss', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();

    // January..May covered (the helper would reach into June); June —
    // the open current month — is the window's only miss, so the run
    // still spans it: six calendar months, silver.
    for ($monthsAgo = 5; $monthsAgo >= 1; $monthsAgo--) {
        regularsAttendMonth($user, now()->subMonths($monthsAgo)->format('Y-m'));
    }

    expect(app(RegularsTableEvaluator::class)->evaluate($user)?->tierKey)->toBe('silver');
});

it('proposes nothing after two consecutive trailing misses', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    regularsAttendMonths($user, 4); // through April

    // No attendance since April: July burns the grace, August breaks
    // the run — a streak that no longer ends at the current month.
    $this->travel(2)->months();

    expect(app(RegularsTableEvaluator::class)->evaluate($user))->toBeNull();
});

it('never counts attendance the organizer has not resolved', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();

    // Resolved January..April, ending a month short of the helper's
    // usual current-month reach so May and June stay genuinely open.
    for ($monthsAgo = 5; $monthsAgo >= 2; $monthsAgo--) {
        regularsAttendMonth($user, now()->subMonths($monthsAgo)->format('Y-m'));
    }

    foreach (['2026-05', '2026-06'] as $month) {
        regularsAttendMonth($user, $month, AttendanceStatus::Attended, resolved: false);
    }

    // Self check-in shape (spec anti-gaming note): unresolved rows may
    // as well not exist — the trailing May/June gap breaks the run
    // exactly like any other double miss, and nothing is ever written.
    app(RegularsTableEvaluator::class)->sweep();

    expect(regularsTableHold())->toBeNull()
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('never counts non-attended participation statuses', function (string $february, string $march) {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();

    regularsAttendMonth($user, '2026-01');
    regularsAttendMonth($user, '2026-02', AttendanceStatus::from($february));
    regularsAttendMonth($user, '2026-03', AttendanceStatus::from($march));
    regularsAttendMonth($user, '2026-04');
    regularsAttendMonth($user, '2026-05');
    regularsAttendMonth($user, '2026-06');

    // NoShow/LateCancel/Excused/CancelledEarly rows are not verified
    // attendance: February and March stay misses, February burns the
    // grace and March ends the January run — the fresh April..June
    // start is three months, bronze and never silver.
    expect(app(RegularsTableEvaluator::class)->evaluate($user)?->tierKey)->toBe('bronze');
})->with([
    'no-show then late cancel' => ['no_show', 'late_cancel'],
    'excused then cancelled early' => ['excused', 'cancelled_early'],
]);

it('counts a month once no matter how many sessions were attended', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();

    regularsAttendMonth($user, '2026-04');
    regularsAttendMonth($user, '2026-04');
    regularsAttendMonth($user, '2026-05');
    regularsAttendMonth($user, '2026-06');
    regularsAttendMonth($user, '2026-06');

    // Five sessions across three distinct months: one covered-month
    // bucket each, streak 3 — bronze, never inflated by session count.
    expect(app(RegularsTableEvaluator::class)->evaluate($user)?->tierKey)->toBe('bronze');
});

it('points the award at the most recent attended session as evidence', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();

    regularsAttendMonth($user, '2026-04');
    regularsAttendMonth($user, '2026-05');
    $latest = regularsAttendMonth($user, '2026-06');

    $award = app(RegularsTableEvaluator::class)->evaluate($user);

    // Evidence via the D170 morph alias, never the FQCN.
    expect($award?->evidenceType)->toBe('game_participant')
        ->and($award?->evidenceId)->toBe((string) $latest->id);
});

it('backfills the badge from months of fixtures with no prior state', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    $latest = regularsAttendMonths($user, 12);

    app(RegularsTableEvaluator::class)->sweep();

    $hold = regularsTableHold();
    $audit = BadgeEvent::query()->first();

    // The slice demo's second half: a year of attendance history, zero
    // badge state, one sweep → gold in place with a full audit row.
    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBe('gold')
        ->and($hold->evidence_type)->toBe('game_participant')
        ->and($hold->evidence_id)->toBe((string) $latest->id)
        ->and($audit?->action)->toBe(BadgeEventAction::Awarded)
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('upgrades the tier in place as the streak grows', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    regularsAttendMonths($user, 3);

    $evaluator = app(RegularsTableEvaluator::class);
    $evaluator->sweep();

    expect(regularsTableHold()?->tier)->toBe('bronze');

    $this->travel(3)->months();

    foreach (['2026-07', '2026-08', '2026-09'] as $month) {
        regularsAttendMonth($user, $month);
    }

    $evaluator->sweep();

    // January..September is one unbroken nine-month run: silver lands
    // on the same row — one hold, one Awarded plus one Upgraded audit.
    expect(regularsTableHold()?->tier)->toBe('silver')
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->where('action', BadgeEventAction::Upgraded)->count())->toBe(1);
});

it('quietly lapses a held badge when the streak breaks', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    regularsAttendMonths($user, 6);

    $evaluator = app(RegularsTableEvaluator::class);
    $evaluator->sweep();

    expect(regularsTableHold()?->tier)->toBe('silver');

    $this->travel(2)->months(); // nothing since June: the run is dead

    $evaluator->sweep();

    $hold = regularsTableHold();
    $audit = BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->first();

    // Spec §4: a broken streak greys out quietly — lapsed_at stamped,
    // row kept, one audit row carrying the machine-facing cause.
    expect($hold->lapsed_at)->not->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and($audit)->not->toBeNull()
        ->and($audit->reason)->toBe('streak_broken');

    // Idempotent: a second sweep must not re-lapse or re-audit.
    $evaluator->sweep();

    expect(BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->count())->toBe(1);
});

it('requalifies a lapsed holder who returns to the table', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    regularsAttendMonths($user, 6);

    $evaluator = app(RegularsTableEvaluator::class);
    $evaluator->sweep(); // silver

    $this->travel(2)->months();
    $evaluator->sweep(); // lapsed

    expect(regularsTableHold()->lapsed_at)->not->toBeNull();

    // Distinct created_at per phase: badge_events timestamps are
    // second-precision, so ties would make the sequence below ambiguous.
    $this->travel(1)->seconds();
    regularsAttendMonth($user, '2026-07');
    regularsAttendMonth($user, '2026-08');

    $evaluator->sweep();

    $hold = regularsTableHold();
    $actions = BadgeEvent::query()->orderBy('created_at')->pluck('action')->all();

    // July and August close the gap: January..August is one unbroken
    // run again — requalified in place at the tier it earns.
    expect($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and($hold->tier)->toBe('silver')
        ->and($actions)->toBe([
            BadgeEventAction::Awarded,
            BadgeEventAction::Lapsed,
            BadgeEventAction::Requalified,
        ]);
});

it('sweeps current holders even when their participation history is gone', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    $admin = User::factory()->create();

    app(BadgeService::class)->award(
        $user,
        'regulars_table',
        'bronze',
        null,
        BadgeSource::Manual,
        $admin,
        'Grandfathered before the badge engine shipped',
    );

    // No Attended rows at all: the cohort's holder arm is what pulls
    // this user in — the streak is dead on arrival and the hold lapses.
    app(RegularsTableEvaluator::class)->sweep();

    expect(regularsTableHold()?->lapsed_at)->not->toBeNull()
        ->and(BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->count())->toBe(1);
});
