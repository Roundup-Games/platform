<?php

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\GameSystem;
use App\Models\User;
use function Pest\Laravel\{actingAs, assertDatabaseHas, get};

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
    it('redirects guests to /discover', function () {
        get('/en/campaigns')
            ->assertRedirect('/en/discover');
    });
});

// ═══════════════════════════════════════════════════════════
// AUTHENTICATED ACCESS
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Authenticated Access', function () {
    it('renders for authenticated users', function () {
        $user = campaignsPageCreateUser();

        actingAs($user)
            ->get('/en/campaigns')
            ->assertOk()
            ->assertSee(__('campaigns.heading_my_campaigns'));
    });

    it('shows My Campaigns section heading', function () {
        $user = campaignsPageCreateUser();

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.heading_my_campaigns'));
    });

    it('shows create campaign button', function () {
        $user = campaignsPageCreateUser();

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.action_create_campaign'));
    });
});

// ═══════════════════════════════════════════════════════════
// MY CAMPAIGNS — OWNED CAMPAIGNS DISPLAY
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — My Campaigns Display', function () {
    it('shows owned campaigns with name', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'name' => 'Test Campaign']);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee('Test Campaign');
    });

    it('shows status badge for active campaigns', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'active']);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.status_active'));
    });

    it('shows status badge for cancelled campaigns', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'cancelled']);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.status_cancelled'));
    });

    it('shows status badge for completed campaigns', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'completed']);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.status_completed'));
    });

    it('does not show other users campaigns in My Campaigns', function () {
        $user = campaignsPageCreateUser();
        $other = campaignsPageCreateUser();
        // Make the campaign private so it won't appear in Community section either
        $campaign = campaignsPageCreateCampaign(['owner_id' => $other->id, 'name' => 'Other User Campaign', 'visibility' => 'private']);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertDontSee('Other User Campaign');
    });

    it('shows empty state when no campaigns exist', function () {
        $user = campaignsPageCreateUser();

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.content_no_owned_campaigns'));
    });

    it('shows cancel button for active campaigns', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'active']);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.action_cancel_campaign'));
    });

    it('shows complete button for active campaigns', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'active']);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.action_complete_campaign'));
    });

    it('does not show cancel/complete buttons for cancelled campaigns', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'cancelled']);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertDontSee(__('campaigns.action_cancel_campaign'))
            ->assertDontSee(__('campaigns.action_complete_campaign'));
    });

    it('does not show cancel/complete buttons for completed campaigns', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'completed']);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertDontSee(__('campaigns.action_cancel_campaign'))
            ->assertDontSee(__('campaigns.action_complete_campaign'));
    });
});

