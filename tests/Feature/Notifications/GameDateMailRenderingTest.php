<?php

use App\Enums\AttendanceStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\User;
use App\Notifications\AttendanceReported;
use App\Notifications\AttendanceResolved;
use App\Notifications\DisputeResolved;
use App\Notifications\EntityCancelled;
use Carbon\Carbon;

//
// R089 — attendance / dispute / cancellation mail dates render on the
// RECIPIENT's wall calendar (format_date + recipient zone), never the raw
// stored UTC instant: a 23:30 UTC fixture is already "tomorrow" in Berlin.
//
// The four notifications below are the siblings AttendanceNudge converted
// in the R089 follow-up — this file pins them to the same contract. Per
// K004, expectations derive from format_date() itself (explicit zone), so
// the pin is the conversion, not a literal date string.
//

beforeEach(function () {
    app()->setLocale('en');
});

function tzMailGame(): Game
{
    return Game::factory()->create([
        'date_time' => Carbon::parse('2026-11-05 23:30:00', 'UTC'), // Nov 6, 00:30 Berlin
    ]);
}

function tzMailRecipient(): User
{
    return User::factory()->create([
        'timezone' => 'Europe/Berlin',
        'preferred_language' => 'en',
    ]);
}

/**
 * The wall dates the fixture instant maps to — derived, never literal.
 *
 * @return array{berlin: string, utc: string}
 */
function tzMailExpectedDates(Game $game): array
{
    return [
        'berlin' => format_date($game->date_time, 'date', 'Europe/Berlin'),
        'utc' => format_date($game->date_time, 'date', 'UTC'),
    ];
}

it('renders the attendance-reported mail date on the recipient wall calendar', function () {
    $user = tzMailRecipient();
    $game = tzMailGame();
    $report = AttendanceReport::factory()->make(['game_id' => $game->id]);

    $rendered = (string) (new AttendanceReported($game, $report))->toMail($user)->render();
    $expected = tzMailExpectedDates($game);

    expect($rendered)->toContain($expected['berlin'])
        ->and($rendered)->not->toContain($expected['utc']);
});

it('renders the attendance-resolved mail date on the recipient wall calendar', function () {
    $user = tzMailRecipient();
    $game = tzMailGame();

    $rendered = (string) (new AttendanceResolved($game, AttendanceStatus::Attended))->toMail($user)->render();
    $expected = tzMailExpectedDates($game);

    expect($rendered)->toContain($expected['berlin'])
        ->and($rendered)->not->toContain($expected['utc']);
});

it('renders the dispute-resolved mail date on the recipient wall calendar', function () {
    $user = tzMailRecipient();
    $game = tzMailGame();

    $rendered = (string) (new DisputeResolved($game, 'resolved_favor'))->toMail($user)->render();
    $expected = tzMailExpectedDates($game);

    expect($rendered)->toContain($expected['berlin'])
        ->and($rendered)->not->toContain($expected['utc']);
});

it('renders the game-cancelled mail date on the recipient wall calendar', function () {
    $user = tzMailRecipient();
    $game = tzMailGame();

    $rendered = (string) (new EntityCancelled($game))->toMail($user)->render();
    $expected = tzMailExpectedDates($game);

    expect($rendered)->toContain($expected['berlin'])
        ->and($rendered)->not->toContain($expected['utc']);
});
