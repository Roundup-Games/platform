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

function weightCreateParticipationRecord(User $user, AttendanceStatus $status, ?float $attendanceWeight = null, ?int $reportedBy = null): GameParticipant
{
    $game = Game::factory()->create();

    return GameParticipant::factory()->create([
        'user_id' => $user->id,
        'game_id' => $game->id,
        'attendance_status' => $status,
        'attendance_weight' => $attendanceWeight,
        'attendance_reported_by' => $reportedBy,
    ]);
}

// ── attendance_weight multiplier ─────────────────────────

describe('attendance_weight multiplier', function () {
    it('applies attendance_weight multiplier to peer-reported attendance', function () {
        $user = User::factory()->create();
        $reporter = User::factory()->create();

        // Create 4 attended records so we have baseline + the weighted one
        weightCreateParticipationRecord($user, AttendanceStatus::Attended, reportedBy: null);
        weightCreateParticipationRecord($user, AttendanceStatus::Attended, reportedBy: null);
        weightCreateParticipationRecord($user, AttendanceStatus::Attended, reportedBy: null);
        weightCreateParticipationRecord($user, AttendanceStatus::Attended, reportedBy: null);

        // One no_show with weight 0.5, peer-reported
        weightCreateParticipationRecord($user, AttendanceStatus::NoShow, attendanceWeight: 0.5, reportedBy: $reporter->id);

        $result = $this->service->computeScore($user);

        // 4 attended (1.0 each) + 1 no_show with weight 0.5 → -1.0 * 0.5 = -0.5
        // Total = 4.0 - 0.5 = 3.5 / 5.0 * 100 = 70.0
        expect($result['score'])->toBe(70.0);
    });

    it('does not apply weight multiplier to auto-attend records', function () {
        $user = User::factory()->create();

        // 4 attended records (system-generated / auto-attend with weight 0.7)
        for ($i = 0; $i < 4; $i++) {
            weightCreateParticipationRecord($user, AttendanceStatus::Attended, attendanceWeight: 0.7, reportedBy: null);
        }

        // 1 more attended, also system-generated
        weightCreateParticipationRecord($user, AttendanceStatus::Attended, attendanceWeight: 0.7, reportedBy: null);

        $result = $this->service->computeScore($user);

        // All auto-attend (reported_by = null), so weight is ignored, uses base 1.0
        // Total = 5 * 1.0 = 5.0 / 5.0 * 100 = 100.0
        expect($result['score'])->toBe(100.0);
    });

    it('does not apply weight multiplier to self-reported records', function () {
        $user = User::factory()->create();

        // Self-reported (reported_by = own user_id) — treated as system-generated
        for ($i = 0; $i < 5; $i++) {
            weightCreateParticipationRecord($user, AttendanceStatus::Attended, attendanceWeight: 0.5, reportedBy: $user->id);
        }

        $result = $this->service->computeScore($user);

        // Self-reported: weight multiplier ignored, uses base 1.0
        // Total = 5 * 1.0 / 5 * 100 = 100.0
        expect($result['score'])->toBe(100.0);
    });

    it('multiplies weight for late-filed attended report', function () {
        $user = User::factory()->create();
        $reporter = User::factory()->create();

        // 4 fully-weighted attended records
        for ($i = 0; $i < 4; $i++) {
            weightCreateParticipationRecord($user, AttendanceStatus::Attended, reportedBy: null);
        }

        // 1 peer-reported attended with weight 0.7 (late-filed report)
        weightCreateParticipationRecord($user, AttendanceStatus::Attended, attendanceWeight: 0.7, reportedBy: $reporter->id);

        $result = $this->service->computeScore($user);

        // 4 * 1.0 + 1.0 * 0.7 = 4.7 / 5.0 * 100 = 94.0
        expect($result['score'])->toBe(94.0);
    });

    it('handles null attendance_weight as 1.0', function () {
        $user = User::factory()->create();
        $reporter = User::factory()->create();

        // 4 attended records
        for ($i = 0; $i < 4; $i++) {
            weightCreateParticipationRecord($user, AttendanceStatus::Attended, reportedBy: null);
        }

        // 1 peer-reported no_show with null weight → should use 1.0 multiplier
        weightCreateParticipationRecord($user, AttendanceStatus::NoShow, attendanceWeight: null, reportedBy: $reporter->id);

        $result = $this->service->computeScore($user);

        // 4 * 1.0 + (-1.0) * 1.0 = 3.0 / 5.0 * 100 = 60.0
        expect($result['score'])->toBe(60.0);
    });
});