// ═══════════════════════════════════════════════════════════
// CANCEL CAMPAIGN ACTION
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Cancel Campaign Action', function () {
    it('cancels an active campaign', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'active']);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('cancelCampaign', $campaign->id);

        assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'cancelled',
        ]);
    });

    it('flashes success message after cancel', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'active']);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('cancelCampaign', $campaign->id);

        assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'cancelled',
        ]);

        $component->assertSee(__('campaigns.flash_campaign_canceled'));
    });

    it('cannot cancel already cancelled campaign', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'cancelled']);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('cancelCampaign', $campaign->id);

        assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'cancelled',
        ]);

        $component->assertSee(__('campaigns.error_campaign_not_active'));
    });

    it('cannot cancel a completed campaign', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'completed']);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('cancelCampaign', $campaign->id);

        assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'completed',
        ]);

        $component->assertSee(__('campaigns.error_campaign_not_active'));
    });

    it('denies cancel by non-owner', function () {
        $owner = campaignsPageCreateUser();
        $other = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id, 'status' => 'active']);

        Livewire\Livewire::actingAs($other)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('cancelCampaign', $campaign->id)
            ->assertStatus(403);

        assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'active',
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// COMPLETE CAMPAIGN ACTION
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Complete Campaign Action', function () {
    it('completes an active campaign', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'active']);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('completeCampaign', $campaign->id);

        assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'completed',
        ]);

        $component->assertSee(__('campaigns.flash_campaign_completed'));
    });

    it('cannot complete already cancelled campaign', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'cancelled']);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('completeCampaign', $campaign->id);

        assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'cancelled',
        ]);

        $component->assertSee(__('campaigns.error_campaign_not_active'));
    });

    it('cannot complete already completed campaign', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'status' => 'completed']);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('completeCampaign', $campaign->id);

        assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'completed',
        ]);

        $component->assertSee(__('campaigns.error_campaign_not_active'));
    });

    it('denies complete by non-owner', function () {
        $owner = campaignsPageCreateUser();
        $other = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id, 'status' => 'active']);

        Livewire\Livewire::actingAs($other)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('completeCampaign', $campaign->id)
            ->assertStatus(403);

        assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'active',
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGNS I'M IN — DISPLAY
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Campaigns I\'m In Display', function () {
    it('shows section heading', function () {
        $user = campaignsPageCreateUser();

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.heading_campaigns_im_in'));
    });

    it('shows empty state when not participating in any campaigns', function () {
        $user = campaignsPageCreateUser();

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.content_no_campaigns_joined'));
    });

    it('shows campaigns where user is an approved player', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id, 'name' => 'Joined Campaign']);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee('Joined Campaign');
    });

    it('does not show owned campaigns in Campaigns I\'m In', function () {
        $user = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $user->id, 'name' => 'My Own Campaign']);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee('My Own Campaign'); // visible in My Campaigns
        // But the participating section should show empty state
        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.content_no_campaigns_joined'));
    });

    it('does not show campaigns with pending participation in Campaigns I\'m In section', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id, 'name' => 'Pending Campaign', 'visibility' => 'private']);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'pending',
        ]);

        $response = actingAs($user)->get('/en/campaigns');
        $content = $response->getContent();

        // Find the Campaigns I'm In section and verify the campaign is not there
        $heading = __('campaigns.heading_campaigns_im_in');
        $escapedHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pos = strpos($content, $heading) ?: strpos($content, $escapedHeading);
        expect($pos)->not->toBeFalse('Campaigns I\'m In section heading should be present');

        $sectionContent = substr($content, $pos);
        $nextSection = strpos($sectionContent, '<section>');
        if ($nextSection !== false) {
            $sectionContent = substr($sectionContent, 0, $nextSection);
        }
        expect($sectionContent)->not->toContain('Pending Campaign');
    });

    it('does not show invited campaigns in Campaigns I\'m In section', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id, 'name' => 'Invited Only Campaign']);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        // Invited campaigns appear in Open Invitations, not in Campaigns I'm In
        $response = actingAs($user)->get('/en/campaigns');
        $content = $response->getContent();

        // Find the Campaigns I'm In section and ensure the campaign is NOT there
        $sectionMarker = "Campaigns I&#039;m In";
        $pos = strpos($content, $sectionMarker);
        if ($pos === false) {
            $sectionMarker = "Campaigns I'm In";
            $pos = strpos($content, $sectionMarker);
        }
        expect($pos)->not->toBeFalse('Campaigns I\'m In section should be present');

        $sectionContent = substr($content, $pos);
        $nextSection = strpos($sectionContent, '<section>');
        if ($nextSection !== false) {
            $sectionContent = substr($sectionContent, 0, $nextSection);
        }

        expect($sectionContent)->not->toContain('Invited Only Campaign');
    });

    it('shows view link for participating campaigns', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id, 'name' => 'Viewable Campaign']);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.action_view_campaign'));
    });
});

// ═══════════════════════════════════════════════════════════
// OPEN INVITATIONS — DISPLAY
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Open Invitations Display', function () {
    it('hides section when no pending invitations', function () {
        $user = campaignsPageCreateUser();

        actingAs($user)
            ->get('/en/campaigns')
            ->assertDontSee(__('campaigns.heading_open_invitations'));
    });

    it('shows section heading when invitations exist', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id, 'name' => 'Invite Campaign']);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.heading_open_invitations'));
    });

    it('shows campaign name for pending invitations', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id, 'name' => 'Invite Me Campaign']);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee('Invite Me Campaign');
    });

    it('shows accept and decline buttons for invitations', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.action_accept_invitation'))
            ->assertSee(__('campaigns.action_decline_invitation'));
    });
});

