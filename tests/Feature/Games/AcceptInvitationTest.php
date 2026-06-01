<?php

use App\Enums\ParticipantRole;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Enums\ParticipantStatus;
use function Pest\Laravel\{actingAs, assertDatabaseHas, get};

// ── Helpers ──────────────────────────────────────────────

function acceptTestCreateGameWithOwner(array $gameAttrs = []): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        ...$gameAttrs,
    ]);

    return ['owner' => $owner, 'game' => $game];
}

// ═══════════════════════════════════════════════════════════
// GAME: ACCEPT INVITATION
// ═══════════════════════════════════════════════════════════

describe('Game AcceptInvitation', function () {
    test('invited user can accept their invitation', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Invitation accepted');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'user_id' => $invitedUser->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    })->group('smoke');

    test('cannot accept someone else\'s invitation', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $otherUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($otherUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertSee('not yours');

        // Should remain pending
        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
    })->group('smoke');

    test('cannot accept already-accepted invitation', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $user = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertSee('no longer valid');

        // Should remain unchanged
        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    })->group('smoke');

    test('full game routes to waitlist on accept', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner(['max_players' => 2]);
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        // Fill up the game: owner + 1 approved player = 2 (max)
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $filler = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $filler->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id);

        // Should be moved to waitlist (bench_mode=false by default for standalone games)
        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'user_id' => $invitedUser->id,
            'status' => ParticipantStatus::Waitlisted->value,
        ]);
    })->group('smoke');

    test('can accept when under capacity', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner(['max_players' => 5]);
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Invitation accepted');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    })->group('smoke');

    test('accept invitation from manage participants page', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        // Invited user shouldn't normally be on manage-participants page,
        // but the trait method should still work via any component using it
        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors();

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// GAME: DECLINE INVITATION
// ═══════════════════════════════════════════════════════════

describe('Game DeclineInvitation', function () {
    test('invited user can decline their invitation', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('declineInvitation', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('declined');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => ParticipantStatus::Rejected->value,
        ]);
    })->group('smoke');

    test('cannot decline someone else\'s invitation', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $otherUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($otherUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('declineInvitation', $participant->id)
            ->assertSee('not yours');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// GAME DETAIL: INVITATION BANNER VISIBILITY
// ═══════════════════════════════════════════════════════════

describe('Game Invitation Banner', function () {
    test('invited user sees invitation banner on game detail', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee('Accept Invitation')
            ->assertSee('Accept');
    })->group('smoke');


});


