<?php

namespace Tests\Feature\Services;

use App\Enums\CampaignStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\User;
use App\Services\MyCampaignsBoardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MyCampaignsBoardServiceTest extends TestCase
{
    use DatabaseTransactions;

    private MyCampaignsBoardService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MyCampaignsBoardService;
        Cache::flush();
        Queue::fake();
        URL::defaults(['locale' => 'en']);

        $this->user = User::factory()->create();
    }

    public function test_empty_user_has_no_campaigns(): void
    {
        $board = $this->service->build($this->user);

        $this->assertFalse($board['has_any_campaigns']);
        $this->assertCount(0, $board['active_hosting']);
        $this->assertCount(0, $board['active_playing']);
        $this->assertCount(0, $board['ended']);
    }

    public function test_active_owned_campaign_lands_in_active_hosting(): void
    {
        $active = Campaign::factory()->create([
            'owner_id' => $this->user->id,
            'status' => CampaignStatus::Active->value,
        ]);

        $board = $this->service->build($this->user);

        $this->assertTrue($board['has_any_campaigns']);
        $this->assertTrue($board['active_hosting']->contains('id', $active->id));
        $this->assertFalse($board['ended']->contains('id', $active->id));
    }

    public function test_completed_and_cancelled_campaigns_lands_in_ended(): void
    {
        $completed = Campaign::factory()->create([
            'owner_id' => $this->user->id,
            'status' => CampaignStatus::Completed->value,
        ]);
        $cancelled = Campaign::factory()->create([
            'owner_id' => $this->user->id,
            'status' => CampaignStatus::Cancelled->value,
        ]);

        $board = $this->service->build($this->user);

        $this->assertTrue($board['ended']->contains('id', $completed->id));
        $this->assertTrue($board['ended']->contains('id', $cancelled->id));
        $this->assertCount(0, $board['active_hosting']);
    }

    public function test_active_participating_campaign_lands_in_active_playing(): void
    {
        $host = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $host->id,
            'status' => CampaignStatus::Active->value,
        ]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $board = $this->service->build($this->user);

        $this->assertTrue($board['active_playing']->contains('id', $campaign->id));
        $this->assertFalse($board['active_hosting']->contains('id', $campaign->id));
    }

    public function test_pending_invitations_collected(): void
    {
        $host = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $host->id,
            'status' => CampaignStatus::Active->value,
        ]);
        $invitation = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->user->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        $board = $this->service->build($this->user);

        $this->assertTrue($board['pending_invitations']->contains('id', $invitation->id));
    }

    public function test_has_any_campaigns_true_with_only_pending_invitation(): void
    {
        $host = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $host->id,
            'status' => CampaignStatus::Active->value,
        ]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->user->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        $board = $this->service->build($this->user);

        // A user with only an invitation should NOT see the empty state.
        $this->assertTrue($board['has_any_campaigns']);
    }
}
