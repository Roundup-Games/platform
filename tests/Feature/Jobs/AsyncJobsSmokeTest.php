<?php

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Jobs\AttendanceNudgeJob;
use App\Jobs\AutoCompleteGames;
use App\Jobs\RecordShortLinkHit;
use App\Jobs\ResolveAttendance;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\ShortLink;
use App\Models\User;
use App\Notifications\AttendanceNudge;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;

//
// Async-layer smoke tests (M058/S03).
//
// These cover the 4 critical jobs that previously had NO test of any kind.
// QUEUE_CONNECTION=sync in phpunit.xml, so dispatch() runs handle() inline
// — the tests assert the documented side-effect of each job against real DB
// state, not a Queue::fake dispatch assertion.
//

// ── Helpers ───────────────────────────────────────────────────────────────

function asyncJobCreateCompletedGame(array $overrides = []): array
{
    $host = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();

    $game = Game::factory()->create(array_merge([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
        'status' => GameStatus::Completed->value,
        'date_time' => now()->subHours(3),
        'attendance_window_opens_at' => now()->subHour(),
        'attendance_window_closes_at' => now()->addDays(2),
    ], $overrides));

    return [$host, $game];
}

// ── ResolveAttendance ────────────────────────────────────────────────────

it('resolves attendance for a single game via the ResolveAttendance job', function () {
    [$host, $game] = asyncJobCreateCompletedGame();

    // Two participants with conflicting attendance reports → the job's
    // resolution engine must produce a resolved status.
    $player = User::factory()->create(['profile_complete' => true]);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
        'attendance_status' => AttendanceStatus::Attended->value,
    ]);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
        'attendance_status' => AttendanceStatus::Attended->value,
    ]);

    // File conflicting reports (host says no_show, player says attended)
    AttendanceReport::create([
        'game_id' => $game->id,
        'reporter_id' => $host->id,
        'reported_id' => $player->id,
        'status' => AttendanceStatus::NoShow,
    ]);
    AttendanceReport::create([
        'game_id' => $game->id,
        'reporter_id' => $player->id,
        'reported_id' => $player->id,
        'status' => AttendanceStatus::Attended,
    ]);

    expect($game->fresh()->attendance_resolved_at)->toBeNull();

    ResolveAttendance::dispatchSync($game);

    // The job marks the game resolved — the load-bearing idempotency outcome.
    expect($game->fresh()->attendance_resolved_at)->not->toBeNull();
})->group('smoke');

// ── AutoCompleteGames ────────────────────────────────────────────────────

it('auto-completes a game whose scheduled end plus offset has passed', function () {
    // A game scheduled to end 20h ago, with a 0h offset so it qualifies now.
    Config::set('attendance.auto_complete_offset_hours', 0);

    $host = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
        'status' => GameStatus::Scheduled->value,
        'date_time' => now()->subHours(20),
        'expected_duration' => 3,
    ]);

    AutoCompleteGames::dispatchSync();

    $fresh = $game->fresh();
    expect($fresh->status)->toBe(GameStatus::Completed)
        ->and($fresh->attendance_window_opens_at)->not->toBeNull();
})->group('smoke');

it('does not auto-complete a game still within its scheduled window', function () {
    Config::set('attendance.auto_complete_offset_hours', 0);

    $host = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
        'status' => GameStatus::Scheduled->value,
        'date_time' => now()->addHours(3),
        'expected_duration' => 3,
    ]);

    AutoCompleteGames::dispatchSync();

    expect($game->fresh()->status)->toBe(GameStatus::Scheduled);
})->group('smoke');

// ── AttendanceNudgeJob ───────────────────────────────────────────────────

it('nudges approved participants who have not filed an attendance report', function () {
    Notification::fake();

    [$host, $game] = asyncJobCreateCompletedGame([
        // Reporting window closes in ~24h — inside the job's 23h45m–24h15m band.
        'attendance_window_closes_at' => now()->addDay()->addMinutes(5),
    ]);

    $unreportedPlayer = User::factory()->create(['profile_complete' => true]);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $unreportedPlayer->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    AttendanceNudgeJob::dispatchSync();

    Notification::assertSentTo($unreportedPlayer, AttendanceNudge::class);
})->group('smoke');

// ── RecordShortLinkHit ───────────────────────────────────────────────────

it('records a short link hit and increments the hit count', function () {
    $link = ShortLink::factory()->create([
        'hit_count' => 3,
        'last_hit_at' => now()->subDay(),
    ]);

    RecordShortLinkHit::dispatchSync(
        $link->id,
        '203.0.113.42',
        'https://example.com/page',
        'Mozilla/5.0 (Macintosh) Chrome/120.0',
        false,
    );

    $fresh = $link->fresh();
    expect($fresh->hit_count)->toBe(4)
        ->and($fresh->last_hit_at)->not->toBeNull();

    // Hit row persisted (IP hashed, UA reduced to browser family, referer to host)
    $hit = $fresh->hits()->latest('hit_at')->first();
    expect($hit)->not->toBeNull()
        ->and($hit->ip_address)->not->toBe('203.0.113.42') // raw IP never stored
        ->and($hit->user_agent)->toBe('Chrome')             // reduced to family
        ->and($hit->referer_domain)->toBe('example.com');   // host only
})->group('smoke');

// ── RecordUserSignIn listener ────────────────────────────────────────────

it('stamps last_login_at when a user logs in (RecordUserSignIn listener)', function () {
    $user = User::factory()->create(['last_login_at' => null]);

    expect($user->last_login_at)->toBeNull();

    // Fire the Login event the listener is bound to. actingAs + a login POST
    // both trigger it; event dispatch is the most direct assertion.
    event(new Login('web', $user, false));

    expect($user->fresh()->last_login_at)->not->toBeNull();
})->group('smoke');
