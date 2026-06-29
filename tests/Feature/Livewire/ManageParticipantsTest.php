<?php

use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\ManageParticipants as GameManageParticipants;
use App\Models\GameApplication;
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

// ═══════════════════════════════════════════════════════════
// APPLICATION MESSAGE VISIBILITY (M054/S01)
// A host must see the message an applicant wrote, even on public games
// where applications are auto-approved and the applicant never passes
// through the Pending Applications section.
// ═══════════════════════════════════════════════════════════

test('host sees application message for an auto-approved applicant', function () {
    $applicant = User::factory()->create([
        'name' => 'MessageApplicant',
        'profile_complete' => true,
    ]);

    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $applicant->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
        'join_source' => JoinSource::Application->value,
    ]);

    GameApplication::create([
        'game_id' => $this->game->id,
        'user_id' => $applicant->id,
        'status' => 'approved',
        'message' => 'I would love to join your game!',
    ]);

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->assertSee('I would love to join your game!');
});

test('host does not see a message slot for a non-application participant', function () {
    // A player who joined via share link has no application message and should
    // not render an empty message slot.
    $player = User::factory()->create([
        'name' => 'ShareLinkPlayer',
        'profile_complete' => true,
    ]);

    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
        'join_source' => JoinSource::ShareLink->value,
    ]);

    Livewire\Livewire::actingAs($this->owner)
        ->test(GameManageParticipants::class, ['id' => $this->game->id])
        ->assertDontSee('I would love to join your game!');
});
