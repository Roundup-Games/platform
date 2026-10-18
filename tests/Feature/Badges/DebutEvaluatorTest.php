<?php

use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Enums\GameStatus;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\Game;
use App\Models\User;
use App\Services\Badges\BadgeService;
use App\Services\Badges\DebutEvaluator;
use Carbon\Carbon;

/**
 * DebutEvaluator (M069 S05 T1, spec §3 Milestones row): hosted your
 * first table — the user owns at least one game whose session date
 * has arrived without the game being canceled (Scheduled and
 * Completed both count as hosted; a canceled game never happened —
 * spec §1 anti-gaming note).
 *
 * Tierless OneTime, no lapse: every proposal carries a null tier and
 * points at the earliest qualifying game (morph alias 'game' per
 * D170), and a null proposal never parks a hold. Write-side
 * guarantees (audited rows, idempotent re-runs) ride the S02
 * BadgeService engine — these tests pin the debut-specific
 * derivation, its boundaries, and that the sweep routes through it.
 */

/**
 * One game owned (hosted) by the user at the given session time.
 */
function debutHostGame(User $user, Carbon $dateTime, GameStatus $status = GameStatus::Scheduled): Game
{
    return Game::factory()->create([
        'owner_id' => $user,
        'date_time' => $dateTime,
        'status' => $status->value,
    ]);
}

/**
 * The user's debut hold row, or null when not held.
 */
function debutHold(): ?BadgeUser
{
    return BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'debut'))
        ->first();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();
});

it('proposes the badge for a past hosted game', function () {
    $user = User::factory()->create();
    $game = debutHostGame($user, now()->subDays(2));

    $award = app(DebutEvaluator::class)->evaluate($user);

    expect($award)->not->toBeNull()
        ->and($award->badgeKey)->toBe('debut')
        // Tierless OneTime: there is no "more first" than first.
        ->and($award->tierKey)->toBeNull()
        // Evidence via the D170 morph alias, never the FQCN.
        ->and($award->evidenceType)->toBe('game')
        ->and($award->evidenceId)->toBe((string) $game->id);
});

it('counts both scheduled and completed past games as hosted', function (GameStatus $status) {
    $user = User::factory()->create();
    debutHostGame($user, now()->subDays(2), $status);

    expect(app(DebutEvaluator::class)->evaluate($user))->not->toBeNull();
})->with([
    'scheduled' => [GameStatus::Scheduled],
    'completed' => [GameStatus::Completed],
]);

it('points the award at the earliest qualifying game', function () {
    $user = User::factory()->create();
    $earliest = debutHostGame($user, now()->subDays(30));
    debutHostGame($user, now()->subDays(10));

    expect(app(DebutEvaluator::class)->evaluate($user)?->evidenceId)->toBe((string) $earliest->id);
});

it('never proposes for a canceled game', function () {
    $user = User::factory()->create();
    debutHostGame($user, now()->subDays(2), GameStatus::Canceled);

    // A canceled game is not "hosted" — spec anti-gaming note.
    expect(app(DebutEvaluator::class)->evaluate($user))->toBeNull();
});

it('never proposes for a future-dated game', function () {
    $user = User::factory()->create();
    debutHostGame($user, now()->addDay());

    // Still just a plan: the session date has not arrived.
    expect(app(DebutEvaluator::class)->evaluate($user))->toBeNull();
});

it('skips a canceled earlier game and proposes the later hosted one', function () {
    $user = User::factory()->create();
    debutHostGame($user, now()->subDays(30), GameStatus::Canceled);
    $hosted = debutHostGame($user, now()->subDays(10));

    $award = app(DebutEvaluator::class)->evaluate($user);

    // The earliest QUALIFYING game is the evidence, not the earliest
    // row — the canceled game is invisible to the derivation.
    expect($award)->not->toBeNull()
        ->and($award->evidenceId)->toBe((string) $hosted->id);
});

it('never proposes for someone else\'s game', function () {
    $owner = User::factory()->create();
    debutHostGame($owner, now()->subDays(2));

    expect(app(DebutEvaluator::class)->evaluate(User::factory()->create()))->toBeNull();
});

it('is a pure proposal — evaluate never writes badge state', function () {
    $user = User::factory()->create();
    debutHostGame($user, now()->subDays(2));

    app(DebutEvaluator::class)->evaluate($user);

    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('backfills the badge from hosted games with no prior state', function () {
    $user = User::factory()->create();
    $earliest = debutHostGame($user, now()->subDays(30));
    debutHostGame($user, now()->subDays(10));

    app(DebutEvaluator::class)->sweep();

    $hold = debutHold();
    $audit = BadgeEvent::query()->first();

    // The backfill demo in miniature: hosting history, zero badge
    // state, one sweep → debut in place with a full audit row.
    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBeNull()
        ->and($hold->evidence_type)->toBe('game')
        ->and($hold->evidence_id)->toBe((string) $earliest->id)
        ->and($audit?->action)->toBe(BadgeEventAction::Awarded)
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('is idempotent — a second sweep writes nothing new', function () {
    $user = User::factory()->create();
    debutHostGame($user, now()->subDays(2));

    app(DebutEvaluator::class)->sweep();
    app(DebutEvaluator::class)->sweep();

    expect(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('leaves a holder without games untouched — one-time badges never lapse', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();

    app(BadgeService::class)->award(
        $user,
        'debut',
        null,
        null,
        BadgeSource::Manual,
        $admin,
        'Grandfathered before the badge engine shipped',
    );

    // The holder owns no games at all: the cohort's holder arm pulls
    // them in, but a null proposal must park nothing — the manual
    // award's audit row stays the only one.
    app(DebutEvaluator::class)->sweep();

    $hold = debutHold();

    expect($hold)->not->toBeNull()
        ->and($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and(BadgeEvent::query()->count())->toBe(1);
});
