<?php

use App\Enums\AttendanceStatus;
use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use App\Services\Badges\BadgeService;
use App\Services\Badges\MentorEvaluator;
use Carbon\Carbon;

/**
 * MentorEvaluator (M069 S05 T3, spec §3 GM Craft row, D190): published
 * 5/5 Game reviews from distinct players whose reviewed session was
 * their FIRST attended session with this GM — tiers at 5/15/40
 * distinct reviewers.
 *
 * "First session with this GM" is the first row of (games.date_time,
 * games.id) over the GM's owned games carrying the reviewer's Attended
 * participant row — platform tenure is irrelevant, and equal date_time
 * ties resolve deterministically by game id (the Event tables()
 * ordering precedent). Milestone semantics: proposals only ever award
 * or upgrade, never lapse. Write-side guarantees (audited rows,
 * idempotent re-runs, tier upgrades) ride the S02 BadgeService engine —
 * these tests pin the D190 derivation, its 5/15/40 boundaries, and
 * that the sweep routes through it.
 */

/**
 * The GM under test: a user plus the GM profile reviews point at
 * (games are owned by the user, reviews reference the profile).
 *
 * @return array{0: User, 1: GMProfile}
 */
function mentorGm(): array
{
    $gm = User::factory()->create();

    return [$gm, GMProfile::factory()->for($gm, 'user')->create()];
}

/**
 * One hosted session $monthsAgo back, owned by the GM.
 */
function mentorSession(User $gm, int $monthsAgo = 1): Game
{
    return Game::factory()->create([
        'owner_id' => $gm,
        'date_time' => now()->subMonths($monthsAgo)->setTime(18, 0),
    ]);
}

/**
 * The reviewer's participant row on a session.
 */
function mentorAttends(Game $game, User $reviewer, AttendanceStatus $status = AttendanceStatus::Attended): GameParticipant
{
    return GameParticipant::factory()->create([
        'game_id' => $game,
        'user_id' => $reviewer,
        'attendance_status' => $status,
    ]);
}

/**
 * The reviewer's review of a session on this GM's profile.
 */
function mentorReview(GMProfile $profile, User $reviewer, Game $game, int $rating = 5, string $status = 'published'): Review
{
    return Review::factory()
        ->for($reviewer, 'reviewer')
        ->for($profile, 'gmProfile')
        ->forReviewable($game)
        ->create(['rating' => $rating, 'status' => $status]);
}

/**
 * The canonical first-session fixture: the reviewer's ONLY attended
 * session with this GM plus their published 5/5 review of it.
 */
function mentorFirstSession(GMProfile $profile, User $reviewer, int $monthsAgo = 1): Review
{
    $game = mentorSession($profile->user, $monthsAgo);
    mentorAttends($game, $reviewer);

    return mentorReview($profile, $reviewer, $game);
}

/**
 * Four genuine first-timers on the GM's profile — one short of the
 * bronze boundary, so a single extra qualifier is always decisive.
 */
function mentorFourFirstTimers(GMProfile $profile): void
{
    for ($i = 0; $i < 4; $i++) {
        mentorFirstSession($profile, User::factory()->create());
    }
}

/**
 * The GM's mentor hold row, or null when not held.
 */
function mentorHold(): ?BadgeUser
{
    return BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'mentor'))
        ->first();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();
});

it('pins tier thresholds to distinct qualifying reviewers', function (int $reviewers, ?string $tier) {
    [$gm, $profile] = mentorGm();

    for ($i = 0; $i < $reviewers; $i++) {
        mentorFirstSession($profile, User::factory()->create());
    }

    $award = app(MentorEvaluator::class)->evaluate($gm);

    if ($tier === null) {
        expect($award)->toBeNull();
    } else {
        expect($award)->not->toBeNull()
            ->and($award->badgeKey)->toBe('mentor')
            ->and($award->tierKey)->toBe($tier);
    }
})->with([
    'four reviewers → below every tier' => [4, null],
    'five reviewers → bronze' => [5, 'bronze'],
    'fifteen reviewers → silver' => [15, 'silver'],
    'forty reviewers → gold' => [40, 'gold'],
]);

it('proposes nothing for a user without a GM profile', function () {
    expect(app(MentorEvaluator::class)->evaluate(User::factory()->create()))->toBeNull();
});

