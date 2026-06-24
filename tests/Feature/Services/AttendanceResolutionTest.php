<?php

use App\Enums\AttendanceResolutionMethod;
use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\AttendanceResolutionService;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use App\Services\ReliabilityScoreService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;

uses(DatabaseTransactions::class);

// ── Helpers ───────────────────────────────────────────────────────────────

/**
 * Create a completed game with the given number of approved participants (including the host).
 * Returns the game, host user, and all player users (excluding host).
 *
 * @return array{game: Game, host: User, players: array<int, User>}
 */
function createCompletedGameWithPlayers(int $totalParticipants = 5): array
{
    $host = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();

    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
        'status' => GameStatus::Completed->value,
        'date_time' => now()->subHours(3),
        'attendance_window_opens_at' => now()->subHour(),
        'attendance_window_closes_at' => now()->addHours(72),
    ]);

    // Host participant
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    $players = [];
    for ($i = 1; $i < $totalParticipants; $i++) {
        $user = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
        $players[] = $user;
    }

    return ['game' => $game, 'host' => $host, 'players' => $players];
}

/**
 * File an attendance report in the database directly (bypassing submitReport validation).
 */
function fileReport(Game $game, User $reporter, User $reported, string $status, float $weight = 1.0, bool $quarantined = false, ?string $reason = null): AttendanceReport
{
    return AttendanceReport::create([
        'game_id' => $game->id,
        'reporter_id' => $reporter->id,
        'reported_id' => $reported->id,
        'status' => $status,
        'weight_applied' => $weight,
        'is_corroborated' => false,
        'quarantined' => $quarantined,
        'reason' => $reason,
    ]);
}

/**
 * Get the attendance_service instance with a mocked ReliabilityScoreService.
 * Returns [AttendanceResolutionService, MockInterface].
 *
 * The mock absorbs recomputeAfterAttendance calls so tests don't cascade.
 */
function getAttendanceServiceWithMock(): array
{
    $mock = mock(ReliabilityScoreService::class, function (MockInterface $m) {
        $m->shouldReceive('recomputeAfterAttendance')->zeroOrMoreTimes();
    });

    return [new AttendanceResolutionService($mock), $mock];
}

/**
 * Returns [AttendanceService, MockInterface] — the intake service for tests
 * that exercise submitReport (which delegates to AttendanceResolutionService
 * via app() for markCorroborated and the early-consensus job dispatch).
 */
function getAttendanceIntakeServiceWithMock(): array
{
    $mock = mock(ReliabilityScoreService::class, function (MockInterface $m) {
        $m->shouldReceive('recomputeAfterAttendance')->zeroOrMoreTimes();
    });

    return [new AttendanceService($mock), $mock];
}

/**
 * Resolve attendance and return the refreshed game.
 */
function resolveAndRefresh(Game $game, ?AttendanceResolutionMethod $method = null): Game
{
    $service = getAttendanceServiceWithMock()[0];
    $service->resolveGameAttendance($game, $method);

    return $game->fresh();
}

/**
 * Get a participant's attendance status for a game.
 */
function getAttendanceStatus(Game $game, User $user): ?AttendanceStatus
{
    $p = GameParticipant::where('game_id', $game->id)
        ->where('user_id', $user->id)
        ->first();

    return $p?->attendance_status;
}

// ══════════════════════════════════════════════════════════════════════════
// 1. Basic scenarios
// ══════════════════════════════════════════════════════════════════════════

