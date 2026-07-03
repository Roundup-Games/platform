<?php

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\AttendanceResolutionService;
use App\Services\ReliabilityScoreService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

// Criterion 2: a player can RSVP to a public Gathering, join the waitlist when
// full, and have attendance resolved through consensus voting — producing a
// reliability record IDENTICAL IN SHAPE to any other game. A Gathering is just
// a GameType value on the Game model, so this test proves the consensus +
// reliability machinery engages for a Gathering exactly as it does for a
// focused game (the same attendance_status + attendance_weight + computeScore).

/**
 * Build a completed Gathering (multi-system 1-element set, per R046) with the
 * host + N approved players. Mirrors the AttendanceResolutionTest scaffold but
 * types the game as a Gathering.
 *
 * @return array{game: Game, host: User, players: array<int, User>}
 */
function createCompletedGatheringWithPlayers(int $totalParticipants = 4): array
{
    $host = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();

    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_type' => GameType::Gathering->value,
        'game_system_id' => $system->id,
        'game_systems' => [$system->id],
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

it('resolves a Gathering through consensus and produces an identical-shape reliability input', function () {
    ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGatheringWithPlayers(4);
    // 4 participants: host + 3 players. Target = players[0]; 3 non-self reporters.
    $target = $players[0];

    // 2 of 3 reporters say no_show → weighted 2.0 > 50% of 3.0 = 1.5 → NoShow
    AttendanceReport::create([
        'game_id' => $game->id, 'reporter_id' => $host->id, 'reported_id' => $target->id,
        'status' => 'no_show', 'weight_applied' => 1.0, 'is_corroborated' => false,
    ]);
    AttendanceReport::create([
        'game_id' => $game->id, 'reporter_id' => $players[1]->id, 'reported_id' => $target->id,
        'status' => 'no_show', 'weight_applied' => 1.0, 'is_corroborated' => false,
    ]);
    // 1 says attended
    AttendanceReport::create([
        'game_id' => $game->id, 'reporter_id' => $players[2]->id, 'reported_id' => $target->id,
        'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => false,
    ]);

    // Resolve with a real ReliabilityScoreService (no mock) so the full machinery runs.
    $service = new AttendanceResolutionService(app(ReliabilityScoreService::class));
    $service->resolveGameAttendance($game);

    $resolved = $game->fresh();
    $targetParticipant = GameParticipant::where('game_id', $resolved->id)
        ->where('user_id', $target->id)
        ->first();

    // Identical shape to any other game: same AttendanceStatus + same no_show weight.
    expect($targetParticipant->attendance_status)->toBe(AttendanceStatus::NoShow)
        ->and($targetParticipant->attendance_weight)->toBe(ReliabilityScoreService::WEIGHTS['no_show']);

    // The reliability record is produced from the Gathering's resolved attendance —
    // same computeScore() shape every game type yields.
    $score = app(ReliabilityScoreService::class)->computeScore($target);
    expect($score)->toBeArray()
        ->and($score['game_count'])->toBeGreaterThanOrEqual(1)
        ->and($score['score'])->toBeFloat();
});

it('records an attended Gathering participant with the standard attended weight', function () {
    ['game' => $game, 'host' => $host, 'players' => $players] = createCompletedGatheringWithPlayers(4);
    $target = $players[0];

    // Unanimous attended → Attended
    AttendanceReport::create([
        'game_id' => $game->id, 'reporter_id' => $host->id, 'reported_id' => $target->id,
        'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => false,
    ]);
    AttendanceReport::create([
        'game_id' => $game->id, 'reporter_id' => $players[1]->id, 'reported_id' => $target->id,
        'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => false,
    ]);
    AttendanceReport::create([
        'game_id' => $game->id, 'reporter_id' => $players[2]->id, 'reported_id' => $target->id,
        'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => false,
    ]);

    $service = new AttendanceResolutionService(app(ReliabilityScoreService::class));
    $service->resolveGameAttendance($game);

    $targetParticipant = GameParticipant::where('game_id', $game->id)
        ->where('user_id', $target->id)
        ->first();

    expect($targetParticipant->attendance_status)->toBe(AttendanceStatus::Attended)
        ->and($targetParticipant->attendance_weight)->toBe(ReliabilityScoreService::WEIGHTS['attended']);
});
