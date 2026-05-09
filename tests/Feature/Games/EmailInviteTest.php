<?php

use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\ManageParticipants as GameManageParticipants;
use App\Mail\EntityInvitationEmail;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

beforeEach(function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
    $this->owner = $owner;
    $this->game = $game;
});

// ═══════════════════════════════════════════════════════════
// 1. CAN INVITE BY EMAIL FOR NON-EXISTENT USER
// ═══════════════════════════════════════════════════════════

test('can invite by email for non-existent user', function () {
    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', 'newuser@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('game_participants', [
        'game_id' => $this->game->id,
        'user_id' => null,
        'invitee_email' => 'newuser@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);
});

// ═══════════════════════════════════════════════════════════
// 2. INVITE BY EMAIL CREATES PENDING PARTICIPANT
// ═══════════════════════════════════════════════════════════

test('invite by email creates pending participant', function () {
    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', 'pending@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    $participant = GameParticipant::where('game_id', $this->game->id)
        ->where('invitee_email', 'pending@example.com')
        ->first();

    expect($participant)->not->toBeNull();
    expect($participant->role)->toBe('invited');
    expect($participant->status)->toBe(ParticipantStatus::Pending);
    expect($participant->join_source)->toBe(JoinSource::EmailInvite);
    expect($participant->user_id)->toBeNull();
});

// ═══════════════════════════════════════════════════════════
// 3. INVITE BY EMAIL SENDS INVITATION EMAIL
// ═══════════════════════════════════════════════════════════

test('invite by email sends invitation email', function () {
    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', 'external@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    Mail::assertQueued(EntityInvitationEmail::class, function ($mail) {
        return $mail->inviteeEmail === 'external@example.com'
            && $mail->entityType === 'game'
            && $mail->inviterName === $this->owner->name;
    });
});

// ═══════════════════════════════════════════════════════════
// 4. INVITE BY EMAIL FOR EXISTING USER CREATES NORMAL INVITE
// ═══════════════════════════════════════════════════════════

test('invite by email for existing user creates normal invite', function () {
    $existingUser = User::factory()->create([
        'email' => 'existing@example.com',
        'profile_complete' => true,
    ]);

    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', 'existing@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    // Should create a participant with user_id set (not null)
    $this->assertDatabaseHas('game_participants', [
        'game_id' => $this->game->id,
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
// 5. INVITE BY EMAIL REJECTS INVALID EMAIL
// ═══════════════════════════════════════════════════════════

test('invite by email rejects invalid email', function () {
    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', 'not-an-email')
        ->call('inviteByEmail')
        ->assertHasErrors('inviteEmail');

    // No participant should be created
    $this->assertDatabaseMissing('game_participants', [
        'game_id' => $this->game->id,
        'invitee_email' => 'not-an-email',
    ]);

    Mail::assertNothingQueued();
});

// ═══════════════════════════════════════════════════════════
// 6. INVITE BY EMAIL REJECTS SELF-INVITE
// ═══════════════════════════════════════════════════════════

test('invite by email rejects self-invite', function () {
    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', $this->owner->email)
        ->call('inviteByEmail')
        ->assertHasErrors('inviteEmail');

    Mail::assertNothingQueued();
});

// ═══════════════════════════════════════════════════════════
// 7. INVITE BY EMAIL REJECTS DUPLICATE
// ═══════════════════════════════════════════════════════════

test('invite by email rejects duplicate', function () {
    Mail::fake();

    // First invite succeeds
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', 'duplicate@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    // Second invite to same email should fail
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', 'duplicate@example.com')
        ->call('inviteByEmail')
        ->assertHasErrors('inviteEmail');

    // Should still be only one participant for this email
    $count = GameParticipant::where('game_id', $this->game->id)
        ->where('invitee_email', 'duplicate@example.com')
        ->count();
    expect($count)->toBe(1);
});

// ═══════════════════════════════════════════════════════════
// 8. INVITE BY EMAIL REJECTS WHEN AT CAPACITY
// ═══════════════════════════════════════════════════════════

test('invite by email rejects when at capacity', function () {
    Mail::fake();

    // Create a game with max_players=1 (owner is already an approved participant from createGameWithOwner)
    // But actually, createGameWithOwner doesn't create the owner participant — let's create a full game
    ['owner' => $fullOwner, 'game' => $fullGame] = $this->createGameWithOwner(['max_players' => 1]);

    // Add approved participant to fill capacity
    GameParticipant::create([
        'game_id' => $fullGame->id,
        'user_id' => $fullOwner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    Livewire\Livewire::actingAs($fullOwner)
        ->test(GameManageParticipants::class, ['id' => $fullGame->id])
        ->set('inviteEmail', 'full@example.com')
        ->call('inviteByEmail')
        ->assertHasErrors('inviteEmail');

    Mail::assertNothingQueued();
});

// ═══════════════════════════════════════════════════════════
// 9. CANCEL INVITE WORKS FOR EMAIL INVITE
// ═══════════════════════════════════════════════════════════

test('cancel invite works for email invite', function () {
    Mail::fake();

    // Create email invite
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', 'cancel@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    $participant = GameParticipant::where('game_id', $this->game->id)
        ->where('invitee_email', 'cancel@example.com')
        ->first();

    expect($participant)->not->toBeNull();
    expect($participant->status)->toBe(ParticipantStatus::Pending);

    // Cancel the invite
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->call('cancelInvite', $participant->id)
        ->assertHasNoErrors();

    // Status should be rejected
    $this->assertDatabaseHas('game_participants', [
        'id' => $participant->id,
        'status' => ParticipantStatus::Rejected->value,
    ]);
});

// ═══════════════════════════════════════════════════════════
// 10. INVITE BY EMAIL LOGS STRUCTURED CONTEXT
// ═══════════════════════════════════════════════════════════

test('invite by email logs structured context', function () {
    Log::spy();
    Mail::fake();

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', 'logged@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    Log::shouldHaveReceived('info')
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'email invite')
                && isset($context['game_id'])
                && $context['game_id'] === $this->game->id
                && isset($context['invitee_email'])
                && $context['invitee_email'] === 'logged@example.com';
        })
        ->once();
});