it('never counts a five-star review of a later session with the GM', function () {
    [$gm, $profile] = mentorGm();
    mentorFourFirstTimers($profile);

    // A regular who attended two sessions but only reviewed the second:
    // their earliest attended session is the unreviewed one — not a
    // fifth distinct first-session reviewer.
    $reviewer = User::factory()->create();
    mentorAttends(mentorSession($gm, 2), $reviewer);
    $second = mentorSession($gm, 1);
    mentorAttends($second, $reviewer);
    mentorReview($profile, $reviewer, $second);

    expect(app(MentorEvaluator::class)->evaluate($gm))->toBeNull();
});

it('never counts a reviewer who never actually attended the reviewed session', function () {
    [$gm, $profile] = mentorGm();
    mentorFourFirstTimers($profile);

    // A reviewer with no Attended row at all (no-show on the reviewed
    // game, nothing else either): there is no earliest attended session
    // to match, so their perfect review cannot qualify.
    $noShow = User::factory()->create();
    $reviewed = mentorSession($gm, 1);
    mentorAttends($reviewed, $noShow, AttendanceStatus::NoShow);
    mentorReview($profile, $noShow, $reviewed);

    expect(app(MentorEvaluator::class)->evaluate($gm))->toBeNull();
});

it('never counts sub-five or unpublished reviews', function (int $rating, string $status) {
    [$gm, $profile] = mentorGm();

    for ($i = 0; $i < 5; $i++) {
        $reviewer = User::factory()->create();
        $game = mentorSession($gm);
        mentorAttends($game, $reviewer);
        mentorReview($profile, $reviewer, $game, $rating, $status);
    }

    // Five attendees, zero qualifying reviews: no proposal.
    expect(app(MentorEvaluator::class)->evaluate($gm))->toBeNull();
})->with([
    'four-star reviews' => [4, 'published'],
    'reported reviews' => [5, 'reported'],
]);

it('never counts a perfect review that is not a game review', function () {
    [$gm, $profile] = mentorGm();

    for ($i = 0; $i < 5; $i++) {
        $reviewer = User::factory()->create();
        $game = mentorSession($gm);
        mentorAttends($game, $reviewer);

        // A published 5/5 review on the 'campaign' morph carries the
        // GM profile but points at a Campaign, not the session — the
        // morph filter drops it.
        Review::factory()
            ->for($reviewer, 'reviewer')
            ->for($profile, 'gmProfile')
            ->forReviewable(Campaign::factory()->create(['owner_id' => $gm]))
            ->create(['rating' => 5, 'status' => 'published']);
    }

    expect(app(MentorEvaluator::class)->evaluate($gm))->toBeNull();
});

it('counts a repeat reviewer once', function () {
    [$gm, $profile] = mentorGm();
    mentorFourFirstTimers($profile);

    // One reviewer, two five-star reviews — the earliest attended
    // session and their second. The reviews_reviewable_unique
    // constraint makes same-game duplicates impossible, so this is
    // the realistic repeat shape: only the first-session review
    // qualifies and the reviewer counts exactly once — the fifth
    // distinct reviewer lands bronze, never inflated by review count.
    $reviewer = User::factory()->create();
    $earliest = mentorSession($gm, 2);
    mentorAttends($earliest, $reviewer);
    $second = mentorSession($gm, 1);
    mentorAttends($second, $reviewer);
    mentorReview($profile, $reviewer, $earliest);
    mentorReview($profile, $reviewer, $second);

    expect(app(MentorEvaluator::class)->evaluate($gm)?->tierKey)->toBe('bronze');
});

it('ignores sessions the reviewer played at other tables', function () {
    [$gm, $profile] = mentorGm();
    [$otherGm] = mentorGm();
    mentorFourFirstTimers($profile);

    // This reviewer's overall first session was at another GM's table;
    // "first session WITH THIS GM" is what counts — their fifth
    // distinct first-timer review lands bronze.
    $reviewer = User::factory()->create();
    mentorAttends(mentorSession($otherGm, 6), $reviewer);
    mentorFirstSession($profile, $reviewer, 1);

    expect(app(MentorEvaluator::class)->evaluate($gm)?->tierKey)->toBe('bronze');
});

