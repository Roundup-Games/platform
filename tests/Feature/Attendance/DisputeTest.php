<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DisputeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_dispute_filed_successfully(): void
    {
        $host = User::factory()->create();
        $reporter = User::factory()->create();
        $reported = User::factory()->create();

        $game = $this->createPastGame($host, [$reporter, $reported]);
        $participant = $game->participants()->where('user_id', $reported->id)->first();

        // First report the user as no_show
        $service = app(\App\Services\AttendanceService::class);
        $service->reportAttendance($game, $reporter, $reported, 'no_show');

        // Now dispute
        $result = $service->disputeAttendanceReport($participant->id, 'I was there the whole time', $reported);

        $this->assertTrue($result['success']);
        $this->assertEquals('Dispute filed', $result['reason']);

        // Participant should have dispute reason
        $participant->refresh();
        $this->assertEquals('I was there the whole time', $participant->attendance_dispute_reason);

        // Attendance reports should be marked as disputed
        $this->assertDatabaseHas('attendance_reports', [
            'game_id' => $game->id,
            'reported_id' => $reported->id,
            'dispute_reason' => 'I was there the whole time',
        ]);
    }

    #[Test]
    public function test_auto_resolve_when_2_plus_attended_reports(): void
    {
        $host = User::factory()->create();
        $reporter1 = User::factory()->create();
        $reporter2 = User::factory()->create();
        $disputed = User::factory()->create();

        $game = $this->createPastGame($host, [$reporter1, $reporter2, $disputed]);
        $participant = $game->participants()->where('user_id', $disputed->id)->first();

        // Someone reports disputed user as no_show
        $service = app(\App\Services\AttendanceService::class);
        $service->reportAttendance($game, $reporter1, $disputed, 'no_show');

        // But 2 others report them as attended
        $service->reportAttendance($game, $reporter2, $disputed, 'attended');
        // Host also reports attended
        $service->reportAttendance($game, $host, $disputed, 'attended');

        // Now dispute
        $service->disputeAttendanceReport($participant->id, 'Wrongful no_show', $disputed);

        // Resolve
        $outcome = $service->resolveDispute($participant);

        $this->assertEquals('resolved_favor', $outcome);

        $participant->refresh();
        $this->assertEquals(AttendanceStatus::Attended, $participant->attendance_status);
        $this->assertEquals(1.0, $participant->attendance_weight);
    }

    #[Test]
    public function test_uncorroborated_report_stands_reduced_weight(): void
    {
        $host = User::factory()->create();
        $reporter = User::factory()->create();
        $disputed = User::factory()->create();

        $game = $this->createPastGame($host, [$reporter, $disputed]);
        $participant = $game->participants()->where('user_id', $disputed->id)->first();

        // Report user as no_show (only 1 report, no corroboration)
        $service = app(\App\Services\AttendanceService::class);
        $service->reportAttendance($game, $reporter, $disputed, 'no_show');

        // Dispute
        $service->disputeAttendanceReport($participant->id, 'I attended', $disputed);

        // Resolve — only 0 corroborating attended reports
        $outcome = $service->resolveDispute($participant);

        $this->assertEquals('upheld', $outcome);

        $participant->refresh();
        // Weight should be reduced
        $this->assertLessThan(1.0, $participant->attendance_weight);
        // Status should still be no_show
        $this->assertEquals(AttendanceStatus::NoShow, $participant->attendance_status);
    }

    #[Test]
    public function test_dispute_notification_sent(): void
    {
        $host = User::factory()->create();
        $reporter = User::factory()->create();
        $reported = User::factory()->create();

        $game = $this->createPastGame($host, [$reporter, $reported]);
        $participant = $game->participants()->where('user_id', $reported->id)->first();

        $service = app(\App\Services\AttendanceService::class);
        $service->reportAttendance($game, $reporter, $reported, 'no_show');

        // File dispute
        $result = $service->disputeAttendanceReport($participant->id, 'Unfair report', $reported);

        $this->assertTrue($result['success']);

        // Verify the dispute reason is stored
        $participant->refresh();
        $this->assertNotNull($participant->attendance_dispute_reason);

        // Verify attendance reports have dispute columns set
        $disputedReports = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $reported->id)
            ->whereNotNull('dispute_reason')
            ->count();

        $this->assertGreaterThanOrEqual(1, $disputedReports);
    }

    // ── Dispute Authorization ──────────────────────────

    #[Test]
    public function test_reported_user_can_dispute(): void
    {
        $host = User::factory()->create();
        $reporter = User::factory()->create();
        $reported = User::factory()->create();

        $game = $this->createPastGame($host, [$reporter, $reported]);
        $participant = $game->participants()->where('user_id', $reported->id)->first();

        $service = app(\App\Services\AttendanceService::class);
        $service->reportAttendance($game, $reporter, $reported, 'no_show');

        $result = $service->disputeAttendanceReport($participant->id, 'I was there', $reported);

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function test_game_host_can_dispute(): void
    {
        $host = User::factory()->create();
        $reporter = User::factory()->create();
        $reported = User::factory()->create();

        $game = $this->createPastGame($host, [$reporter, $reported]);
        $participant = $game->participants()->where('user_id', $reported->id)->first();

        $service = app(\App\Services\AttendanceService::class);
        $service->reportAttendance($game, $reporter, $reported, 'no_show');

        // Host disputes on behalf of reported user
        $result = $service->disputeAttendanceReport($participant->id, 'I saw them there', $host);

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function test_global_admin_can_dispute(): void
    {
        // Ensure the Platform Admin role exists
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Platform Admin',
            'guard_name' => 'web',
            'team_id' => null,
        ]);

        $host = User::factory()->create();
        $reporter = User::factory()->create();
        $reported = User::factory()->create();

        $game = $this->createPastGame($host, [$reporter, $reported]);
        $participant = $game->participants()->where('user_id', $reported->id)->first();

        $service = app(\App\Services\AttendanceService::class);
        $service->reportAttendance($game, $reporter, $reported, 'no_show');

        // Create global admin
        $admin = User::factory()->create();
        $admin->assignRole('Platform Admin');

        $result = $service->disputeAttendanceReport($participant->id, 'Admin review', $admin);

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function test_unauthorized_user_cannot_dispute(): void
    {
        $host = User::factory()->create();
        $reporter = User::factory()->create();
        $reported = User::factory()->create();

        $game = $this->createPastGame($host, [$reporter, $reported]);
        $participant = $game->participants()->where('user_id', $reported->id)->first();

        $service = app(\App\Services\AttendanceService::class);
        $service->reportAttendance($game, $reporter, $reported, 'no_show');

        // Random unrelated user
        $stranger = User::factory()->create();

        $result = $service->disputeAttendanceReport($participant->id, 'Unrelated', $stranger);

        $this->assertFalse($result['success']);
        $this->assertEquals(__('attendance.error_dispute_unauthorized'), $result['reason']);
    }

    private function createPastGame(User $host, array $players): Game
    {
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->subDay(),
            'status' => 'completed',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        foreach ($players as $player) {
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'role' => 'player',
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        return $game;
    }
}
