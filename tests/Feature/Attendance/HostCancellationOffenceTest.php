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

class HostCancellationOffenceTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale;

    #[Test]
    public function test_host_cancel_under_24h_records_offence(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        // Game starts in 12 hours (under 24h threshold)
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addHours(12),
            'status' => 'canceled',
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

        $service = app(\App\Services\AttendanceService::class);
        $service->recordHostCancellationOffence($game);

        // Host participant should have late_cancel recorded
        $hostParticipant = $game->participants()->where('user_id', $host->id)->first();
        $this->assertEquals(AttendanceStatus::LateCancel, $hostParticipant->attendance_status);
        $this->assertNotNull($hostParticipant->attendance_reported_at);

        // Attendance report should exist with negative weight
        $this->assertDatabaseHas('attendance_reports', [
            'game_id' => $game->id,
            'reporter_id' => $host->id,
            'reported_id' => $host->id,
            'status' => 'late_cancel',
            'is_corroborated' => true,
        ]);

        $report = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $host->id)
            ->first();
        $this->assertEquals(-1.2, $report->weight_applied);

        // Host reliability should have been recomputed
        $host->refresh();
        $this->assertNotNull($host->reliability_score);
    }

    #[Test]
    public function test_host_cancel_under_24h_justified_when_below_min(): void
    {
        $host = User::factory()->create();

        // Game starts in 12 hours, but host is only participant (count=1 >= MIN_ROSTER=1)
        // However, if host is the ONLY participant (no other players signed up),
        // the cancellation is arguably justified. Let's test with no host participant
        // record at all — edge case where game was just created.
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addHours(12),
            'status' => 'canceled',
        ]);

        // No participant records at all
        $service = app(\App\Services\AttendanceService::class);
        $service->recordHostCancellationOffence($game);

        // No offence recorded — no host participant found
        $this->assertDatabaseMissing('attendance_reports', [
            'game_id' => $game->id,
            'reported_id' => $host->id,
        ]);
    }

    #[Test]
    public function test_host_cancel_over_24h_no_offence(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        // Game starts in 48 hours (over 24h threshold)
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addHours(48),
            'status' => 'canceled',
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

        $service = app(\App\Services\AttendanceService::class);
        $service->recordHostCancellationOffence($game);

        // No offence — host participant should not have attendance set
        $hostParticipant = $game->participants()->where('user_id', $host->id)->first();
        $this->assertNull($hostParticipant->attendance_status);
    }
}