it('resolves a same-date_time tie on game id deterministically', function () {
    [$gm, $profile] = mentorGm();
    mentorFourFirstTimers($profile);

    // Two sessions sharing one date_time — three months back, so the
    // fillers' one-month sessions can never collide with the tie.
    $tieBreaker = User::factory()->create();
    $date = now()->subMonths(3)->setTime(18, 0);
    $first = Game::factory()->create(['owner_id' => $gm, 'date_time' => $date]);
    $second = Game::factory()->create(['owner_id' => $gm, 'date_time' => $date]);
    mentorAttends($first, $tieBreaker);
    mentorAttends($second, $tieBreaker);

    // Same date_time: the lower game id IS the earliest session.
    [$earlier, $later] = strcmp((string) $first->id, (string) $second->id) <= 0
        ? [$first, $second]
        : [$second, $first];

    // Reviewing only the later twin: not the earliest session, so no
    // fifth distinct reviewer arrives.
    mentorReview($profile, $tieBreaker, $later);
    expect(app(MentorEvaluator::class)->evaluate($gm))->toBeNull();

    // Reviewing the earliest twin crosses the boundary — bronze.
    mentorReview($profile, $tieBreaker, $earlier);
    expect(app(MentorEvaluator::class)->evaluate($gm)?->tierKey)->toBe('bronze');
});

it('points the award at the earliest qualifying review as evidence', function () {
    [$gm, $profile] = mentorGm();

    // Explicit distinct review times: same-second creations would make
    // the evidence pick depend on the id tiebreak alone.
    $reviews = [];

    for ($i = 0; $i < 5; $i++) {
        $reviewer = User::factory()->create();
        $game = mentorSession($gm);
        mentorAttends($game, $reviewer);
        $reviews[] = Review::factory()
            ->for($reviewer, 'reviewer')
            ->for($profile, 'gmProfile')
            ->forReviewable($game)
            ->create(['rating' => 5, 'status' => 'published', 'created_at' => Carbon::parse('-'.(10 - $i).' days')]);
    }

    $earliest = $reviews[0];

    $award = app(MentorEvaluator::class)->evaluate($gm);

    // Evidence via the D170 morph alias, never the FQCN.
    expect($award?->evidenceType)->toBe('review')
        ->and($award?->evidenceId)->toBe((string) $earliest->id);
});

it('is a pure proposal — evaluate never writes badge state', function () {
    [$gm, $profile] = mentorGm();

    for ($i = 0; $i < 5; $i++) {
        mentorFirstSession($profile, User::factory()->create());
    }

    app(MentorEvaluator::class)->evaluate($gm);

    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('backfills the badge from first-session reviews with no prior state', function () {
    [$gm, $profile] = mentorGm();

    for ($i = 0; $i < 6; $i++) {
        mentorFirstSession($profile, User::factory()->create());
    }

    app(MentorEvaluator::class)->sweep();

    $hold = mentorHold();
    $audit = BadgeEvent::query()->first();

    // The slice demo's Mentor fixture: six first-timers, zero badge
    // state, one sweep → bronze in place with a full audit row.
    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBe('bronze')
        ->and($hold->evidence_type)->toBe('review')
        ->and($audit?->action)->toBe(BadgeEventAction::Awarded)
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('upgrades the tier in place as more first-timers arrive', function () {
    [$gm, $profile] = mentorGm();

    for ($i = 0; $i < 5; $i++) {
        mentorFirstSession($profile, User::factory()->create());
    }

    $evaluator = app(MentorEvaluator::class);
    $evaluator->sweep();

    expect(mentorHold()?->tier)->toBe('bronze');

    for ($i = 0; $i < 10; $i++) {
        mentorFirstSession($profile, User::factory()->create());
    }

    $evaluator->sweep();

    // Fifteen distinct reviewers: silver lands on the same row — one
    // hold, one Awarded plus one Upgraded audit.
    expect(mentorHold()?->tier)->toBe('silver')
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->where('action', BadgeEventAction::Upgraded)->count())->toBe(1);
});

it('milestones never lapse — a holder without qualifying reviews is untouched', function () {
    [$gm, $profile] = mentorGm();
    $admin = User::factory()->create();

    app(BadgeService::class)->award(
        $gm,
        'mentor',
        'bronze',
        null,
        BadgeSource::Manual,
        $admin,
        'Grandfathered before the badge engine shipped',
    );

    // No reviews at all: the cohort's holder arm pulls the GM in, but
    // a null proposal must park nothing — the manual award's audit row
    // stays the only one.
    app(MentorEvaluator::class)->sweep();

    $hold = mentorHold();

    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBe('bronze')
        ->and($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and(BadgeEvent::query()->count())->toBe(1);
});