// ═══════════════════════════════════════════════════════════
// ACCEPT INVITATION ACTION
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Accept Invitation Action', function () {
    it('accepts a pending invitation', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id, 'max_players' => 4]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('acceptInvitation', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $component->assertSee(__('campaigns.flash_invitation_accepted'));
    });

    it('rejects accepting another users invitation', function () {
        $user = campaignsPageCreateUser();
        $other = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $other->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('acceptInvitation', $participant->id);

        // Status should remain unchanged
        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        $component->assertSee(__('campaigns.error_not_your_invitation'));
    });

    it('rejects accepting a non-invited participant', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('acceptInvitation', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    });

    it('rejects accepting when campaign is full', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id, 'max_players' => 1]);

        // Fill the campaign with an approved player
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => campaignsPageCreateUser()->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('acceptInvitation', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        $component->assertSee(__('campaigns.error_campaign_full'));
    });

    it('allows accepting when campaign has no max_players limit', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id, 'max_players' => null]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('acceptInvitation', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    });

    it('allows accepting when campaign still has capacity', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id, 'max_players' => 3]);

        // Add 2 approved players (campaign has room for 1 more)
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => campaignsPageCreateUser()->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => campaignsPageCreateUser()->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('acceptInvitation', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// COMMUNITY SECTION — DISPLAY
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Community Display', function () {
    it('shows Community section heading', function () {
        $user = campaignsPageCreateUser();

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.heading_community'));
    });

    it('shows empty state when no community campaigns exist', function () {
        $user = campaignsPageCreateUser();

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee(__('campaigns.content_no_community_campaigns'));
    });

    it('shows public active campaigns in community', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'active',
            'name' => 'Public Campaign Alpha',
        ]);

        actingAs($user)
            ->get('/en/campaigns')
            ->assertSee('Public Campaign Alpha');
    });

    it('hides private campaigns from community', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign([
            'owner_id' => $owner->id,
            'visibility' => 'private',
            'status' => 'active',
            'name' => 'Secret Private Campaign',
        ]);

        $response = actingAs($user)->get('/en/campaigns');
        $content = $response->getContent();

        // Find the Community section and ensure private campaign is NOT there
        $heading = __('campaigns.heading_community');
        $escapedHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pos = strpos($content, $heading) ?: strpos($content, $escapedHeading);
        expect($pos)->not->toBeFalse('Community section heading should be present');

        $sectionContent = substr($content, $pos);
        expect($sectionContent)->not->toContain('Secret Private Campaign');
    });

    it('excludes cancelled campaigns from community', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'cancelled',
            'name' => 'Cancelled Community Campaign',
        ]);

        $response = actingAs($user)->get('/en/campaigns');
        $content = $response->getContent();

        $heading = __('campaigns.heading_community');
        $escapedHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pos = strpos($content, $heading) ?: strpos($content, $escapedHeading);
        expect($pos)->not->toBeFalse('Community section heading should be present');

        $sectionContent = substr($content, $pos);
        expect($sectionContent)->not->toContain('Cancelled Community Campaign');
    });

    it('excludes completed campaigns from community', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'completed',
            'name' => 'Completed Community Campaign',
        ]);

        $response = actingAs($user)->get('/en/campaigns');
        $content = $response->getContent();

        $heading = __('campaigns.heading_community');
        $escapedHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pos = strpos($content, $heading) ?: strpos($content, $escapedHeading);
        expect($pos)->not->toBeFalse('Community section heading should be present');

        $sectionContent = substr($content, $pos);
        expect($sectionContent)->not->toContain('Completed Community Campaign');
    });
});

// ═══════════════════════════════════════════════════════════
// COMMUNITY SECTION — SEARCH FILTER
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Community Search Filter', function () {
    it('filters campaigns by search term', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $match = campaignsPageCreateCampaign([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'active',
            'name' => 'Dragonlance Chronicles',
        ]);
        $noMatch = campaignsPageCreateCampaign([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'active',
            'name' => 'Forgotten Realms',
        ]);

        $response = actingAs($user)
            ->get('/en/campaigns?q=Dragonlance');

        $content = $response->getContent();
        $heading = __('campaigns.heading_community');
        $escapedHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pos = strpos($content, $heading) ?: strpos($content, $escapedHeading);
        $sectionContent = substr($content, $pos);

        expect($sectionContent)->toContain('Dragonlance Chronicles');
        expect($sectionContent)->not->toContain('Forgotten Realms');
    });
});