describe('basic consensus scenarios', function () {
    test('all reporters say attended → everyone Attended', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(4);
        // 4 participants: host + 3 players
        // Each non-host participant gets 3 reporters (all say attended)

        // Players report each other as attended
        foreach ($players as $i => $reporter) {
            // Report the host as attended
            fileReport($game, $reporter, $host, 'attended');
            // Report other players as attended
            foreach ($players as $j => $target) {
                if ($i !== $j) {
                    fileReport($game, $reporter, $target, 'attended');
                }
            }
        }

        $resolved = resolveAndRefresh($game);

        // All should be Attended
        expect(getAttendanceStatus($resolved, $host))->toBe(AttendanceStatus::Attended);
        foreach ($players as $player) {
            expect(getAttendanceStatus($resolved, $player))->toBe(AttendanceStatus::Attended);
        }
    });

    test('majority says no_show (3 of 5) → NoShow', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(5);
        // 5 participants: host + 4 players. Target = players[0]
        // Need >= 50% of 4 non-self reporters to file = 2 reporters minimum
        // 3 reporters say no_show, 1 says attended → no_show wins

        $target = $players[0];

        // 3 reporters say no_show
        fileReport($game, $players[1], $target, 'no_show');
        fileReport($game, $players[2], $target, 'no_show');
        fileReport($game, $players[3], $target, 'no_show');

        // 1 reporter says attended
        fileReport($game, $host, $target, 'attended');

        $resolved = resolveAndRefresh($game);

        // 3 no_show vs 1 attended: no_show weighted = 3.0 > 50% of 4.0 total → NoShow
        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::NoShow);
    });

    test('tie (2 attended, 2 no_show) → Attended (default wins ties)', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(5);
        // Target = players[0], 4 non-self participants
        $target = $players[0];

        // 2 say no_show
        fileReport($game, $players[1], $target, 'no_show');
        fileReport($game, $players[2], $target, 'no_show');

        // 2 say attended
        fileReport($game, $players[3], $target, 'attended');
        fileReport($game, $host, $target, 'attended');

        $resolved = resolveAndRefresh($game);

        // weighted no_show = 2.0, total weighted = 4.0
        // 2.0 > 50% of 4.0 = 2.0? No, must be strictly > 50% → Attended
        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::Attended);
    });

    test('single reporter in 5-person game → threshold not met, Attended', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(5);
        // Target = players[0], 4 non-self participants
        // Only 1 reporter files → 1/4 = 25% < 50% threshold

        $target = $players[0];
        fileReport($game, $players[1], $target, 'no_show');

        $resolved = resolveAndRefresh($game);

        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::Attended);
    });
});

// ══════════════════════════════════════════════════════════════════════════
// 2. Host excused override
// ══════════════════════════════════════════════════════════════════════════

describe('host excused override', function () {
    test('majority no_show + host excused → Excused', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(5);
        $target = $players[0];

        // Majority say no_show
        fileReport($game, $players[1], $target, 'no_show');
        fileReport($game, $players[2], $target, 'no_show');
        fileReport($game, $players[3], $target, 'no_show');

        // Host says excused (overrides everything)
        fileReport($game, $host, $target, 'excused', 1.0, false, 'Family emergency');

        $resolved = resolveAndRefresh($game);

        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::Excused);
    });

    test('no majority + host excused → Excused (host override works even without consensus)', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(5);
        $target = $players[0];

        // Only 1 reporter below threshold + host excused
        fileReport($game, $players[1], $target, 'attended');

        // Host excused (reason required for excused)
        fileReport($game, $host, $target, 'excused', 1.0, false, 'Sick');

        $resolved = resolveAndRefresh($game);

        // Even though only 2/4 reporters filed (50% = threshold met),
        // host excused check happens before vote counting
        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::Excused);
    });
});

// ══════════════════════════════════════════════════════════════════════════
// 3. Host no-show (with HOST_WEIGHTS)
// ══════════════════════════════════════════════════════════════════════════

describe('host no-show', function () {
    test('players report host as no_show (majority) → NoShow with HOST_WEIGHTS', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(5);
        // Host is the target. 4 non-self participants.
        // Need >= 2 reporters (50% of 4).

        // 3 players report host as no_show
        fileReport($game, $players[0], $host, 'no_show');
        fileReport($game, $players[1], $host, 'no_show');
        fileReport($game, $players[2], $host, 'no_show');

        // 1 player says attended
        fileReport($game, $players[3], $host, 'attended');

        $resolved = resolveAndRefresh($game);

        // Host should be NoShow with host_no_show weight
        expect(getAttendanceStatus($resolved, $host))->toBe(AttendanceStatus::NoShow);

        $hostParticipant = GameParticipant::where('game_id', $resolved->id)
            ->where('user_id', $host->id)
            ->first();

        expect($hostParticipant->attendance_weight)->toBe(ReliabilityScoreService::HOST_WEIGHTS['host_no_show']);
    });
});

// ══════════════════════════════════════════════════════════════════════════
// 4. Participation threshold
// ══════════════════════════════════════════════════════════════════════════

