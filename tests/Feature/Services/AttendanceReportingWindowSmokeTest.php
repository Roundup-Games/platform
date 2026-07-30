<?php

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\AttendanceService;

//
// Attendance reporting-window boundary smoke tests (M058/S06).
//
// AttendanceService::submitReport enforces an open/closed window via
// attendance_window_opens_at / attendance_window_closes_at, but only the full
// lifecycle integration test exercised it indirectly. These pin the boundary
// directly: refused before the window opens, succeeds within, refused after.
//

function windowCreateCompletedGame(array $windowOverrides): array
{
    $host = User::factory()->create(['profile_complete' => true]);
    $player = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $game = Game::factory()->create(array_merge([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
        'status' => GameStatus::Completed->value,
        'date_time' => now()->subHours(3),
        'attendance_window_opens_at' => now()->subHour(),
        'attendance_window_closes_at' => now()->addDays(2),
    ], $windowOverrides));

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return [$host, $player, $game];
}

function windowReport($service, $game, $host, $player): array
{
    return $service->submitReport($game, $host, [
        ['reported_id' => (string) $player->id, 'status' => 'attended'],
    ]);
}

it('refuses attendance reporting before the window opens', function () {
    [$host, $player, $game] = windowCreateCompletedGame([
        'attendance_window_opens_at' => now()->addHour(),
    ]);

    $result = windowReport(app(AttendanceService::class), $game, $host, $player);

    expect($result['success'])->toBeFalse()
        ->and($result['reason'])->toBe('Attendance reporting window has not opened yet');
})->group('smoke');

it('accepts attendance reporting within the open window', function () {
    [$host, $player, $game] = windowCreateCompletedGame([
        'attendance_window_opens_at' => now()->subHour(),
        'attendance_window_closes_at' => now()->addDays(2),
    ]);

    $result = windowReport(app(AttendanceService::class), $game, $host, $player);

    expect($result['success'])->toBeTrue();
})->group('smoke');

it('refuses attendance reporting after the window closes', function () {
    [$host, $player, $game] = windowCreateCompletedGame([
        'attendance_window_closes_at' => now()->subHour(),
    ]);

    $result = windowReport(app(AttendanceService::class), $game, $host, $player);

    expect($result['success'])->toBeFalse()
        ->and($result['reason'])->toBe('Attendance reporting window has closed');
})->group('smoke');
