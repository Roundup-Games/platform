<?php

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);

    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

function capacityCreateFullPublicGame(User $owner, GameSystem $system, int $maxPlayers = 2): Game
{
    $game = Game::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => ['en' => 'Full Public Game'],
        'date_time' => now()->addDays(7),
        'description' => ['en' => 'A test game'],
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 1,
        'max_players' => $maxPlayers,
        'campaign_id' => null,
    ]);

    // Fill with approved participants including owner
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($i = 1; $i < $maxPlayers; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return $game;
}

describe('ApplyToGame capacity guard', function () {
    it('auto-waitlists applicant when standalone public game is full', function () {
        $game = capacityCreateFullPublicGame($this->owner, $this->gameSystem, maxPlayers: 2);
        $applicant = User::factory()->create();

        Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'I want to join!')
            ->call('submitApplication');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();

        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Waitlisted)
            ->and($participant->waitlisted_at)->not->toBeNull();
    })->group('smoke');

    it('auto-approves when standalone public game has capacity', function () {
        // Create a game with capacity (5 max, only 2 participants → 3 open slots)
        $game = Game::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Public Game With Capacity'],
            'date_time' => now()->addDays(7),
            'description' => ['en' => 'A test game'],
            'expected_duration' => 3,
            'visibility' => 'public',
            'status' => 'scheduled',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 1,
            'max_players' => 5,
            'campaign_id' => null,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $applicant = User::factory()->create();

        Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Let me in!')
            ->call('submitApplication');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();

        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Approved);
    })->group('smoke');

});
