<?php

use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\ManageParticipants as GameManageParticipants;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

beforeEach(function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
    $this->owner = $owner;
    $this->game = $game;
});

// ═══════════════════════════════════════════════════════════
// 1. EMAIL INVITE INPUT APPEARS ON MANAGE PARTICIPANTS PAGE
// ═══════════════════════════════════════════════════════════

test('email invite input appears on manage participants page', function () {
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->assertSee('Invite by email')
        ->assertSee('or')
        ->assertSeeHtml('id="invite-email"');
});

// ═══════════════════════════════════════════════════════════
// 2. CAN SUBMIT EMAIL INVITE FROM MANAGE PAGE
// ═══════════════════════════════════════════════════════════

test('can submit email invite from manage page', function () {
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
// 3. EMAIL INVITEE SHOWS IN PENDING INVITES WITH EMAIL
// ═══════════════════════════════════════════════════════════

test('email invitee shows in pending invites with email', function () {
    // Create an email invite with null user_id
    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => null,
        'invitee_email' => 'pending-user@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->assertSee('pending-user@example.com')
        ->assertSee('Pending account creation');
});

// ═══════════════════════════════════════════════════════════
// 4. CANCEL EMAIL INVITE REMOVES FROM PENDING
// ═══════════════════════════════════════════════════════════

test('cancel email invite removes from pending', function () {
    Mail::fake();

    // Create email invite
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', 'cancel-me@example.com')
        ->call('inviteByEmail')
        ->assertHasNoErrors();

    $participant = GameParticipant::where('game_id', $this->game->id)
        ->where('invitee_email', 'cancel-me@example.com')
        ->first();

    expect($participant)->not->toBeNull();
    expect($participant->status)->toBe(ParticipantStatus::Pending);

    // Cancel the invite
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->call('cancelInvite', $participant->id)
        ->assertHasNoErrors();

    // Should now be rejected
    $this->assertDatabaseHas('game_participants', [
        'id' => $participant->id,
        'status' => ParticipantStatus::Rejected->value,
    ]);

    // Should no longer appear in pending invites
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->assertDontSee('cancel-me@example.com');
});

// ═══════════════════════════════════════════════════════════
// 5. MATCHED EMAIL INVITEE SHOWS AS NORMAL PENDING INVITE
// ═══════════════════════════════════════════════════════════

test('matched email invitee shows as normal pending invite', function () {
    // Create a user that will be the matched registrant
    $matchedUser = User::factory()->create([
        'email' => 'matched@example.com',
        'profile_complete' => true,
    ]);

    // Create email invite that has been matched (user_id populated)
    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $matchedUser->id,
        'invitee_email' => 'matched@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    // Render the page — should show user-link (via <x-user-link>) instead of email display
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->assertSee($matchedUser->name)
        ->assertSee($matchedUser->email)
        // Should NOT show "Pending account creation" for matched invitees
        ->assertDontSee('Pending account creation');
});
