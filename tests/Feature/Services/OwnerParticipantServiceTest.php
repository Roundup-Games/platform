<?php

namespace Tests\Feature\Services;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\OwnerParticipantService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OwnerParticipantServiceTest extends TestCase
{
    use DatabaseTransactions;

    private OwnerParticipantService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OwnerParticipantService;
    }

    // ── Game Owner Participant ─────────────────────────

    public function test_ensure_owner_participant_creates_participant(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $participant = $this->service->ensureOwnerParticipant($game);

        $this->assertInstanceOf(GameParticipant::class, $participant);
        $this->assertEquals($game->id, $participant->game_id);
        $this->assertEquals($owner->id, $participant->user_id);
        $this->assertEquals(ParticipantRole::Owner, $participant->role);
        $this->assertEquals(ParticipantStatus::Approved, $participant->status);
    }

    public function test_ensure_owner_participant_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $first = $this->service->ensureOwnerParticipant($game);
        $second = $this->service->ensureOwnerParticipant($game);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, GameParticipant::where('game_id', $game->id)
            ->where('role', ParticipantRole::Owner->value)
            ->count());
    }

    public function test_ensure_owner_participant_returns_existing_without_creating_duplicate(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        // Pre-create the owner participant
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $result = $this->service->ensureOwnerParticipant($game);

        $this->assertEquals(1, GameParticipant::where('game_id', $game->id)
            ->where('role', ParticipantRole::Owner->value)
            ->count());
        $this->assertEquals(ParticipantRole::Owner, $result->role);
    }

    // ── Campaign Owner Participant ─────────────────────

    public function test_ensure_campaign_owner_participant_creates_participant(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        $participant = $this->service->ensureCampaignOwnerParticipant($campaign);

        $this->assertInstanceOf(CampaignParticipant::class, $participant);
        $this->assertEquals($campaign->id, $participant->campaign_id);
        $this->assertEquals($owner->id, $participant->user_id);
        $this->assertEquals(ParticipantRole::Owner, $participant->role);
        $this->assertEquals(ParticipantStatus::Approved, $participant->status);
    }

    public function test_ensure_campaign_owner_participant_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        $first = $this->service->ensureCampaignOwnerParticipant($campaign);
        $second = $this->service->ensureCampaignOwnerParticipant($campaign);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('role', ParticipantRole::Owner->value)
            ->count());
    }

    public function test_ensure_campaign_owner_participant_returns_existing_without_creating_duplicate(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        // Pre-create the owner participant
        CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $result = $this->service->ensureCampaignOwnerParticipant($campaign);

        $this->assertEquals(1, CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('role', ParticipantRole::Owner->value)
            ->count());
        $this->assertEquals(ParticipantRole::Owner, $result->role);
    }
}
