<?php

namespace App\Livewire\Games;

use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            session()->flash('info', __('You are already a participant or have a pending invite/application for this game.'));

            return;
        }

        if ($game->applications()->where('user_id', Auth::id())->exists()) {
            session()->flash('info', __('You have already applied to this game.'));

            return;
        }
    }

    public function submitApplication(): void
    {
        // Owner cannot apply to their own game
        if ($this->game->owner_id === Auth::id()) {
            $this->addError('message', __('You cannot apply to your own game.'));

            return;
        }

        $gameId = $this->game->id;
        $userId = Auth::id();
        $message = $this->message;

        $this->validate();

        try {
            DB::transaction(function () use ($gameId, $userId, $message) {
                // Pessimistic lock on existing participant/application rows for this game+user
                GameParticipant::lockForUpdate()
                    ->where('game_id', $gameId)
                    ->where('user_id', $userId)
                    ->exists();

                GameApplication::lockForUpdate()
                    ->where('game_id', $gameId)
                    ->where('user_id', $userId)
                    ->exists();

                // Double-check no existing participant record
                if (GameParticipant::where('game_id', $gameId)->where('user_id', $userId)->exists()) {
                    throw new \RuntimeException(__('You are already a participant or have a pending invite.'));
                }

                // Double-check no existing application
                if (GameApplication::where('game_id', $gameId)->where('user_id', $userId)->exists()) {
                    throw new \RuntimeException(__('You have already applied to this game.'));
                }

                // For public games, auto-approve; for protected games, require approval
                $isPublic = Game::find($gameId)->visibility === 'public';

                // Create application record (always)
                GameApplication::create([
                    'game_id' => $gameId,
                    'user_id' => $userId,
                    'status' => $isPublic ? 'approved' : 'pending',
                    'message' => $message ?: null,
                ]);

                // Create participant record
                GameParticipant::create([
                    'game_id' => $gameId,
                    'user_id' => $userId,
                    'role' => $isPublic ? 'player' : 'applicant',
                    'status' => $isPublic ? 'approved' : 'pending',
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique constraint violation — concurrent duplicate
            Log::warning('Game application race caught by unique constraint', [
                'game_id' => $gameId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            session()->flash('info', __('You have already applied to this game.'));
            $this->redirect(route('games.detail', $this->game->id), navigate: true);

            return;
        } catch (\RuntimeException $e) {
            $this->addError('message', $e->getMessage());

            return;
        }

        $isPublic = $this->game->visibility === 'public';

        Log::info('Game application submitted', [
            'game_id' => $this->game->id,
            'user_id' => Auth::id(),
            'auto_approved' => $isPublic,
        ]);

        if ($isPublic) {
            session()->flash('success', __('You have joined the game!'));
        } else {
            session()->flash('success', __('Application submitted! The game owner will review it.'));
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
