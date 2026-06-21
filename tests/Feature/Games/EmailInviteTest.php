<?php

use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\ManageParticipants as GameManageParticipants;
use App\Mail\EntityInvitationEmail;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\SuppressedInviteEmail;
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
        'role' => ParticipantRole::Invited->value,
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
    expect($participant->role)->toBe(ParticipantRole::Invited);
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
        'role' => ParticipantRole::Invited->value,
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
// 6. INVITE BY EMAIL REJECTS DUPLICATE
// ═══════════════════════════════════════════════════════════
// (Self-invite rejection is covered canonically in ParticipantManagementTest.)

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

test('invite by email adds to waitlist when at capacity for standalone game', function () {
    Mail::fake();

    // Create a standalone game (no campaign_id) with max_players=1
    // Owner participant is already created by createGameWithOwner, filling the game
    ['owner' => $fullOwner, 'game' => $fullGame] = $this->createGameWithOwner(['max_players' => 1]);

    Livewire\Livewire::actingAs($fullOwner)
        ->test(GameManageParticipants::class, ['id' => $fullGame->id])
        ->set('inviteEmail', 'full@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    // Should create a waitlisted participant, not an error
    $this->assertDatabaseHas('game_participants', [
        'game_id' => $fullGame->id,
        'user_id' => null,
        'invitee_email' => 'full@example.com',
        'status' => ParticipantStatus::Waitlisted->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    // Should still send the invitation email
    Mail::assertQueued(EntityInvitationEmail::class);
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

    // Cancel the invite — record is deleted (so user can be re-invited)
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->call('cancelInvite', $participant->id)
        ->assertHasNoErrors();

    // Record should be deleted
    $this->assertDatabaseMissing('game_participants', [
        'id' => $participant->id,
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
                && isset($context['invitee_email_hash'])
                && $context['invitee_email_hash'] === SuppressedInviteEmail::hashEmail('logged@example.com');
        })
        ->once();
});

// ═══════════════════════════════════════════════════════════
// 11. INVITE BY EMAIL RATE LIMITS AFTER THRESHOLD
// ═══════════════════════════════════════════════════════════

test('invite by email rate limits after threshold', function () {
    Mail::fake();

    // Send 10 invites to distinct emails — all should succeed
    for ($i = 0; $i < 10; $i++) {
        Livewire\Livewire::actingAs($this->owner)
            ->test(GameManageParticipants::class, ['id' => $this->game->id])
            ->set('inviteEmail', "user{$i}@example.com")
            ->call('inviteByEmail')
            ->assertHasNoErrors();
    }

    // 11th invite should be rejected by the rate limiter
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', 'onemore@example.com')
        ->call('inviteByEmail')
        ->assertHasErrors('inviteEmail');

    // No participant should be created for the rate-limited attempt
    $this->assertDatabaseMissing('game_participants', [
        'game_id' => $this->game->id,
        'invitee_email' => 'onemore@example.com',
    ]);
});

// ═══════════════════════════════════════════════════════════
// 12. ACCEPT INVITATION REJECTS WHEN GAME IS CANCELLED
// ═══════════════════════════════════════════════════════════

test('accept invitation rejects when game is cancelled', function () {
    Mail::fake();

    // Use a fresh game to avoid transaction state pollution from prior tests
    ['owner' => $freshOwner, 'game' => $freshGame] = $this->createGameWithOwner();

    // Send an email invite to a future invitee
    Livewire\Livewire::actingAs($freshOwner)
        ->test(GameManageParticipants::class, ['id' => $freshGame->id])
        ->set('inviteEmail', 'invited@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    // Cancel the game — use saveQuietly to bypass GameObserver which triggers
    // a pre-existing activity_log UUID bug when user_id is empty string
    $freshGame->status = GameStatus::Canceled;
    $freshGame->saveQuietly();
    expect($freshGame->fresh()->status->value)->toBe('canceled');

    // Simulate the invitee creating an account and getting matched to the participant
    $invitee = User::factory()->create([
        'email' => 'invited@example.com',
        'profile_complete' => true,
    ]);

    $participant = GameParticipant::where('game_id', $freshGame->id)
        ->where('invitee_email', 'invited@example.com')
        ->first();
    $participant->update(['user_id' => $invitee->id]);

    // Try to accept — guard should block this because the game is cancelled
    Livewire\Livewire::actingAs($invitee)
        ->test(GameManageParticipants::class, ['id' => $freshGame->id])
        ->call('acceptInvitation', $participant->id);

    // Guard uses session flash, so no Livewire errors — check status directly
    // Status must remain pending — approval must not happen on a cancelled game
    expect($participant->fresh()->status->value)->not->toBe('approved');
});

// ═══════════════════════════════════════════════════════════
// 13. ACCEPT INVITATION ADDS TO WAITLIST WHEN STANDALONE GAME IS FULL
// ═══════════════════════════════════════════════════════════

test('accept invitation adds to waitlist when standalone game is full', function () {
    Mail::fake();

    // Create a standalone game with max_players=1 (owner fills the slot)
    ['owner' => $fullOwner, 'game' => $fullGame] = $this->createGameWithOwner(['max_players' => 1]);

    // Invite someone
    Livewire\Livewire::actingAs($fullOwner)
        ->test(GameManageParticipants::class, ['id' => $fullGame->id])
        ->set('inviteEmail', 'invited@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    // The invitee exists and matches
    $invitee = User::factory()->create([
        'email' => 'invited@example.com',
        'profile_complete' => true,
    ]);

    $participant = GameParticipant::where('game_id', $fullGame->id)
        ->where('invitee_email', 'invited@example.com')
        ->first();
    $participant->update(['user_id' => $invitee->id]);

    // Accept — game is full so should go to waitlist, not approved
    Livewire\Livewire::actingAs($invitee)
        ->test(GameManageParticipants::class, ['id' => $fullGame->id])
        ->call('acceptInvitation', $participant->id);

    expect($participant->fresh()->status)->toBe(ParticipantStatus::Waitlisted);
    expect($participant->fresh()->role)->toBe(ParticipantRole::Invited);
});