describe('participation threshold', function () {
    test('2 of 5 reporters filed (40%) → threshold not met, all Attended', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(5);
        $target = $players[0];
        // 4 non-self participants, need >= 2 (50%) to meet threshold
        // Only 1 files → below threshold

        fileReport($game, $players[1], $target, 'no_show');

        $resolved = resolveAndRefresh($game);
        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::Attended);
    });

    test('3 of 5 reporters filed (60%) → threshold met, consensus applies', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(5);
        $target = $players[0];
        // 4 non-self, need >= 2 reporters → 3 is well above

        fileReport($game, $players[1], $target, 'no_show');
        fileReport($game, $players[2], $target, 'no_show');
        fileReport($game, $players[3], $target, 'no_show');

        $resolved = resolveAndRefresh($game);
        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::NoShow);
    });

    test('exactly 50% filed → threshold met', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(5);
        $target = $players[0];
        // 4 non-self participants, need >= 2.0 → exactly 2 meets threshold

        fileReport($game, $players[1], $target, 'no_show');
        fileReport($game, $players[2], $target, 'no_show');

        $resolved = resolveAndRefresh($game);
        // 2 no_show vs 0 attended → no_show = 2.0 > 50% of 2.0 → NoShow
        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::NoShow);
    });
});

// ══════════════════════════════════════════════════════════════════════════
// 5. Grief resistance
// ══════════════════════════════════════════════════════════════════════════

describe('grief resistance in resolution', function () {
    test('quarantined reporter vote excluded — weight 0.0 does not count', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(4);
        // 3 participants: host + 2 players. Target = players[0]
        // 2 non-self reporters needed
        $target = $players[0];

        // Player[1] reports no_show with weight 0.0 (quarantined)
        fileReport($game, $players[1], $target, 'no_show', 0.0, true);

        // Host reports attended with weight 1.0
        fileReport($game, $host, $target, 'attended', 1.0);

        $resolved = resolveAndRefresh($game);

        // no_show weighted = 0.0, attended = 1.0, total = 1.0
        // 0.0 > 50% of 1.0? No → Attended
        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::Attended);
    });

    test('low-reliability reporter vote weighted at 0.5', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(4);
        // 3 participants: host + 2 players. Target = players[0]
        $target = $players[0];

        // Player[1] has low reliability → weight 0.5, says no_show
        fileReport($game, $players[1], $target, 'no_show', 0.5);

        // Host says attended with full weight
        fileReport($game, $host, $target, 'attended', 1.0);

        $resolved = resolveAndRefresh($game);

        // no_show weighted = 0.5, attended = 1.0, total = 1.5
        // 0.5 > 50% of 1.5 = 0.75? No → Attended
        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::Attended);
    });

    test('mix of normal and weighted votes — weighted no_show barely wins', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(5);
        // 5 participants: host + 4 players. Target = players[0]
        // 4 non-self reporters
        $target = $players[0];

        // 2 reporters at full weight say no_show = 2.0
        fileReport($game, $players[1], $target, 'no_show', 1.0);
        fileReport($game, $players[2], $target, 'no_show', 1.0);

        // 1 reporter at weight 0.5 says no_show = 0.5 → total no_show = 2.5
        fileReport($game, $players[3], $target, 'no_show', 0.5);

        // Host says attended = 1.0
        fileReport($game, $host, $target, 'attended', 1.0);

        $resolved = resolveAndRefresh($game);

        // no_show = 2.5, attended = 1.0, total = 3.5
        // 2.5 > 50% of 3.5 = 1.75? Yes → NoShow
        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::NoShow);
    });
});

// ══════════════════════════════════════════════════════════════════════════
// 6. Small tables
// ══════════════════════════════════════════════════════════════════════════

describe('small tables', function () {
    test('2-person game (host + 1): single reporter carries', function () {
        $host = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $system->id,
            'status' => GameStatus::Completed->value,
            'date_time' => now()->subHours(3),
            'attendance_window_opens_at' => now()->subHour(),
            'attendance_window_closes_at' => now()->addHours(72),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Player reports host as no_show. Host is the only non-self reporter for the player (1/1 = 100% threshold met)
        fileReport($game, $player, $host, 'no_show');

        // Host reports player as attended
        fileReport($game, $host, $player, 'attended');

        $resolved = resolveAndRefresh($game);

        expect(getAttendanceStatus($resolved, $host))->toBe(AttendanceStatus::NoShow);
        expect(getAttendanceStatus($resolved, $player))->toBe(AttendanceStatus::Attended);
    });

    test('3-person game: 2 reporters, majority works', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(3);
        // 3 participants: host + 2 players. Target = players[0]
        // 2 non-self reporters, need >= 1.0 (50% of 2)

        $target = $players[0];

        // Both say no_show
        fileReport($game, $host, $target, 'no_show');
        fileReport($game, $players[1], $target, 'no_show');

        $resolved = resolveAndRefresh($game);

        // no_show = 2.0 > 50% of 2.0 → NoShow
        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::NoShow);
    });
});

