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
// OWNER IS EXCLUDED FROM APPROVED PARTICIPANTS LIST
// ═══════════════════════════════════════════════════════════

test('owner is excluded from approved participants list', function () {
    // Only the owner is a participant — approved list should be empty
    $component = Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id]);

    // The approvedParticipants collection should be empty (owner filtered out)
    $approvedParticipants = $component->viewData('approvedParticipants');
    expect($approvedParticipants)->toHaveCount(0);
});

test('approved non-owner players appear in participants list', function () {
    $player = User::factory()->create(['profile_complete' => true]);
    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    $component = Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id]);

    $approvedParticipants = $component->viewData('approvedParticipants');
    expect($approvedParticipants)->toHaveCount(1);
    expect($approvedParticipants->first()->user_id)->toBe($player->id);
});

test('owner does not appear even when multiple approved players exist', function () {
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

    $approvedParticipants = $component->viewData('approvedParticipants');
    // 2 players, no owner
    expect($approvedParticipants)->toHaveCount(2);
    $approvedParticipants->each(function ($p) {
        expect($p->role)->not->toBe(ParticipantRole::Owner);
    });
});

test('owner user is still accessible via game relationship', function () {
    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->assertOk();

    // The game's owner relationship should still work
    expect($this->game->owner->id)->toBe($this->owner->id);
});

test('owner name does not appear in approved participants section', function () {
    // Add a player so the section is non-empty
    $player = User::factory()->create([
        'name' => 'UniquePlayerName',
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
        ->assertSee('UniquePlayerName')
        ->assertDontSee($this->owner->name);
});
