<?php

use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Enums\GameStatus;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\Game;
use App\Models\User;
use App\Services\Badges\BadgeService;
use App\Services\Badges\StillRollingEvaluator;
use Carbon\Carbon;

/**
 * StillRollingEvaluator (M069 S05 T2, spec §3 Support Tenure row):
 * hosting tenure — full years since the user's first hosted game,
 * tiers at 1/3/5 years.
 *
 * Milestone semantics: proposals only ever award or upgrade, never
 * lapse — a null proposal (under every threshold) parks nothing. The
 * tenure anchor and evidence pointer is the earliest owned, past,
 * non-Canceled game (morph alias 'game' per D170). Write-side
 * guarantees (audited rows, idempotent re-runs, tier upgrades) ride
 * the S02 BadgeService engine — these tests pin the still-rolling
 * derivation, its year boundaries, and that the sweep routes through
 * it.
 */

/**
 * One game owned (hosted) by the user at the given session time.
 */
function stillRollingHostGame(User $user, Carbon $dateTime, GameStatus $status = GameStatus::Scheduled): Game
{
    return Game::factory()->create([
        'owner_id' => $user,
        'date_time' => $dateTime,
        'status' => $status->value,
    ]);
}

/**
 * The user's still_rolling hold row, or null when not held.
 */
function stillRollingHold(): ?BadgeUser
{
    return BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'still_rolling'))
        ->first();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();
});

it('proposes year_1 once the first hosted game is a full year back', function () {
    $user = User::factory()->create();
    $game = stillRollingHostGame($user, now()->subDays(366));

    $award = app(StillRollingEvaluator::class)->evaluate($user);

    // 366 days back is at least one full year in every calendar
    // (leap crossing included) — the first tier has been reached.
    expect($award)->not->toBeNull()
        ->and($award->badgeKey)->toBe('still_rolling')
        ->and($award->tierKey)->toBe('year_1')
        ->and($award->evidenceType)->toBe('game')
        ->and($award->evidenceId)->toBe((string) $game->id);
});

it('proposes nothing while the first hosted game is under a year old', function () {
    $user = User::factory()->create();
    stillRollingHostGame($user, now()->subDays(364));

    // 364 days back is under one full year in every calendar — the
    // hosting history exists but has earned no tier yet.
    expect(app(StillRollingEvaluator::class)->evaluate($user))->toBeNull();
});

it('reaches year_3 at three full years of hosting', function () {
    $user = User::factory()->create();
    stillRollingHostGame($user, now()->subYears(3));

    expect(app(StillRollingEvaluator::class)->evaluate($user)?->tierKey)->toBe('year_3');
});

it('reaches year_5 at five full years of hosting', function () {
    $user = User::factory()->create();
    stillRollingHostGame($user, now()->subYears(5));

    expect(app(StillRollingEvaluator::class)->evaluate($user)?->tierKey)->toBe('year_5');
});

it('picks the highest threshold reached, not the first', function () {
    $user = User::factory()->create();
    stillRollingHostGame($user, now()->subYears(6));

    expect(app(StillRollingEvaluator::class)->evaluate($user)?->tierKey)->toBe('year_5');
});

it('anchors tenure and evidence at the earliest qualifying game', function () {
    $user = User::factory()->create();
    $earliest = stillRollingHostGame($user, now()->subYears(3));
    stillRollingHostGame($user, now()->subDays(366));

    $award = app(StillRollingEvaluator::class)->evaluate($user);

    // The tenure clock starts at the FIRST hosted game: year_3, with
    // that game as the evidence pointer.
    expect($award?->tierKey)->toBe('year_3')
        ->and($award?->evidenceId)->toBe((string) $earliest->id);
});

