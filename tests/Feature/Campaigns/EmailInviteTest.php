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

// ═══════════════════════════════════════════════════════════
// 5. INVITE BY EMAIL FOR EXISTING USER CREATES NORMAL INVITE
// ═══════════════════════════════════════════════════════════

test('invite by email for existing user creates normal invite on campaign', function () {
    $existingUser = User::factory()->create([
        'email' => 'existing@example.com',
        'profile_complete' => true,
    ]);

    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(CampaignManageParticipants::class, ['id' => $this->campaign->id])
        ->set('inviteEmail', 'existing@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    // Should create participant with user_id set (not null)
    $this->assertDatabaseHas('campaign_participants', [
        'campaign_id' => $this->campaign->id,
        'user_id' => $existingUser->id,
        'invitee_email' => null,
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    // Should NOT send EntityInvitationEmail — sends in-app notification instead
    Mail::assertNotQueued(EntityInvitationEmail::class);
});

// ═══════════════════════════════════════════════════════════
// 6. INVITE BY EMAIL REJECTS INVALID EMAIL
// ═══════════════════════════════════════════════════════════

test('invite by email rejects invalid email on campaign', function () {
    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(CampaignManageParticipants::class, ['id' => $this->campaign->id])
        ->set('inviteEmail', 'not-an-email')
        ->call('inviteByEmail')
        ->assertHasErrors('inviteEmail');

    // No participant should be created
    $this->assertDatabaseMissing('campaign_participants', [
        'campaign_id' => $this->campaign->id,
        'invitee_email' => 'not-an-email',
    ]);

    Mail::assertNothingQueued();
});

// ═══════════════════════════════════════════════════════════
// 7. INVITE BY EMAIL REJECTS SELF-INVITE
// ═══════════════════════════════════════════════════════════

test('invite by email rejects self-invite on campaign', function () {
    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(CampaignManageParticipants::class, ['id' => $this->campaign->id])
        ->set('inviteEmail', $this->owner->email)
        ->call('inviteByEmail')
        ->assertHasErrors('inviteEmail');

    Mail::assertNothingQueued();
});

// ═══════════════════════════════════════════════════════════
// 8. INVITE BY EMAIL ADDS TO BENCH WHEN AT CAPACITY FOR CAMPAIGN
// ═══════════════════════════════════════════════════════════

test('invite by email adds to bench when at capacity on campaign', function () {
    Mail::fake();

    ['owner' => $fullOwner, 'campaign' => $fullCampaign] = $this->createCampaignWithOwner(['max_players' => 1]);

    // Fill the one slot with an approved participant
    CampaignParticipant::create([
        'campaign_id' => $fullCampaign->id,
        'user_id' => $fullOwner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    Livewire\Livewire::actingAs($fullOwner)
        ->test(CampaignManageParticipants::class, ['id' => $fullCampaign->id])
        ->set('inviteEmail', 'full@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    // Should create a benched participant, not an error
    $this->assertDatabaseHas('campaign_participants', [
        'campaign_id' => $fullCampaign->id,
        'user_id' => null,
        'invitee_email' => 'full@example.com',
        'status' => ParticipantStatus::Benched->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    // Should still send the invitation email
    Mail::assertQueued(EntityInvitationEmail::class);
});

// ═══════════════════════════════════════════════════════════
// 9. INVITE BY EMAIL LOGS STRUCTURED CONTEXT
// ═══════════════════════════════════════════════════════════

test('invite by email logs structured context on campaign', function () {
    Log::spy();
    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(CampaignManageParticipants::class, ['id' => $this->campaign->id])
        ->set('inviteEmail', 'logged@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    Log::shouldHaveReceived('info')
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'email invite')
                && isset($context['campaign_id'])
                && $context['campaign_id'] === $this->campaign->id
                && isset($context['invitee_email'])
                && $context['invitee_email'] === 'logged@example.com';
        })
        ->once();
});
