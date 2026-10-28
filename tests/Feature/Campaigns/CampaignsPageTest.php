<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Campaigns\CampaignsPage;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\User;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;

// ── Helpers ──────────────────────────────────────────────

function campaignsPageCreateUser(array $overrides = []): User
{
    return User::factory()->create(['profile_complete' => true, ...$overrides]);
}

function campaignsPageCreateCampaign(array $overrides = []): Campaign
{
    return Campaign::factory()->create($overrides);
}

// ═══════════════════════════════════════════════════════════
// GUEST REDIRECT
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Guest Access', function () {
    it('permanently redirects guests from /campaigns to /dashboard/campaigns', function () {
        get('/en/campaigns')
            ->assertStatus(301)->assertRedirect('/en/dashboard/campaigns');
    });
});

// ═══════════════════════════════════════════════════════════
// INVITATION ACCEPT / DECLINE (HandlesInvitations)
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Invitation Actions', function () {
    it('accepts a pending invitation', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(CampaignsPage::class)
            ->call('acceptInvitation', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    });

    it('rejects accepting another users invitation', function () {
        $user = campaignsPageCreateUser();
        $other = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $other->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(CampaignsPage::class)
            ->call('acceptInvitation', $participant->id);

        // Status must remain unchanged — only the invited user may accept.
        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
    });

    it('declines a pending invitation and stamps the departure audit trail', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(CampaignsPage::class)
            ->call('declineInvitation', $participant->id);

        // Decline routes through the lifecycle departure write: Rejected plus
        // removed_by/removed_at audit stamps (seatless — no cascade).
        $fresh = $participant->fresh();
        expect($fresh->status)->toBe(ParticipantStatus::Rejected);
        expect($fresh->removed_by)->toBe($user->id);
        expect($fresh->removed_at)->not->toBeNull();
    });
});
