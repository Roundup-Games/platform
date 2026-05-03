<?php

namespace Tests\Feature\Migrations;

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WaitlistMigrationTest extends TestCase
{
    use DatabaseTransactions;

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
