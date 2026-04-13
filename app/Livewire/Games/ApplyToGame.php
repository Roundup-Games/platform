<?php

namespace App\Livewire\Games;

use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class ApplyToGame extends Component
{
    public Game $game;

    #[Validate('nullable|string|max:1000')]
    public string $message = '';

    public function mount(string $id): void
    {
        $game = Game::findOrFail($id);

        // Must be logged in to apply
        if (! Auth::check()) {
            $this->redirect(route('login'));

            return;
        }

        $this->authorize('view', $game);
        $this->game = $game;

        // Only public and protected games accept applications
        if ($game->visibility === 'private') {
            abort(403, 'This game does not accept applications.');
        }

        // Check if already a participant or has pending application
        if ($game->participants()->where('user_id', Auth::id())->exists()) {
            session()->flash('info', 'You are already a participant or have a pending invite/application for this game.');

            return;
        }

        if ($game->applications()->where('user_id', Auth::id())->exists()) {
            session()->flash('info', 'You have already applied to this game.');

            return;
        }
    }

    public function submitApplication(): void
    {
        // Owner cannot apply to their own game
        if ($this->game->owner_id === Auth::id()) {
            $this->addError('message', 'You cannot apply to your own game.');

            return;
        }

        // Double-check no existing participant record
        if ($this->game->participants()->where('user_id', Auth::id())->exists()) {
            $this->addError('message', 'You are already a participant or have a pending invite.');

            return;
        }

        // Double-check no existing application
        if ($this->game->applications()->where('user_id', Auth::id())->exists()) {
            $this->addError('message', 'You have already applied to this game.');

            return;
        }

        $this->validate();

        // For public games, auto-approve; for protected games, require approval
        $isPublic = $this->game->visibility === 'public';

        // Create application record (always)
        GameApplication::create([
            'game_id' => $this->game->id,
            'user_id' => Auth::id(),
            'status' => $isPublic ? 'approved' : 'pending',
            'message' => $this->message ?: null,
        ]);

        // Create participant record
        GameParticipant::create([
            'game_id' => $this->game->id,
            'user_id' => Auth::id(),
            'role' => 'applicant',
            'status' => $isPublic ? 'approved' : 'pending',
        ]);

        // For public games, immediately promote to player
        if ($isPublic) {
            $this->game->participants()
                ->where('user_id', Auth::id())
                ->update(['role' => 'player']);
        }

        Log::info('Game application submitted', [
            'game_id' => $this->game->id,
            'user_id' => Auth::id(),
            'auto_approved' => $isPublic,
        ]);

        if ($isPublic) {
            session()->flash('success', 'You have joined the game!');
        } else {
            session()->flash('success', 'Application submitted! The game owner will review it.');
        }

        $this->redirect(route('games.detail', $this->game->id), navigate: true);
    }

    public function render()
    {
        return view('livewire.games.apply-to-game', [
            'game' => $this->game,
            'hasExistingApplication' => Auth::check() && $this->game->applications()
                ->where('user_id', Auth::id())
                ->exists(),
            'isParticipant' => Auth::check() && $this->game->participants()
                ->where('user_id', Auth::id())
                ->exists(),
        ]);
    }
}
