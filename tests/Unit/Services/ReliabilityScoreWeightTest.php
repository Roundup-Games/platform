<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\ReliabilityScoreService;

beforeEach(function () {
    $this->service = app(ReliabilityScoreService::class);
});

// ── Helpers ──────────────────────────────────────────────

function reliabilityCreateParticipant(User $user, AttendanceStatus $status, ?float $attendanceWeight = null, ?int $reportedBy = null): GameParticipant
{
    $game = Game::factory()->create();
    $participant = GameParticipant::factory()->create([
        'user_id' => $user->id,
        'game_id' => $game->id,
        'attendance_status' => $status,
        'attendance_weight' => $attendanceWeight,
        'attendance_reported_by' => $reportedBy,
    ]);

    return $participant;
}

// ── attendance_weight multiplier ─────────────────────────

describe('attendance_weight multiplier', function () {
    it('applies attendance_weight multiplier to peer-reported no_show', function () {
        $user = User::factory()->create();
        $reporterId = User::factory()->create()->id;

        // no_show has base weight -1.0; with multiplier 0.5 → -0.5
        reliabilityCreateParticipant($user, AttendanceStatus::NoShow, 0.5, $reporterId);

        // Need 5 total to get past MIN_GAMES
        for ($i = 0; $i < 4; $i++) {
            reliabilityCreateParticipant($user, AttendanceStatus::Attended, 1.0, null);
        }

        $result = $this->service->computeScore($user);

        // 4*1.0 + 1*(-1.0*0.5) = 4.0 - 0.5 = 3.5 / 5 * 100 = 70.0
        expect($result['score'])->toBe(70.0);
    });

    it('applies attendance_weight multiplier to peer-reported attended', function () {
        $user = User::factory()->create();
        $reporterId = User::factory()->create()->id;

        // attended has base weight 1.0; with multiplier 0.7 → 0.7
        reliabilityCreateParticipant($user, AttendanceStatus::Attended, 0.7, $reporterId);

        // Need 4 more for 5 total
        for ($i = 0; $i < 4; $i++) {
            reliabilityCreateParticipant($user, AttendanceStatus::Attended, 1.0, null);
        }

        $result = $this->service->computeScore($user);

        // 4*1.0 + 1*(1.0*0.7) = 4.7 / 5 * 100 = 94.0
        expect($result['score'])->toBe(94.0);
    });

    it('uses base weight when attendance_weight is null', function () {
        $user = User::factory()->create();
        $reporterId = User::factory()->create()->id;

        // no attendance_weight set → defaults to 1.0 multiplier in resolveWeight
        reliabilityCreateParticipant($user, AttendanceStatus::Attended, null, $reporterId);

        for ($i = 0; $i < 4; $i++) {
            reliabilityCreateParticipant($user, AttendanceStatus::Attended, null, null);
        }

        $result = $this->service->computeScore($user);

        // All 5 attended at full weight → 100%
        expect($result['score'])->toBe(100.0);
    });

    it('uses base weight for system-generated attendance (auto-attend)', function () {
        $user = User::factory()->create();

        // System-generated: attendance_reported_by is null (auto-attend)
        reliabilityCreateParticipant($user, AttendanceStatus::Attended, 1.0, null);

        // Self-report: reporter === reported user
        $game = Game::factory()->create();
        GameParticipant::factory()->create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'attendance_status' => AttendanceStatus::Attended,
            'attendance_weight' => 1.0,
            'attendance_reported_by' => $user->id,
        ]);

        for ($i = 0; $i < 3; $i++) {
            reliabilityCreateParticipant($user, AttendanceStatus::Attended, 1.0, null);
        }

        $result = $this->service->computeScore($user);

        // All 5 attended, all system-generated → full weight
        expect($result['score'])->toBe(100.0);
    });
});

// ── cancelled_early ──────────────────────────────────────

describe('cancelled_early', function () {
    it('counts cancelled_early toward min games threshold', function () {
        $user = User::factory()->create();

        // 4 attended + 1 cancelled_early = 5 games total (meets MIN_GAMES)
        for ($i = 0; $i < 4; $i++) {
            reliabilityCreateParticipant($user, AttendanceStatus::Attended, 1.0, null);
        }
        reliabilityCreateParticipant($user, AttendanceStatus::CancelledEarly, 0.0, null);

        $result = $this->service->computeScore($user);

        // 5 games meets MIN_GAMES → tier should be 'reliable' (score = 80.0)
        expect($result['game_count'])->toBe(5);
        expect($result['tier'])->not->toBe('newcomer');
        // (4*1.0 + 1*0.0) / 5 * 100 = 80.0
        expect($result['score'])->toBe(80.0);
    });

    it('cancelled_early has zero weight', function () {
        $user = User::factory()->create();

        $participant = reliabilityCreateParticipant($user, AttendanceStatus::CancelledEarly, null, null);

        $weight = $this->service->resolveWeight($participant, AttendanceStatus::CancelledEarly);

        expect($weight)->toBe(0.0);
    });
});
