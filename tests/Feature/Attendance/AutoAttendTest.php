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

class AutoAttendTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_auto_attend_after_48h(): void
    {
        $host = User::factory()->create();
        $player1 = User::factory()->create();
        $player2 = User::factory()->create();

        // Game completed more than 48h ago
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->subHours(50),
            'status' => 'completed',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player1->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player2->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $service = app(\App\Services\AttendanceService::class);
        $count = $service->autoAttendAfter48Hours();

        $this->assertEquals(3, $count);

        // All participants should be auto-attended
        foreach ($game->participants as $participant) {
            $this->assertEquals(AttendanceStatus::Attended, $participant->fresh()->attendance_status);
        }

        // System-generated reports should exist
        $reports = AttendanceReport::where('game_id', $game->id)->get();
        $this->assertEquals(3, $reports->count());
        foreach ($reports as $report) {
            $this->assertTrue($report->is_corroborated); // System reports auto-corroborated
        }
    }

    #[Test]
    public function test_no_auto_attend_if_reports_exist(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        // Game completed more than 48h ago
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->subHours(50),
            'status' => 'completed',
        ]);

        // Host already has attendance reported
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
            'attendance_status' => AttendanceStatus::Attended->value,
        ]);
        // Player has no report yet
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $service = app(\App\Services\AttendanceService::class);
        $count = $service->autoAttendAfter48Hours();

        // Only the player without a report should be auto-attended
        $this->assertEquals(1, $count);

        $hostParticipant = $game->participants()->where('user_id', $host->id)->first();
        $playerParticipant = $game->participants()->where('user_id', $player->id)->first();

        // Host already had attendance, player auto-attended
        $this->assertEquals(AttendanceStatus::Attended, $hostParticipant->attendance_status);
        $this->assertEquals(AttendanceStatus::Attended, $playerParticipant->fresh()->attendance_status);
    }
}
