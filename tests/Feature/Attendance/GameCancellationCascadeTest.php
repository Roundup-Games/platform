<?php

namespace Tests\Feature\Attendance;

use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GameCancellationCascadeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_cancel_game_resolves_waitlisted(): void
    {
        $host = User::factory()->create();
        $approved = User::factory()->create();
        $waitlisted = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(3),
            'status' => 'scheduled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $approved->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $waitlisted->id,
            'role' => 'player',
            'status' => ParticipantStatus::Waitlisted->value,
        ]);

        // Cancel the game
        $game->status = 'canceled';
        $game->save();

        // Run the waitlist cancellation handler
        app(\App\Services\WaitlistService::class)->handleGameCancellation($game);

        // Waitlisted participant should be rejected
        $waitlistedParticipant = $game->participants()->where('user_id', $waitlisted->id)->first();
        $this->assertEquals(ParticipantStatus::Rejected, $waitlistedParticipant->status);

        // Approved participant should remain approved
        $approvedParticipant = $game->participants()->where('user_id', $approved->id)->first();
        $this->assertEquals(ParticipantStatus::Approved, $approvedParticipant->status);
    }

    #[Test]
    public function test_cancel_game_resolves_benched(): void
    {
        $host = User::factory()->create();
        $approved = User::factory()->create();
        $benched = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(3),
            'status' => 'scheduled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $approved->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $benched->id,
            'role' => 'player',
            'status' => ParticipantStatus::Benched->value,
        ]);

        // Cancel the game
        $game->status = 'canceled';
        $game->save();

        app(\App\Services\WaitlistService::class)->handleGameCancellation($game);

        // Benched participant should be rejected
        $benchedParticipant = $game->participants()->where('user_id', $benched->id)->first();
        $this->assertEquals(ParticipantStatus::Rejected, $benchedParticipant->status);
    }

    #[Test]
    public function test_host_cancel_under_24h_records_offence(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        // Game in 12h, with participants
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addHours(12),
            'status' => 'scheduled',
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

        // Cancel the game (host action)
        $game->status = 'canceled';
        $game->save();

        // Record host cancellation offence
        app(\App\Services\AttendanceService::class)->recordHostCancellationOffence($game);

        // Host should have late_cancel offence
        $hostParticipant = $game->participants()->where('user_id', $host->id)->first();
        $this->assertEquals(\App\Enums\AttendanceStatus::LateCancel, $hostParticipant->attendance_status);

        $this->assertDatabaseHas('attendance_reports', [
            'game_id' => $game->id,
            'reported_id' => $host->id,
            'status' => 'late_cancel',
        ]);
    }
}
