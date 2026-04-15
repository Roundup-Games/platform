<?php

namespace App\Livewire\Games;

use App\Models\Game;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class GameDetail extends Component
{
    public Game $game;

    public function mount(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('view', $game);
        $this->game = $game;
    }

    public function render()
    {
        $this->game->load([
            'owner',
            'campaign',
            'gameSystem',
            'participants.user',
            'applications.user',
        ]);

        return view('livewire.games.game-detail', [
            'game' => $this->game,
            'isOwner' => Auth::check() && $this->game->owner_id === Auth::id(),
            'isParticipant' => Auth::check() && $this->game->participants()
                ->where('user_id', Auth::id())
                ->exists(),
        ]);
    }
}
