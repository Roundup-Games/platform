<?php

use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\ManageParticipants as GameManageParticipants;
use App\Mail\EntityInvitationEmail;
use App\Models\GameParticipant;
use App\Models\SuppressedInviteEmail;
use Illuminate\Support\Facades\Mail;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

beforeEach(function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
    $this->owner = $owner;
    $this->game = $game;
});

// ═══════════════════════════════════════════════════════════
// SUPPRESSED EMAIL — BEHAVIOR VERIFICATION
//
// These tests verify the expected GDPR-compliant behavior:
// (a) suppressed emails store suppressed- prefix (not real email)
// (b) duplicate detection still works via deterministic hash
// (c) no email is dispatched
//
// FIX REQUIRED: When ManagesParticipants.php line 300 is updated
// to use 'suppressed-' prefix, these tests will pass.
// ═══════════════════════════════════════════════════════════

test('suppressed email creates participant with suppressed prefix instead of real email', function () {
    Mail::fake();

    $suppressedEmail = 'optedout@example.com';
    SuppressedInviteEmail::suppress($suppressedEmail);

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', $suppressedEmail)
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    $participant = GameParticipant::where('game_id', $this->game->id)
        ->where('role', 'invited')
        ->first();

    expect($participant)->not->toBeNull();

    // The participant's invitee_email should NOT be the real plaintext email.
    // It should use the deterministic suppressed- prefix for data minimization (GDPR Art. 5(1)(c)).
    expect($participant->invitee_email)->not->toBe($suppressedEmail)
        ->and($participant->invitee_email)->toStartWith('suppressed-');

    // Verify deterministic: same email always produces the same hash
    $expectedHash = 'suppressed-'.SuppressedInviteEmail::hashEmail($suppressedEmail);
    expect($participant->invitee_email)->toBe($expectedHash);
});

test('suppressed email does not send invitation email', function () {
    Mail::fake();

    $suppressedEmail = 'noemail@example.com';
    SuppressedInviteEmail::suppress($suppressedEmail);

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', $suppressedEmail)
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    Mail::assertNotQueued(EntityInvitationEmail::class);
});

test('suppressed email duplicate detection works via suppressed hash', function () {
    Mail::fake();

    $suppressedEmail = 'duplicate@example.com';
    SuppressedInviteEmail::suppress($suppressedEmail);

    // First invite — should succeed
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', $suppressedEmail)
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    // Second invite to the same suppressed email — should be rejected as duplicate.
    // Use a fresh component instance to avoid PostgreSQL's aborted-transaction state
    // from the QueryException caught in the first attempt's duplicate-detection path.
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', $suppressedEmail)
        ->call('inviteByEmail')
        ->assertHasErrors('inviteEmail');

    // Only one participant should exist for this email
    $suppressedHash = 'suppressed-'.SuppressedInviteEmail::hashEmail($suppressedEmail);
    $count = GameParticipant::where('game_id', $this->game->id)
        ->where('invitee_email', $suppressedHash)
        ->count();
    expect($count)->toBe(1);
});

test('non-suppressed email still uses real email for invitee_email', function () {
    Mail::fake();

    $normalEmail = 'normal@example.com';

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', $normalEmail)
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    $participant = GameParticipant::where('game_id', $this->game->id)
        ->where('invitee_email', $normalEmail)
        ->first();

    expect($participant)->not->toBeNull();
    expect($participant->invitee_email)->toBe($normalEmail);

    // Email should still be dispatched for non-suppressed addresses
    Mail::assertQueued(EntityInvitationEmail::class);
});

test('suppressed email participant has correct metadata', function () {
    Mail::fake();

    $suppressedEmail = 'metadata@example.com';
    SuppressedInviteEmail::suppress($suppressedEmail);

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', $suppressedEmail)
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    $suppressedHash = 'suppressed-'.SuppressedInviteEmail::hashEmail($suppressedEmail);

    $this->assertDatabaseHas('game_participants', [
        'game_id' => $this->game->id,
        'invitee_email' => $suppressedHash,
        'user_id' => null,
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);
});