// ══════════════════════════════════════════════════════════════════════════
// 7. Pre-game statuses
// ══════════════════════════════════════════════════════════════════════════

describe('pre-game statuses', function () {
    test('participants with LateCancel are skipped during resolution', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(4);
        // Set players[0] as late_cancel before resolution
        $target = $players[0];
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $target->id)
            ->first();
        $participant->forceFill(['attendance_status' => AttendanceStatus::LateCancel->value])->save();

        // Other players report the target as no_show (which would normally resolve as no_show)
        fileReport($game, $players[1], $target, 'no_show');
        fileReport($game, $host, $target, 'no_show');

        $resolved = resolveAndRefresh($game);

        // Should remain LateCancel — resolution skips pre-game statuses
        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::LateCancel);
    });

    test('participants with CancelledEarly are skipped during resolution', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(4);
        $target = $players[0];
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $target->id)
            ->first();
        $participant->forceFill(['attendance_status' => AttendanceStatus::CancelledEarly->value])->save();

        fileReport($game, $players[1], $target, 'no_show');
        fileReport($game, $host, $target, 'no_show');

        $resolved = resolveAndRefresh($game);

        expect(getAttendanceStatus($resolved, $target))->toBe(AttendanceStatus::CancelledEarly);
    });
});

// ══════════════════════════════════════════════════════════════════════════
// 8. Idempotency
// ══════════════════════════════════════════════════════════════════════════

describe('idempotency', function () {
    test('resolveGameAttendance called twice → no changes on second call', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(4);
        $target = $players[0];

        fileReport($game, $players[1], $target, 'no_show');
        fileReport($game, $host, $target, 'no_show');

        // First resolution
        $resolved1 = resolveAndRefresh($game);
        $resolvedAt1 = $resolved1->attendance_resolved_at;
        $status1 = getAttendanceStatus($resolved1, $target);

        // Second resolution — should be a no-op
        $resolved2 = resolveAndRefresh($resolved1);
        $resolvedAt2 = $resolved2->attendance_resolved_at;
        $status2 = getAttendanceStatus($resolved2, $target);

        expect($resolvedAt1->toIso8601String())->toBe($resolvedAt2->toIso8601String());
        expect($status1)->toBe($status2);
        expect($status1)->toBe(AttendanceStatus::NoShow);
    });

    test('resolveGameAttendance called twice → AttendanceResolved notifications fire only once', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(4);
        $target = $players[0];

        fileReport($game, $players[1], $target, 'no_show');
        fileReport($game, $host, $target, 'no_show');

        // Count NotificationService::send calls. Before the fix the notification
        // fan-out ran unconditionally after the transaction, so a second (bailing)
        // call re-sent an AttendanceResolved notification to every participant.
        $sendCount = 0;
        $this->app->instance(
            NotificationService::class,
            mock(NotificationService::class, function (MockInterface $m) use (&$sendCount) {
                $m->shouldReceive('send')->andReturnUsing(function () use (&$sendCount) {
                    $sendCount++;

                    return null;
                });
            }),
        );

        [$service] = getAttendanceServiceWithMock();
        $service->resolveGameAttendance($game);
        // Second call bails inside the transaction (already resolved) — must not re-notify.
        $service->resolveGameAttendance($game->fresh());

        // 4 approved participants each notified exactly once on the first call.
        expect($sendCount)->toBe(4);
    });
});

// ══════════════════════════════════════════════════════════════════════════
// 9. Early resolution
// ══════════════════════════════════════════════════════════════════════════

