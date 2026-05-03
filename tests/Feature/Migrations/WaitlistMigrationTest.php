<?php

namespace Tests\Feature\Migrations;

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WaitlistMigrationTest extends TestCase
{
    use DatabaseTransactions;

    // ── Parameterized: all ParticipantStatus values accepted on game_participants ──

    #[Test]
    public function all_participant_status_values_accepted_on_game_participants(): void
    {
        $user = User::factory()->create();

        foreach (ParticipantStatus::cases() as $status) {
            $participant = GameParticipant::factory()->create([
                'user_id' => $user->id,
                'status' => $status,
            ]);
            $this->assertEquals($status, $participant->refresh()->status);
        }

        $this->assertEquals(5, GameParticipant::count());
    }

    // ── Parameterized: all AttendanceStatus values accepted on game_participants ──

    #[Test]
    public function game_participants_attendance_status_accepts_all_enum_values(): void
    {
        $user = User::factory()->create();

        foreach (AttendanceStatus::cases() as $status) {
            $participant = GameParticipant::factory()->create([
                'user_id' => $user->id,
                'attendance_status' => $status,
            ]);
            $this->assertEquals($status, $participant->refresh()->attendance_status);
        }
    }

    // ── Representative: campaign_participants accepts new statuses ──

    #[Test]
    public function campaign_participants_accepts_waitlisted_status(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $user->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Waitlisted,
        ]);

        $this->assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => 'waitlisted',
        ]);
    }

    #[Test]
    public function campaign_participants_accepts_benched_status(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $user->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Benched,
        ]);

        $this->assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => 'benched',
        ]);
    }
}
