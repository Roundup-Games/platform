<?php

use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Livewire\Campaigns\ManageParticipants as CampaignManageParticipants;
use App\Mail\EntityInvitationEmail;
use App\Models\CampaignParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

beforeEach(function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    $this->owner = $owner;
    $this->campaign = $campaign;
});

// ═══════════════════════════════════════════════════════════
// 1. CAN INVITE BY EMAIL FOR NON-EXISTENT USER
// ═══════════════════════════════════════════════════════════

test('can invite by email for non-existent user on campaign', function () {
    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(CampaignManageParticipants::class, ['id' => $this->campaign->id])
        ->set('inviteEmail', 'newuser@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('campaign_participants', [
        'campaign_id' => $this->campaign->id,
        'user_id' => null,
        'invitee_email' => 'newuser@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);
});

// ═══════════════════════════════════════════════════════════
// 2. INVITE BY EMAIL SENDS INVITATION EMAIL
// ═══════════════════════════════════════════════════════════

test('invite by email sends invitation email for campaign', function () {
    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(CampaignManageParticipants::class, ['id' => $this->campaign->id])
        ->set('inviteEmail', 'external@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    Mail::assertQueued(EntityInvitationEmail::class, function ($mail) {
        return $mail->inviteeEmail === 'external@example.com'
            && $mail->entityType === 'campaign'
            && $mail->inviterName === $this->owner->name;
    });
});

// ═══════════════════════════════════════════════════════════
// 3. INVITE BY EMAIL REJECTS DUPLICATE
// ═══════════════════════════════════════════════════════════

test('invite by email rejects duplicate on campaign', function () {
    Mail::fake();

    // First invite succeeds
    Livewire\Livewire::actingAs($this->owner)
        ->test(CampaignManageParticipants::class, ['id' => $this->campaign->id])
        ->set('inviteEmail', 'duplicate@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    // Second invite to same email should fail
    Livewire\Livewire::actingAs($this->owner)
        ->test(CampaignManageParticipants::class, ['id' => $this->campaign->id])
        ->set('inviteEmail', 'duplicate@example.com')
        ->call('inviteByEmail')
        ->assertHasErrors('inviteEmail');

    $count = CampaignParticipant::where('campaign_id', $this->campaign->id)
        ->where('invitee_email', 'duplicate@example.com')
        ->count();
    expect($count)->toBe(1);
});

// ═══════════════════════════════════════════════════════════
// 4. CANCEL INVITE WORKS FOR EMAIL INVITE
// ═══════════════════════════════════════════════════════════

test('cancel invite works for email invite on campaign', function () {
    Mail::fake();

    // Create email invite
    Livewire\Livewire::actingAs($this->owner)
        ->test(CampaignManageParticipants::class, ['id' => $this->campaign->id])
        ->set('inviteEmail', 'cancel@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    $participant = CampaignParticipant::where('campaign_id', $this->campaign->id)
        ->where('invitee_email', 'cancel@example.com')
        ->first();

    expect($participant)->not->toBeNull();

    // Cancel the invite
    Livewire\Livewire::actingAs($this->owner)
        ->test(CampaignManageParticipants::class, ['id' => $this->campaign->id])
        ->call('cancelInvite', $participant->id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('campaign_participants', [
        'id' => $participant->id,
        'status' => ParticipantStatus::Rejected->value,
    ]);
});
