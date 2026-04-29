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

function recapCreateCompletedGame(User $owner, GameSystem $system, array $overrides = []): Game
{
    return Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'status' => 'completed',
        'campaign_id' => null,
        ...$overrides,
    ]);
}

describe('recap write UI', function () {
    it('shows write recap form for game owner when game is completed with no recap', function () {
        $game = recapCreateCompletedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee(__('games.title_write_recap'))
            ->assertSee(__('games.action_recap_submit'));
    });

    it('hides write recap form when game already has recap', function () {
        $game = recapCreateCompletedGame($this->owner, $this->gameSystem, [
            'recap' => 'A great session happened!',
        ]);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('games.title_write_recap'))
            ->assertDontSee(__('games.action_recap_submit'))
            ->assertSee(__('games.title_host_recap'));
    });

    it('hides write recap form for non-owners', function () {
        $game = recapCreateCompletedGame($this->owner, $this->gameSystem);
        $nonOwner = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $nonOwner->id,
            'status' => ParticipantStatus::Approved,
        ]);

        Livewire::actingAs($nonOwner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('games.title_write_recap'))
            ->assertDontSee(__('games.action_recap_submit'));
    });

    it('hides write recap form for non-completed games', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'campaign_id' => null,
        ]);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('games.title_write_recap'));
    });
});

describe('write recap action', function () {
    it('allows owner to write recap via Livewire', function () {
        $game = recapCreateCompletedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->set('recapContent', 'An epic adventure unfolded!')
            ->call('writeRecap')
            ->assertHasNoErrors();

        expect($game->fresh()->recap)->toBe('An epic adventure unfolded!');
    });

    it('validates recap content is required', function () {
        $game = recapCreateCompletedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->set('recapContent', '')
            ->call('writeRecap')
            ->assertHasErrors(['recapContent' => 'required']);
    });

    it('validates recap content max 2000 chars', function () {
        $game = recapCreateCompletedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->set('recapContent', str_repeat('a', 2001))
            ->call('writeRecap')
            ->assertHasErrors(['recapContent' => 'max']);
    });

    it('rejects recap from non-owner', function () {
        $game = recapCreateCompletedGame($this->owner, $this->gameSystem);
        $nonOwner = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $nonOwner->id,
            'status' => ParticipantStatus::Approved,
        ]);

        Livewire::actingAs($nonOwner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->set('recapContent', 'Sneaky recap attempt')
            ->call('writeRecap');

        // Recap should NOT be written — RecapService throws LogicException for non-hosts
        expect($game->fresh()->recap)->toBeNull();
    });
});
