<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\ManageParticipants as GameManageParticipants;
use App\Models\GameParticipant;
use App\Models\User;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

beforeEach(function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
    $this->owner = $owner;
    $this->game = $game;
});

// ═══════════════════════════════════════════════════════════
// 1. OWNER IS EXCLUDED FROM APPROVED PARTICIPANTS, SHOWN SEPARATELY
// ═══════════════════════════════════════════════════════════

test('approved participants count excludes owner', function () {
    // Add 2 approved players
    $player1 = User::factory()->create(['profile_complete' => true]);
    $player2 = User::factory()->create(['profile_complete' => true]);

    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $player1->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $player2->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    $component = Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id]);

    // approvedParticipants should have 2 (players only, no owner)
    $approved = $component->viewData('approvedParticipants');
    expect($approved)->toHaveCount(2);

    // Verify none of them are the owner
    $approved->each(fn ($p) => expect($p->role)->not->toBe(ParticipantRole::Owner));
});

test('owner name does not appear in approved participants section', function () {
    $player = User::factory()->create([
        'name' => 'VisiblePlayer',
        'profile_complete' => true,
    ]);

    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->assertSee('VisiblePlayer')
        ->assertDontSee($this->owner->name);
});

test('owner is accessible via game relationship for separate display', function () {
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id]);

    // The game's owner relationship is still intact
    expect($this->game->owner->id)->toBe($this->owner->id);
});

test('approved participants section shows count excluding owner', function () {
    $player = User::factory()->create(['profile_complete' => true]);

    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    $component = Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id]);

    // The rendered view should show count of 1 (not 2 including owner)
    $component->assertSee('(1)');
});

// ═══════════════════════════════════════════════════════════
// 3. REMOVE OWNER IS PREVENTED BY PARTICIPANT SERVICE
// ═══════════════════════════════════════════════════════════

test('attempting to remove owner participant is blocked', function () {
    $ownerParticipant = $this->game->participants()
        ->where('user_id', $this->owner->id)
        ->where('role', ParticipantRole::Owner->value)
        ->first();

    expect($ownerParticipant)->not->toBeNull();

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->call('removeParticipant', $ownerParticipant->id)
        ->assertHasNoErrors();

    // Owner participant should still exist
    expect(
        $this->game->participants()
            ->where('user_id', $this->owner->id)
            ->where('role', ParticipantRole::Owner->value)
            ->exists()
    )->toBeTrue();
});

// ═══════════════════════════════════════════════════════════
// 4. PARTICIPANT COUNT FOR NON-OWNERS IS CORRECT
// ═══════════════════════════════════════════════════════════

test('multiple approved players all appear and owner is excluded', function () {
    $player1 = User::factory()->create(['profile_complete' => true]);
    $player2 = User::factory()->create(['profile_complete' => true]);

    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $player1->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $player2->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    $component = Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id]);

    $approved = $component->viewData('approvedParticipants');
    // Should be 2 (both players), not 3 (players + owner)
    expect($approved)->toHaveCount(2);
    $approved->each(fn ($p) => expect($p->role)->toBe(ParticipantRole::Player));
});

test('player count is zero when only owner is a participant', function () {
    $component = Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id]);

    $approved = $component->viewData('approvedParticipants');
    expect($approved)->toHaveCount(0);
});

// ═══════════════════════════════════════════════════════════
// 5. INVITE FLOWS DON'T ALLOW INVITING THE OWNER
// ═══════════════════════════════════════════════════════════

test('inviting the owner via friends list is skipped', function () {
    // The owner is the inviter, so trying to invite themselves should be skipped.
    // This tests the ParticipantService's self-invite guard.
    $component = Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('selectedFriendIds', [(string) $this->owner->id])
        ->call('inviteParticipants');

    // No new participant should be created for the owner
    $ownerParticipants = $this->game->participants()
        ->where('user_id', $this->owner->id)
        ->get();

    // Still just the original owner participant
    expect($ownerParticipants)->toHaveCount(1);
    expect($ownerParticipants->first()->role)->toBe(ParticipantRole::Owner);
});

test('inviting the owner via email is rejected with error', function () {
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->set('inviteEmail', $this->owner->email)
        ->call('inviteByEmail')
        ->assertHasErrors(['inviteEmail']);

    // No additional participant for the owner
    expect(
        $this->game->participants()
            ->where('user_id', $this->owner->id)
            ->count()
    )->toBe(1);
});
