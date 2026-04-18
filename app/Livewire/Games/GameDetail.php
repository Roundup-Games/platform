<?php

namespace App\Livewire\Games;

use App\Models\Game;
use App\Traits\ManagesParticipants;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class GameDetail extends Component
{
    use ManagesParticipants;

    public Game $game;

    public function mount(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('view', $game);
        $this->game = $game;
    }

    // ── Trait contracts ────────────────────────────────

    public function getEntity(): Game
    {
        return $this->game;
    }

    public function getEntityIdColumn(): string
    {
        return 'game_id';
    }

    public function getParticipantModel(): string
    {
        return \App\Models\GameParticipant::class;
    }

    public function getEntityName(): string
    {
        return 'Game';
    }

    public function getEntityVar(): string
    {
        return 'game';
    }

    public function getBackRoute(): string
    {
        return route('games.detail', $this->game->id);
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

        $userInvitation = null;
        if (Auth::check()) {
            $userInvitation = $this->game->participants
                ->first(fn ($p) => $p->user_id === Auth::id()
                    && $p->role === 'invited'
                    && $p->status === 'pending');
        }

        return view('livewire.games.game-detail', [
            'game' => $this->game,
            'isOwner' => Auth::check() && $this->game->owner_id === Auth::id(),
            'isParticipant' => Auth::check() && $this->game->participants()
                ->where('user_id', Auth::id())
                ->exists(),
            'userInvitation' => $userInvitation,
        ]);
    }
}