// ═══════════════════════════════════════════════════════════
// COMMUNITY SECTION — GAME SYSTEM FILTER
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Community Game System Filter', function () {
    it('filters campaigns by game system', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $systemA = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $systemB = GameSystem::factory()->create(['name' => 'Pathfinder 2e']);
        $match = campaignsPageCreateCampaign([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'active',
            'name' => 'DND Campaign',
            'game_system_id' => $systemA->id,
        ]);
        $noMatch = campaignsPageCreateCampaign([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'active',
            'name' => 'Pathfinder Campaign',
            'game_system_id' => $systemB->id,
        ]);

        $response = actingAs($user)
            ->get('/en/campaigns?game_system_id=' . $systemA->id);

        $content = $response->getContent();
        $heading = __('campaigns.heading_community');
        $escapedHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pos = strpos($content, $heading) ?: strpos($content, $escapedHeading);
        $sectionContent = substr($content, $pos);

        expect($sectionContent)->toContain('DND Campaign');
        expect($sectionContent)->not->toContain('Pathfinder Campaign');
    });
});

// ═══════════════════════════════════════════════════════════
// COMMUNITY SECTION — PAGINATION
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Community Pagination', function () {
    it('paginates community campaigns at 12 per page', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();

        // Create 14 active public campaigns
        for ($i = 0; $i < 14; $i++) {
            campaignsPageCreateCampaign([
                'owner_id' => $owner->id,
                'visibility' => 'public',
                'status' => 'active',
                'name' => "Campaign Page Item {$i}",
            ]);
        }

        // Page 1 should show 12 items (campaigns 13 and 14 on page 2)
        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class);

        $campaigns = $component->viewData('communityCampaigns');
        expect($campaigns->count())->toBe(12);
        expect($campaigns->hasMorePages())->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// COMMUNITY SECTION — CLEAR FILTERS
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Community Clear Filters', function () {
    it('clears all filters', function () {
        $user = campaignsPageCreateUser();

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->set('search', 'test')
            ->set('game_system_id', 1)
            ->set('recurrence', 'weekly')
            ->assertSet('search', 'test')
            ->assertSet('game_system_id', 1)
            ->assertSet('recurrence', 'weekly')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('game_system_id', null)
            ->assertSet('recurrence', '');
    });
});

// ═══════════════════════════════════════════════════════════
// COMMUNITY SECTION — VIBE FLAGS TOGGLE
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Community Vibe Flags Toggle', function () {
    it('toggles vibe flags on and off', function () {
        $user = campaignsPageCreateUser();

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->assertSet('vibe_flags', [])
            ->call('toggleVibeFlag', 'rules_light')
            ->assertSet('vibe_flags', ['rules_light'])
            ->call('toggleVibeFlag', 'roleplay_heavy')
            ->assertSet('vibe_flags', ['rules_light', 'roleplay_heavy'])
            ->call('toggleVibeFlag', 'rules_light')
            ->assertSet('vibe_flags', ['roleplay_heavy']);
    });
});

// ═══════════════════════════════════════════════════════════
// COMMUNITY SECTION — HAS ACTIVE FILTERS
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Community hasActiveFilters', function () {
    it('returns false when no filters are active', function () {
        $user = campaignsPageCreateUser();

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class);

        expect($component->instance()->hasActiveFilters())->toBeFalse();
    });

    it('returns true when search filter is active', function () {
        $user = campaignsPageCreateUser();

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->set('search', 'test');

        expect($component->instance()->hasActiveFilters())->toBeTrue();
    });

    it('returns true when game_system_id filter is active', function () {
        $user = campaignsPageCreateUser();

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->set('game_system_id', 1);

        expect($component->instance()->hasActiveFilters())->toBeTrue();
    });

    it('returns true when vibe_flags filter is active', function () {
        $user = campaignsPageCreateUser();

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('toggleVibeFlag', 'rules_light');

        expect($component->instance()->hasActiveFilters())->toBeTrue();
    });

    it('returns true when recurrence filter is active', function () {
        $user = campaignsPageCreateUser();

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->set('recurrence', 'weekly');

        expect($component->instance()->hasActiveFilters())->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// DECLINE INVITATION ACTION
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Decline Invitation Action', function () {
    it('declines a pending invitation', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('declineInvitation', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'rejected',
        ]);

        $component->assertSee(__('campaigns.flash_invitation_declined'));
    });

    it('rejects declining another users invitation', function () {
        $user = campaignsPageCreateUser();
        $other = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $other->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('declineInvitation', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    });

    it('rejects declining a non-pending invitation', function () {
        $user = campaignsPageCreateUser();
        $owner = campaignsPageCreateUser();
        $campaign = campaignsPageCreateCampaign(['owner_id' => $owner->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'rejected',
        ]);

        // Calling decline again — status should not change (still rejected)
        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('declineInvitation', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'rejected',
        ]);
    });
});
