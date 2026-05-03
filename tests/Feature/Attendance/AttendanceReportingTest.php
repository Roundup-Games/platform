<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class AttendanceReportingTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale;

    // smoke: core attendance flow — peer reports attendance for another player
    #[\PHPUnit\Framework\Attributes\Group('smoke')]
    #[Test]
    public function test_peer_reports_attendance(): void
    {
        [$game, $host, $reporter, $reported] = $this->createPastGameWithParticipants(2);

        $service = app(\App\Services\AttendanceService::class);
        $result = $service->reportAttendance($game, $reporter, $reported, 'attended');

        $this->assertTrue($result['success']);
        $this->assertEquals('Attendance recorded', $result['reason']);

        // Participant should have attendance recorded
        $reportedParticipant = $game->participants()->where('user_id', $reported->id)->first();
        $this->assertEquals(AttendanceStatus::Attended, $reportedParticipant->attendance_status);
        $this->assertNotNull($reportedParticipant->attendance_reported_at);
        $this->assertEquals(1.0, $reportedParticipant->attendance_weight);

        // AttendanceReport should exist
        $this->assertDatabaseHas('attendance_reports', [
            'game_id' => $game->id,
            'reporter_id' => $reporter->id,
            'reported_id' => $reported->id,
            'status' => 'attended',
        ]);
    }

    #[Test]
    public function test_host_reported_as_no_show(): void
    {
        [$game, $host, $player] = $this->createPastGameWithParticipants(1);

        $service = app(\App\Services\AttendanceService::class);
        $result = $service->reportAttendance($game, $player, $host, 'no_show');

        $this->assertTrue($result['success']);

        $hostParticipant = $game->participants()->where('user_id', $host->id)->first();
        $this->assertEquals(AttendanceStatus::NoShow, $hostParticipant->attendance_status);
    }

    #[Test]
    public function test_host_cannot_self_report(): void
    {
        [$game, $host] = $this->createPastGameWithParticipants(0);
        // Add at least one other participant so host isn't alone
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $service = app(\App\Services\AttendanceService::class);
        $result = $service->reportAttendance($game, $host, $host, 'attended');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Host cannot self-report', $result['reason']);
    }

    #[Test]
    public function test_non_participant_cannot_report(): void
    {
        [$game, $host, $player, $target] = $this->createPastGameWithParticipants(2);
        $outsider = User::factory()->create();

        $service = app(\App\Services\AttendanceService::class);
        $result = $service->reportAttendance($game, $outsider, $target, 'attended');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not a participant', $result['reason']);
    }

    #[Test]
    public function test_report_before_game_date_fails(): void
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(7), // future game
            'status' => 'scheduled',
        ]);
        $player = User::factory()->create();
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

        $service = app(\App\Services\AttendanceService::class);
        $result = $service->reportAttendance($game, $player, $host, 'attended');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('future game', $result['reason']);
    }

    #[Test]
    public function test_report_triggers_reliability_recomputation(): void
    {
        [$game, $host, $reporter, $reported] = $this->createPastGameWithParticipants(2);

        $service = app(\App\Services\AttendanceService::class);
        $result = $service->reportAttendance($game, $reporter, $reported, 'attended');

        $this->assertTrue($result['success']);

        // Reliability score should have been computed for the reported user
        $reported->refresh();
        $this->assertNotNull($reported->reliability_score);
        $this->assertArrayHasKey('score', $reported->reliability_score);
        $this->assertArrayHasKey('tier', $reported->reliability_score);
    }

    /**
     * Helper to set up games with participants for attendance tests.
     *
     * @return array{0: Game, 1: User, 2: User, ...}
     */
    private function createPastGameWithParticipants(int $extraPlayers = 0): array
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->subDays(1),
            'status' => 'completed',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $players = [];
        for ($i = 0; $i < $extraPlayers; $i++) {
            $player = User::factory()->create();
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'role' => 'player',
                'status' => ParticipantStatus::Approved->value,
            ]);
            $players[] = $player;
        }

        return [$game, $host, ...$players];
    }
}
