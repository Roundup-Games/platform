<?php

namespace Tests\Feature\Services;

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\BenchService;
use App\Services\ParticipantLifecycle;
use App\Services\ReliabilityScoreService;
use App\Services\WaitlistService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Integration tests for the 3 critical host attendance bug fixes:
 * 1. Host attendance can be reported
 * 2. Host cancellation penalties update reliability scores
 * 3. Hosts are included in auto-attend sweeps
 *
 * Plus: notification exclusion and waitlist/bench promotion owner guards.
 */
class HostAttendanceIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private AttendanceService $attendanceService;

    private ReliabilityScoreService $reliabilityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attendanceService = app(AttendanceService::class);
        $this->reliabilityService = app(ReliabilityScoreService::class);
    }

    // ── 1. Host Attendance Reporting ──────────────────

    public function test_host_attendance_can_be_reported_by_another_player(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        $game = $this->createCompletedGame($host);

        // Create host (owner) participant
        $hostParticipant = $this->createOwnerParticipant($game, $host);

        // Create a player participant who will report the host
        $this->createPlayerParticipant($game, $player);

        // Player reports host as attended
        $result = $this->attendanceService->reportAttendance(
            $game, $player, $host, AttendanceStatus::Attended->value
        );

        $this->assertTrue($result['success'], 'Host attendance report should succeed: '.$result['reason']);

        $hostParticipant->refresh();
        $this->assertEquals(AttendanceStatus::Attended, $hostParticipant->attendance_status);

        // Verify attendance report created
        $report = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $host->id)
            ->first();
        $this->assertNotNull($report, 'AttendanceReport should be created for host');
    }

    public function test_host_attendance_report_updates_reliability_score(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        $game = $this->createCompletedGame($host);
        $this->createOwnerParticipant($game, $host);
        $this->createPlayerParticipant($game, $player);

        // Player reports host as attended
        $this->attendanceService->reportAttendance(
            $game, $player, $host, AttendanceStatus::Attended->value
        );

        $host->refresh();
        $this->assertNotNull($host->reliability_score, 'Host reliability score should be computed');
        $this->assertEquals(100.0, $host->reliability_score['score'], 'Host with 1 attended should have 100% score');
        $this->assertEquals(1, $host->reliability_score['game_count']);
    }

    public function test_host_cannot_self_report_attendance(): void
    {
        $host = User::factory()->create();
        $game = $this->createCompletedGame($host);
        $this->createOwnerParticipant($game, $host);

        $result = $this->attendanceService->reportAttendance(
            $game, $host, $host, AttendanceStatus::Attended->value
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Host cannot self-report', $result['reason']);
    }

    public function test_host_attendance_reported_as_no_show_decreases_reliability(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        $game = $this->createCompletedGame($host);
        $this->createOwnerParticipant($game, $host);
        $this->createPlayerParticipant($game, $player);

        // Player reports host as no-show
        $result = $this->attendanceService->reportAttendance(
            $game, $player, $host, AttendanceStatus::NoShow->value
        );

        $this->assertTrue($result['success'], 'Reporting host as no-show should succeed: '.$result['reason']);

        $host->refresh();
        // No-show as host uses HOST_WEIGHTS['host_no_show'] = -1.5
        // Score = -1.5 / 1 * 100 = clamped to 0.0
        $this->assertEquals(0.0, $host->reliability_score['score'], 'Host no-show should tank reliability');
    }

    // ── 2. Host Cancellation Penalty ──────────────────

    public function test_host_late_cancellation_creates_attendance_report(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        // Game scheduled <24h from now
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Canceled,
            'date_time' => now()->addHours(12), // 12h from now = late cancel
            'max_players' => 6,
        ]);

        $this->createOwnerParticipant($game, $host);
        $this->createPlayerParticipant($game, $player);

        $this->attendanceService->recordHostCancellationOffence($game);

        // Verify attendance report created
        $report = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $host->id)
            ->first();
        $this->assertNotNull($report, 'AttendanceReport should be created for host late cancel');
        $this->assertEquals(AttendanceStatus::LateCancel, $report->status);
        $this->assertEquals(ReliabilityScoreService::HOST_WEIGHTS['host_cancel_late'], $report->weight_applied);
    }

    public function test_host_late_cancellation_updates_reliability_score(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Canceled,
            'date_time' => now()->addHours(12),
            'max_players' => 6,
        ]);

        $this->createOwnerParticipant($game, $host);
        $this->createPlayerParticipant($game, $player);

        $this->attendanceService->recordHostCancellationOffence($game);

        $host->refresh();
        $this->assertNotNull($host->reliability_score, 'Host reliability should be computed after late cancel');
        // HOST_WEIGHTS['host_cancel_late'] = -1.2
        // Score = -1.2 / 1 * 100 = clamped to 0.0
        $this->assertEquals(0.0, $host->reliability_score['score'], 'Host late cancel should produce 0 score');

        // Verify HOST_WEIGHTS applied
        $this->assertArrayHasKey('late_cancel', $host->reliability_score['weights_applied']);
        $this->assertEquals(
            ReliabilityScoreService::HOST_WEIGHTS['host_cancel_late'],
            $host->reliability_score['weights_applied']['late_cancel']
        );
    }

    public function test_host_early_cancellation_does_not_create_offence(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        // Game scheduled >24h from now
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Canceled,
            'date_time' => now()->addHours(48), // 48h = not late
            'max_players' => 6,
        ]);

        $this->createOwnerParticipant($game, $host);
        $this->createPlayerParticipant($game, $player);

        $this->attendanceService->recordHostCancellationOffence($game);

        $report = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $host->id)
            ->first();
        $this->assertNull($report, 'No offence should be recorded for early cancel');
    }

    public function test_host_cancellation_with_no_non_owner_participants_does_not_create_offence(): void
    {
        $host = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Canceled,
            'date_time' => now()->addHours(12), // Late
            'max_players' => 6,
        ]);

        $this->createOwnerParticipant($game, $host);
        // No additional players → non-owner approved count = 0 < hostCancelMinRoster(1)
        // The owner is excluded from the roster count because they are the canceller,
        // not an affected participant.

        $this->attendanceService->recordHostCancellationOffence($game);

        $report = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $host->id)
            ->first();

        $this->assertNull($report, 'No offence should be recorded when only the host (no other players) is present');
    }

    public function test_host_cancellation_non_cancelled_game_skips(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Scheduled, // Not cancelled
            'date_time' => now()->addHours(12),
            'max_players' => 6,
        ]);

        $this->createOwnerParticipant($game, $host);
        $this->createPlayerParticipant($game, $player);

        $this->attendanceService->recordHostCancellationOffence($game);

        $report = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $host->id)
            ->first();
        $this->assertNull($report, 'No offence for non-cancelled game');
    }

    // ── 3. Host Auto-Attend ───────────────────────────

    // NOTE: The old auto-attend-after-48h flow was replaced by the consensus
    // resolution engine. See AttendanceResolutionTest for the new tests.

    // ── 4. Notification Exclusion ─────────────────────

    public function test_host_not_in_debrief_notification_recipients(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        $game = $this->createCompletedGame($host);
        $this->createOwnerParticipant($game, $host);
        $this->createPlayerParticipant($game, $player);

        // DebriefingService::notifyParticipants excludes owner
        $recipients = $game->participants()
            ->where('status', ParticipantStatus::Approved)
            ->where('user_id', '!=', $game->owner_id)
            ->get();

        $this->assertEquals(1, $recipients->count());
        $this->assertEquals($player->id, $recipients->first()->user_id);
        $this->assertNotEquals($host->id, $recipients->first()->user_id,
            'Host should be excluded from notification recipients');
    }

    public function test_host_excluded_from_game_cancellation_notifications(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->addDays(7),
            'max_players' => 6,
        ]);

        $this->createOwnerParticipant($game, $host);
        $this->createPlayerParticipant($game, $player);

        // Simulate the filter used by GamesPage::cancelGame()
        $recipients = $game->participants()
            ->where('status', ParticipantStatus::Approved)
            ->where('user_id', '!=', $game->owner_id)
            ->get();

        $this->assertCount(1, $recipients);
        $this->assertEquals($player->id, $recipients->first()->user_id);
    }

    // ── 5. Waitlist / Bench Owner Exclusion ───────────

    public function test_waitlist_promotion_does_not_promote_owner(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $waitlistedPlayer = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->addDays(7),
            'max_players' => 2, // 2 slots: host + 1 player
        ]);

        $this->createOwnerParticipant($game, $host);
        $this->createPlayerParticipant($game, $player);

        // Waitlist a player
        $waitlisted = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $waitlistedPlayer->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted,
            'waitlisted_at' => now(),
        ]);

        // Also try to waitlist the host — WaitlistService blocks this at addToWaitlist()
        // so we simulate a hypothetical scenario by checking promoteNext won't pick owner
        // The owner has status=Approved, so they wouldn't be in the Waitlisted query anyway.

        // Player drops → delete their participant record
        GameParticipant::where('game_id', $game->id)
            ->where('user_id', $player->id)
            ->delete();

        // Promote from waitlist
        $waitlistService = app(WaitlistService::class);
        $waitlistService->promoteAllOnCancel($game);

        $waitlisted->refresh();
        $this->assertEquals(ParticipantStatus::Pending, $waitlisted->status,
            'Waitlisted player should be promoted to pending (awaiting confirmation)');

        // Verify host is still approved, not affected
        $hostParticipant = $game->participants()->where('user_id', $host->id)->first();
        $this->assertEquals(ParticipantStatus::Approved, $hostParticipant->status,
            'Host participant should remain approved');
    }

    public function test_bench_add_rejects_owner(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->addDays(7),
            'max_players' => 1,
            'bench_mode' => true,
        ]);

        // Fill the game
        $this->createOwnerParticipant($game, $host);
        $this->createPlayerParticipant($game, $player);

        $benchService = app(BenchService::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot add to bench: you are the host');

        $benchService->addToBench($game, $host);
    }

    public function test_bench_entity_cancellation_preserves_owner_status(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $benchedPlayer = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Canceled,
            'date_time' => now()->addDays(7),
            'max_players' => 2,
            'bench_mode' => true,
        ]);

        $this->createOwnerParticipant($game, $host);
        $this->createPlayerParticipant($game, $player);

        // Create a benched player
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $benchedPlayer->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched,
            'benched_at' => now(),
        ]);

        $benchService = app(BenchService::class);
        $benchService->handleEntityCancellation($game);

        // Benched player should be rejected
        $benchedPlayerParticipant = $game->participants()->where('user_id', $benchedPlayer->id)->first();
        $this->assertEquals(ParticipantStatus::Rejected, $benchedPlayerParticipant->status,
            'Benched player should be rejected on cancellation');

        // Owner should NOT be rejected
        $hostParticipant = $game->participants()->where('user_id', $host->id)->first();
        $this->assertEquals(ParticipantStatus::Approved, $hostParticipant->status,
            'Owner participant should NOT be rejected during entity cancellation');
    }

    // ── Integration: Combined Scenarios ────────────────

    public function test_host_with_multiple_games_gets_correct_reliability(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        // Game 1: host attended (as owner)
        $game1 = $this->createCompletedGame($host);
        $this->createOwnerParticipant($game1, $host, attendanceStatus: AttendanceStatus::Attended);
        $this->createPlayerParticipant($game1, $player);

        // Game 2: host attended (as owner)
        $game2 = $this->createCompletedGame($host);
        $this->createOwnerParticipant($game2, $host, attendanceStatus: AttendanceStatus::Attended);

        // Game 3: host late cancel (as owner)
        $game3 = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Canceled,
            'date_time' => now()->addHours(12),
            'max_players' => 6,
        ]);
        $this->createOwnerParticipant($game3, $host, attendanceStatus: AttendanceStatus::LateCancel);

        $result = $this->reliabilityService->computeScore($host);

        // 2 attended * 1.0 + 1 late_cancel * (-1.2 host weight) = 0.8 / 3 * 100 = 26.67
        $this->assertEquals(26.67, $result['score']);
        $this->assertEquals(3, $result['game_count']);
        $this->assertEquals('newcomer', $result['tier']); // <5 games
    }

    // NOTE: test_host_auto_attend_then_manual_report_flow removed —
    // the old auto-attend flow was replaced by the consensus resolution engine.
    // See AttendanceResolutionTest for the new tests.

    // ── Helpers ────────────────────────────────────────

    private function createCompletedGame(User $host, int $maxPlayers = 6): Game
    {
        return Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Completed,
            'date_time' => now()->subDay(),
            'max_players' => $maxPlayers,
        ]);
    }

    private function createOwnerParticipant(
        Game $game,
        User $host,
        ?AttendanceStatus $attendanceStatus = null
    ): GameParticipant {
        return GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved,
            'attendance_status' => $attendanceStatus,
        ]);
    }

    private function createPlayerParticipant(
        Game $game,
        User $player,
        ?AttendanceStatus $attendanceStatus = null
    ): GameParticipant {
        return GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved,
            'attendance_status' => $attendanceStatus,
        ]);
    }

    // ── 5. Cancel-Dodge Prevention ────────────────────

    public function test_host_who_removes_players_then_cancels_still_gets_penalised(): void
    {
        $host = User::factory()->create();
        $player1 = User::factory()->create();
        $player2 = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Canceled,
            'date_time' => now()->addHours(12), // Late cancel
            'max_players' => 6,
        ]);

        $this->createOwnerParticipant($game, $host);
        $p1 = $this->createPlayerParticipant($game, $player1);
        $p2 = $this->createPlayerParticipant($game, $player2);

        // Host removes both players (status -> removed, not hard-deleted)
        app(ParticipantLifecycle::class)
            ->removeParticipant($p1, $game, $host);
        app(ParticipantLifecycle::class)
            ->removeParticipant($p2, $game, $host);

        // Now no 'approved' non-owner participants remain, but 'removed' records exist
        $approvedNonOwner = $game->participants()
            ->where('user_id', '!=', $host->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->count();
        $this->assertEquals(0, $approvedNonOwner, 'No approved non-owner participants');

        $removedCount = $game->participants()
            ->where('user_id', '!=', $host->id)
            ->where('status', ParticipantStatus::Removed->value)
            ->count();
        $this->assertEquals(2, $removedCount, 'Two removed participants');

        // Peak roster check should still catch this
        $this->attendanceService->recordHostCancellationOffence($game);

        $report = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $host->id)
            ->first();
        $this->assertNotNull($report, 'Offence should be recorded despite host removing all players first');
        $this->assertEquals(AttendanceStatus::LateCancel, $report->status);
    }

    public function test_host_cancel_with_only_self_leaves_is_not_penalised(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => GameStatus::Canceled,
            'date_time' => now()->addHours(12), // Late cancel
            'max_players' => 6,
        ]);

        $this->createOwnerParticipant($game, $host);

        // Player was approved but left on their own (hard-deleted, no 'removed' record)
        $playerParticipant = $this->createPlayerParticipant($game, $player);
        $playerParticipant->delete();

        // No approved or removed non-owner participants
        $peakRoster = $game->participants()
            ->where('user_id', '!=', $host->id)
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Removed->value,
            ])
            ->count();
        $this->assertEquals(0, $peakRoster);

        $this->attendanceService->recordHostCancellationOffence($game);

        $report = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $host->id)
            ->first();
        $this->assertNull($report, 'No offence when players left on their own (hard-deleted)');
    }
}