describe('early resolution', function () {
    test('all participants file → resolution triggers immediately via submitReport', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(3);
        // 3 participants: host + 2 players
        // All file reports → should auto-resolve

        // Mock reliability service for submitReport
        $mock = mock(ReliabilityScoreService::class, function (MockInterface $m) {
            $m->shouldReceive('recomputeAfterAttendance')->zeroOrMoreTimes();
        });
        $service = new AttendanceService($mock);

        // Host submits report for player 0
        $result = $service->submitReport($game, $host, [
            ['reported_id' => $players[0]->id, 'status' => 'attended'],
            ['reported_id' => $players[1]->id, 'status' => 'attended'],
        ]);
        expect($result['success'])->toBeTrue();

        // Player 0 submits report for host and player 1 — now all 3 have filed
        $result = $service->submitReport($game, $players[0], [
            ['reported_id' => $host->id, 'status' => 'attended'],
            ['reported_id' => $players[1]->id, 'status' => 'attended'],
        ]);
        expect($result['success'])->toBeTrue();

        // At this point 2 of 3 participants have filed — not yet all
        // Player 1 files — now all 3 have filed → triggers early resolution
        $result = $service->submitReport($game, $players[1], [
            ['reported_id' => $host->id, 'status' => 'attended'],
            ['reported_id' => $players[0]->id, 'status' => 'attended'],
        ]);
        expect($result['success'])->toBeTrue();

        $resolved = $game->fresh();
        expect($resolved->attendance_resolved_at)->not->toBeNull();
        expect($resolved->attendance_resolution_method)->toBe(AttendanceResolutionMethod::EarlyConsensus->value);
    });
});

// ══════════════════════════════════════════════════════════════════════════
// Rate limiting on the submit path
// ══════════════════════════════════════════════════════════════════════════

describe('rate limiting', function () {
    test('blocks a user after 10 attendance submissions within a minute', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(3);
        [$service, $mock] = getAttendanceIntakeServiceWithMock();

        // The rate limiter increments on every submitReport call regardless of
        // downstream validation. Fire 10 attempts to exhaust the bucket (these
        // may fail on dedup/grief-resistance, but each still costs a hit).
        for ($i = 0; $i < 10; $i++) {
            $service->submitReport($game, $host, [
                ['reported_id' => $players[0]->id, 'status' => 'attended'],
            ]);
        }

        // 11th attempt from the same user is blocked by the limiter before any
        // other validation runs.
        $r = $service->submitReport($game, $host, [
            ['reported_id' => $players[1]->id, 'status' => 'attended'],
        ]);
        expect($r['success'])->toBeFalse();
        expect($r['reason'])->toBe(__('games.error_attendance_rate_limited'));
    });

    test('the limit is per-user — a different user is not affected', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(3);
        [$service, $mock] = getAttendanceIntakeServiceWithMock();

        // Host exhausts their limiter
        for ($i = 0; $i < 11; $i++) {
            $service->submitReport($game, $host, [
                ['reported_id' => $players[0]->id, 'status' => 'attended'],
            ]);
        }

        // A different user submitting is still allowed
        $r = $service->submitReport($game, $players[0], [
            ['reported_id' => $host->id, 'status' => 'attended'],
        ]);
        expect($r['success'])->toBeTrue();
    });
});

// ══════════════════════════════════════════════════════════════════════════
// Resolution method tracking
// ══════════════════════════════════════════════════════════════════════════

describe('resolution method tracking', function () {
    test('default resolution method is Timeout', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(3);

        $resolved = resolveAndRefresh($game); // No method specified → defaults to Timeout

        expect($resolved->attendance_resolution_method)->toBe(AttendanceResolutionMethod::Timeout->value);
    });

    test('explicit method is persisted', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(3);

        $resolved = resolveAndRefresh($game, AttendanceResolutionMethod::Manual);

        expect($resolved->attendance_resolution_method)->toBe(AttendanceResolutionMethod::Manual->value);
    });
});

// ══════════════════════════════════════════════════════════════════════════
// Weight application on resolved participants
// ══════════════════════════════════════════════════════════════════════════

describe('weight application', function () {
    test('non-host participant resolved as NoShow gets standard no_show weight', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(4);
        $target = $players[0];

        fileReport($game, $players[1], $target, 'no_show');
        fileReport($game, $host, $target, 'no_show');

        $resolved = resolveAndRefresh($game);

        $participant = GameParticipant::where('game_id', $resolved->id)
            ->where('user_id', $target->id)
            ->first();

        expect($participant->attendance_weight)->toBe(ReliabilityScoreService::WEIGHTS['no_show']);
    });

    test('participant resolved as Attended gets attended weight', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(4);
        $target = $players[0];

        fileReport($game, $players[1], $target, 'attended');
        fileReport($game, $host, $target, 'attended');

        $resolved = resolveAndRefresh($game);

        $participant = GameParticipant::where('game_id', $resolved->id)
            ->where('user_id', $target->id)
            ->first();

        expect($participant->attendance_weight)->toBe(ReliabilityScoreService::WEIGHTS['attended']);
    });

    test('participant resolved as Excused gets excused weight', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(4);
        $target = $players[0];

        fileReport($game, $host, $target, 'excused', 1.0, false, 'Personal reasons');
        fileReport($game, $players[1], $target, 'no_show');

        $resolved = resolveAndRefresh($game);

        $participant = GameParticipant::where('game_id', $resolved->id)
            ->where('user_id', $target->id)
            ->first();

        expect($participant->attendance_weight)->toBe(ReliabilityScoreService::WEIGHTS['excused']);
    });
});