it('never counts a canceled game toward tenure', function () {
    $user = User::factory()->create();
    stillRollingHostGame($user, now()->subYears(5), GameStatus::Canceled);
    $hosted = stillRollingHostGame($user, now()->subDays(366));

    $award = app(StillRollingEvaluator::class)->evaluate($user);

    // A canceled game never happened (spec §1 anti-gaming note): the
    // five-year-old cancellation is invisible — tenure starts at the
    // hosted game and only year_1 is earned.
    expect($award?->tierKey)->toBe('year_1')
        ->and($award?->evidenceId)->toBe((string) $hosted->id);
});

it('never proposes for future-dated games', function () {
    $user = User::factory()->create();
    stillRollingHostGame($user, now()->subDays(400));
    stillRollingHostGame($user, now()->addYear());

    // Only the past game counts; the future one is a plan, and on its
    // own it would earn nothing at all.
    expect(app(StillRollingEvaluator::class)->evaluate($user))->not->toBeNull();

    $lonelyHost = User::factory()->create();
    stillRollingHostGame($lonelyHost, now()->addYear());

    expect(app(StillRollingEvaluator::class)->evaluate($lonelyHost))->toBeNull();
});

it('never proposes for someone else\'s game', function () {
    $owner = User::factory()->create();
    stillRollingHostGame($owner, now()->subYears(3));

    expect(app(StillRollingEvaluator::class)->evaluate(User::factory()->create()))->toBeNull();
});

it('is a pure proposal — evaluate never writes badge state', function () {
    $user = User::factory()->create();
    stillRollingHostGame($user, now()->subYears(3));

    app(StillRollingEvaluator::class)->evaluate($user);

    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('backfills tiered holds from hosting history with no prior state', function () {
    $user = User::factory()->create();
    $earliest = stillRollingHostGame($user, now()->subYears(3));
    stillRollingHostGame($user, now()->subDays(400));

    app(StillRollingEvaluator::class)->sweep();

    $hold = stillRollingHold();
    $audit = BadgeEvent::query()->first();

    // The backfill demo in miniature: three years of hosting history,
    // zero badge state, one sweep → year_3 in place with a full audit
    // row anchored at the first hosted game.
    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBe('year_3')
        ->and($hold->evidence_type)->toBe('game')
        ->and($hold->evidence_id)->toBe((string) $earliest->id)
        ->and($audit?->action)->toBe(BadgeEventAction::Awarded)
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('upgrades an existing hold when older hosting history surfaces', function () {
    $user = User::factory()->create();
    stillRollingHostGame($user, now()->subDays(366));

    app(StillRollingEvaluator::class)->sweep();

    expect(stillRollingHold()?->tier)->toBe('year_1');

    // An import or backfill uncovers a game five years back — the
    // stateless recompute must upgrade the hold, never re-award.
    stillRollingHostGame($user, now()->subYears(5));

    app(StillRollingEvaluator::class)->sweep();

    $hold = stillRollingHold();

    expect($hold?->tier)->toBe('year_5')
        ->and($hold?->evidence_id)->toBe((string) Game::query()
        ->whereBelongsTo($user, 'owner')
        ->orderBy('date_time')
        ->value('id'))
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(2)
        ->and(BadgeEvent::query()->pluck('action')->all())->toBe([
            BadgeEventAction::Awarded,
            BadgeEventAction::Upgraded,
        ]);
});

it('is idempotent — a second sweep writes nothing new', function () {
    $user = User::factory()->create();
    stillRollingHostGame($user, now()->subYears(3));

    app(StillRollingEvaluator::class)->sweep();
    app(StillRollingEvaluator::class)->sweep();

    expect(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('milestone holds never lapse — a holder without games is untouched', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();

    app(BadgeService::class)->award(
        $user,
        'still_rolling',
        'year_1',
        null,
        BadgeSource::Manual,
        $admin,
        'Grandfathered before the badge engine shipped',
    );

    // The holder owns no games at all: the cohort's holder arm pulls
    // them in, but a milestone badge never parks — the manual award's
    // audit row stays the only one.
    app(StillRollingEvaluator::class)->sweep();

    $hold = stillRollingHold();

    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBe('year_1')
        ->and($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and(BadgeEvent::query()->count())->toBe(1);
});
