<?php

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();

    $this->game = Game::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => 'Test Game',
        'date_time' => now()->addDays(7),
        'description' => 'A test game',
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => 5,
        'campaign_id' => null,
    ]);

    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $this->owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);
});

describe('clone link visibility', function () {
    it('shows clone link to game owner', function () {
        $cloneUrl = route('games.create', ['locale' => app()->getLocale()]) . '?clone=' . $this->game->id;

        Livewire::actingAs($this->owner)
            ->test('games.game-detail', ['id' => $this->game->id])
            ->assertSeeHtml('href="' . $cloneUrl . '"')
            ->assertSee(__('games.action_create_similar_session'))
            ->assertSee('content_copy');
    });

    it('does not show clone link to non-owner', function () {
        Livewire::actingAs($this->otherUser)
            ->test('games.game-detail', ['id' => $this->game->id])
            ->assertDontSee(__('games.action_create_similar_session'))
            ->assertDontSee('content_copy');
    });

    it('does not show clone link to guest', function () {
        Livewire::test('games.game-detail', ['id' => $this->game->id])
            ->assertDontSee(__('games.action_create_similar_session'))
            ->assertDontSee('content_copy');
    });
});

describe('clone link URL format', function () {
    it('generates correct clone URL with game ID', function () {
        $expectedUrl = route('games.create', ['locale' => app()->getLocale()]) . '?clone=' . $this->game->id;

        Livewire::actingAs($this->owner)
            ->test('games.game-detail', ['id' => $this->game->id])
            ->assertSeeHtml('href="' . $expectedUrl . '"')
            ->assertSeeHtml('wire:navigate');
    });
});