// ── corroboration prevents grief-resistance quarantine ────

describe('corroboration during resolution', function () {
    test('resolveGameAttendance corroborates agreeing reports (timeout safety net)', function () {
        ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGameWithPlayers(4);
        $target = $players[0];

        // Two independent reporters agree on no_show for $target, but these were
        // filed directly (bypassing submitReport), so corroboration only happens
        // at resolution time — exactly the timeout-sweeper scenario.
        fileReport($game, $host, $target, 'no_show');
        fileReport($game, $players[1], $target, 'no_show');

        resolveAndRefresh($game);

        $reports = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $target->id)
            ->where('status', 'no_show')
            ->get();

        expect($reports)->toHaveCount(2);
        expect($reports->every(fn ($r) => $r->is_corroborated === true))->toBeTrue();
    });

    test('a host who reports three corroborated games is NOT quarantined', function () {
        // Regression for the production symptom: a legitimate host reporting
        // attendance for 3 games in 30 days was quarantined because every report
        // stayed is_corroborated=false. With corroboration restored, the host's
        // reports get corroborated by a second independent reporter and drop out
        // of the uncorroborated-game count.
        [$service, $mock] = getAttendanceIntakeServiceWithMock();

        // Host under test
        $host = User::factory()->create(['profile_complete' => true]);

        // Build 3 distinct completed games, each with the host + 2 players,
        // and have the host + one player both report the other player attended.
        for ($g = 0; $g < 3; $g++) {
            $otherHost = User::factory()->create(['profile_complete' => true]);
            $system = GameSystem::factory()->create();
            $game = Game::factory()->create([
                'owner_id' => $otherHost->id,
                'game_system_id' => $system->id,
                'status' => GameStatus::Completed->value,
                'date_time' => now()->subHours(3),
                'attendance_window_opens_at' => now()->subHour(),
                'attendance_window_closes_at' => now()->addHours(72),
            ]);

            foreach ([$otherHost, $host, User::factory()->create(['profile_complete' => true])] as $u) {
                GameParticipant::create([
                    'game_id' => $game->id, 'user_id' => $u->id,
                    'role' => $u->is($otherHost) ? 'owner' : 'player',
                    'status' => ParticipantStatus::Approved->value,
                ]);
            }

            $reported = $game->participants()->where('user_id', '!=', $host->id)->where('role', 'player')->first()->user;

            // Host files (the report that would otherwise count against them)
            $service->submitReport($game, $host, [
                ['reported_id' => $reported->id, 'status' => 'attended'],
            ]);
            // A second independent reporter agrees — this must corroborate the host's report
            $service->submitReport($game, $otherHost, [
                ['reported_id' => $reported->id, 'status' => 'attended'],
            ]);

            expect(AttendanceReport::where('game_id', $game->id)
                ->where('reporter_id', $host->id)
                ->where('reported_id', $reported->id)
                ->value('is_corroborated'))
                ->toBeTrue();
        }

        // Build one more completed game for the grief-resistance probe so the
        // host is an approved participant of it.
        $probeHost = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create();
        $probeGame = Game::factory()->create([
            'owner_id' => $probeHost->id,
            'game_system_id' => $system->id,
            'status' => GameStatus::Completed->value,
            'date_time' => now()->subHours(3),
            'attendance_window_opens_at' => now()->subHour(),
            'attendance_window_closes_at' => now()->addHours(72),
        ]);
        foreach ([$probeHost, $host] as $u) {
            GameParticipant::create([
                'game_id' => $probeGame->id, 'user_id' => $u->id,
                'role' => $u->is($probeHost) ? 'owner' : 'player',
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        $grief = $service->checkGriefResistance($host, $probeGame);

        expect($grief['allowed'])->toBeTrue();
        expect($grief['quarantined'])->toBeFalse();
    });
});
