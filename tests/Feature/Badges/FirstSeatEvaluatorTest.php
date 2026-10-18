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
use App\Services\Badges\FirstSeatEvaluator;
use Carbon\Carbon;

/**
 * FirstSeatEvaluator (M069 S05 T1, spec §3 Milestones row): sat at
 * your first table — at least one game_participants row with
 * attendance_status = Attended on a game whose attendance_resolved_at
 * is set. Only organizer-resolved attendance counts, never self
 * check-in — the same verified-attendance gate as Regulars' Table
 * (spec §1 anti-gaming note).
 *
 * Tierless OneTime, no lapse: every proposal carries a null tier and
 * points at the earliest verified seat by session date (morph alias
 * 'game_participant' per D170 — games.date_time, never the
 * resolution date). Write-side guarantees (audited rows, idempotent
 * re-runs) ride the S02 BadgeService engine — these tests pin the
 * first-seat-specific derivation, its boundaries, and that the sweep
 * routes through it.
 */

/**
 * One participation row for the user on a game at the given session
 * time, returning the participant row (callers use the first one as
 * evidence).
 */
function firstSeatAttend(User $user, Carbon $sessionAt, AttendanceStatus $attendance = AttendanceStatus::Attended, bool $resolved = true): GameParticipant
{
    $game = Game::factory()->create([
        'date_time' => $sessionAt,
        'attendance_resolved_at' => $resolved ? $sessionAt->copy()->addDay() : null,
    ]);

    return GameParticipant::factory()->create([
        'game_id' => $game,
        'user_id' => $user,
        'attendance_status' => $attendance,
    ]);
}

/**
 * The user's first_seat hold row, or null when not held.
 */
function firstSeatHold(): ?BadgeUser
{
    return BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'first_seat'))
        ->first();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();
});

it('proposes the badge for a resolved attended seat', function () {
    $user = User::factory()->create();
    $participant = firstSeatAttend($user, now()->subDays(2));

    $award = app(FirstSeatEvaluator::class)->evaluate($user);

    expect($award)->not->toBeNull()
        ->and($award->badgeKey)->toBe('first_seat')
        // Tierless OneTime: there is no "more first" than first.
        ->and($award->tierKey)->toBeNull()
        // Evidence via the D170 morph alias, never the FQCN.
        ->and($award->evidenceType)->toBe('game_participant')
        ->and($award->evidenceId)->toBe((string) $participant->id);
});

it('points the award at the earliest verified seat by session date', function () {
    $user = User::factory()->create();
    $earliest = firstSeatAttend($user, now()->subDays(30));
    firstSeatAttend($user, now()->subDays(10));

    expect(app(FirstSeatEvaluator::class)->evaluate($user)?->evidenceId)->toBe((string) $earliest->id);
});

it('never proposes for a seat the organizer has not resolved', function () {
    $user = User::factory()->create();

    // Self check-in shape (spec anti-gaming note): an Attended row on
    // an unresolved game may as well not exist.
    firstSeatAttend($user, now()->subDays(2), resolved: false);

    expect(app(FirstSeatEvaluator::class)->evaluate($user))->toBeNull();
});

it('never proposes for non-attended participation statuses', function (AttendanceStatus $attendance) {
    $user = User::factory()->create();

    firstSeatAttend($user, now()->subDays(2), $attendance);

    // NoShow/LateCancel/Excused/CancelledEarly rows are not verified
    // attendance, resolved or not.
    expect(app(FirstSeatEvaluator::class)->evaluate($user))->toBeNull();
})->with([
    'no show' => [AttendanceStatus::NoShow],
    'late cancel' => [AttendanceStatus::LateCancel],
    'excused' => [AttendanceStatus::Excused],
    'cancelled early' => [AttendanceStatus::CancelledEarly],
]);

it('never proposes for someone else\'s attendance', function () {
    $attendee = User::factory()->create();
    firstSeatAttend($attendee, now()->subDays(2));

    expect(app(FirstSeatEvaluator::class)->evaluate(User::factory()->create()))->toBeNull();
});

it('is a pure proposal — evaluate never writes badge state', function () {
    $user = User::factory()->create();
    firstSeatAttend($user, now()->subDays(2));

    app(FirstSeatEvaluator::class)->evaluate($user);

    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('backfills the badge from attendance history with no prior state', function () {
    $user = User::factory()->create();
    $earliest = firstSeatAttend($user, now()->subDays(30));
    firstSeatAttend($user, now()->subDays(10));

    app(FirstSeatEvaluator::class)->sweep();

    $hold = firstSeatHold();
    $audit = BadgeEvent::query()->first();

    // The backfill demo in miniature: months of attendance history,
    // zero badge state, one sweep → first seat in place with a full
    // audit row.
    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBeNull()
        ->and($hold->evidence_type)->toBe('game_participant')
        ->and($hold->evidence_id)->toBe((string) $earliest->id)
        ->and($audit?->action)->toBe(BadgeEventAction::Awarded)
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('is idempotent — a second sweep writes nothing new', function () {
    $user = User::factory()->create();
    firstSeatAttend($user, now()->subDays(2));

    app(FirstSeatEvaluator::class)->sweep();
    app(FirstSeatEvaluator::class)->sweep();

    expect(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('leaves a holder without attendance untouched — one-time badges never lapse', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();

    app(BadgeService::class)->award(
        $user,
        'first_seat',
        null,
        null,
        BadgeSource::Manual,
        $admin,
        'Grandfathered before the badge engine shipped',
    );

    // The holder has no Attended rows at all: the cohort's holder arm
    // pulls them in, but a null proposal must park nothing — the
    // manual award's audit row stays the only one.
    app(FirstSeatEvaluator::class)->sweep();

    $hold = firstSeatHold();

    expect($hold)->not->toBeNull()
        ->and($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and(BadgeEvent::query()->count())->toBe(1);
});
