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

class GriefResistanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_low_reliability_reporter_reduced_weight(): void
    {
        $host = User::factory()->create();
        $reporter = User::factory()->create([
            'reliability_score' => ['score' => 30.0, 'game_count' => 5, 'tier' => 'active'],
        ]);
        $reported = User::factory()->create();

        $game = $this->createPastGame($host, [$reporter, $reported]);

        $service = app(\App\Services\AttendanceService::class);
        $result = $service->reportAttendance($game, $reporter, $reported, 'attended');

        $this->assertTrue($result['success']);

        // Weight should be reduced (0.5 multiplier for low reliability)
        $report = AttendanceReport::where('reporter_id', $reporter->id)->first();
        $this->assertEquals(0.5, $report->weight_applied);
    }

    #[Test]
    public function test_volume_quarantine_after_3_uncorroborated(): void
    {
        $reporter = User::factory()->create();

        // Create 3 uncorroborated reports in the last 30 days
        for ($i = 0; $i < 3; $i++) {
            $game = Game::factory()->create([
                'date_time' => now()->subDays($i + 1),
                'status' => 'completed',
            ]);
            AttendanceReport::factory()->create([
                'game_id' => $game->id,
                'reporter_id' => $reporter->id,
                'reported_id' => User::factory()->create()->id,
                'is_corroborated' => false,
            ]);
        }

        // Now try to report again — should be quarantined
        $host = User::factory()->create();
        $target = User::factory()->create();
        $newGame = $this->createPastGame($host, [$reporter, $target]);

        $service = app(\App\Services\AttendanceService::class);
        $result = $service->reportAttendance($newGame, $reporter, $target, 'attended');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Quarantined', $result['reason']);
    }

    #[Test]
    public function test_quarantine_lifts_after_30_days(): void
    {
        $reporter = User::factory()->create();

        // Create 3 uncorroborated reports MORE than 30 days ago
        for ($i = 0; $i < 3; $i++) {
            $game = Game::factory()->create([
                'date_time' => now()->subDays(35),
                'status' => 'completed',
            ]);
            AttendanceReport::factory()->create([
                'game_id' => $game->id,
                'reporter_id' => $reporter->id,
                'reported_id' => User::factory()->create()->id,
                'is_corroborated' => false,
                'created_at' => now()->subDays(35),
            ]);
        }

        // Now try to report — old reports should not count
        $host = User::factory()->create();
        $target = User::factory()->create();
        $newGame = $this->createPastGame($host, [$reporter, $target]);

        $service = app(\App\Services\AttendanceService::class);
        $result = $service->reportAttendance($newGame, $reporter, $target, 'attended');

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function test_timeliness_decay_after_72h(): void
    {
        $host = User::factory()->create();
        $reporter = User::factory()->create();
        $reported = User::factory()->create();

        // Game was more than 72h ago
        $game = $this->createPastGame($host, [$reporter, $reported], now()->subHours(80));

        $service = app(\App\Services\AttendanceService::class);
        $result = $service->reportAttendance($game, $reporter, $reported, 'attended');

        $this->assertTrue($result['success']);

        // Weight should be reduced (0.7 for late reporting)
        $report = AttendanceReport::where('reporter_id', $reporter->id)->first();
        $this->assertEquals(0.7, $report->weight_applied);
    }

    #[Test]
    public function test_corroborated_report_full_weight(): void
    {
        $host = User::factory()->create();
        $reporter1 = User::factory()->create();
        $reporter2 = User::factory()->create();
        $reported = User::factory()->create();

        $game = $this->createPastGame($host, [$reporter1, $reporter2, $reported]);

        $service = app(\App\Services\AttendanceService::class);

        // First report
        $service->reportAttendance($game, $reporter1, $reported, 'attended');
        // Second report — should trigger corroboration
        $service->reportAttendance($game, $reporter2, $reported, 'attended');

        // Both reports should now be marked as corroborated
        $corroboratedCount = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $reported->id)
            ->where('is_corroborated', true)
            ->count();

        $this->assertEquals(2, $corroboratedCount);
    }

    private function createPastGame(User $host, array $players, $dateTime = null): Game
    {
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => $dateTime ?? now()->subDay(),
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
