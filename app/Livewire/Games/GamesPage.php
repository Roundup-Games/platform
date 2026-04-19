<?php

namespace App\Livewire\Games;

use App\Models\Game;
use App\Models\GameParticipant;
use App\Services\GameActivityFeedService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class GamesPage extends Component
{
    use WithPagination;

    public function mount(): void
    {
        if (Auth::guest()) {
            $this->redirect(route('discover', app()->getLocale()));
        }
    }

    public function render()
    {
        $user = Auth::user();

        $ownedGames = Game::where('owner_id', $user->id)
            ->with(['gameSystem', 'participants'])
            ->orderBy('date_time', 'desc')
            ->get();

        $participatingGames = Game::whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->where('role', 'player')
                ->where('status', 'approved');
        })->where('owner_id', '!=', $user->id)
            ->with(['gameSystem', 'participants', 'owner'])
            ->orderBy('date_time', 'desc')
            ->get();

        $pendingInvitations = GameParticipant::where('user_id', $user->id)
            ->where('role', 'invited')
            ->where('status', 'pending')
            ->with(['game.gameSystem', 'game.owner'])
            ->get();

        // Community activity feed — what friends/followed users are doing in games
        $activityFeed = app(GameActivityFeedService::class)->getFeed($user, 15);

        return view('livewire.games.games-page', [
            'ownedGames' => $ownedGames,
            'participatingGames' => $participatingGames,
            'pendingInvitations' => $pendingInvitations,
            'activityFeed' => $activityFeed,
        ]);
    }

    public function cancelGame(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('update', $game);

        if ($game->status !== 'scheduled') {
            session()->flash('error', __('games.error_game_not_scheduled'));
            return;
        }

        $game->status = 'canceled';
        $game->save();

        Log::info('Game canceled', [
            'game_id' => $game->id,
            'owner_id' => $game->owner_id,
            'previous_status' => 'scheduled',
            'new_status' => 'canceled',
        ]);

        session()->flash('success', __('games.flash_game_canceled'));
    }

    public function completeGame(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('update', $game);

        if ($game->status !== 'scheduled') {
            session()->flash('error', __('games.error_game_not_scheduled'));
            return;
        }

        $game->status = 'completed';
        $game->save();

        Log::info('Game completed', [
            'game_id' => $game->id,
            'owner_id' => $game->owner_id,
            'previous_status' => 'scheduled',
            'new_status' => 'completed',
        ]);

        session()->flash('success', __('games.flash_game_completed'));
    }

    public function acceptInvitation(string $participantId): void
    {
        $participant = GameParticipant::findOrFail($participantId);

        if ($participant->user_id !== Auth::id()) {
            session()->flash('error', __('games.error_not_your_invitation'));
            return;
        }

        if ($participant->role !== 'invited' || $participant->status !== 'pending') {
            session()->flash('error', __('games.error_invitation_invalid'));
            return;
        }

        $game = $participant->game;

        if ($game->max_players) {
            $currentPlayers = $game->participants()
                ->where('role', 'player')
                ->where('status', 'approved')
                ->count();
            if ($currentPlayers >= $game->max_players) {
                session()->flash('error', __('games.error_game_full'));
                return;
            }
        }

        $participant->role = 'player';
        $participant->status = 'approved';
        $participant->save();

        Log::info('Invitation accepted', [
            'game_id' => $game->id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'previous_role' => 'invited',
            'new_role' => 'player',
            'previous_status' => 'pending',
            'new_status' => 'approved',
        ]);

        session()->flash('success', __('games.flash_invitation_accepted'));
    }

    public function declineInvitation(string $participantId): void
    {
        $participant = GameParticipant::findOrFail($participantId);

        if ($participant->user_id !== Auth::id()) {
            session()->flash('error', __('games.error_not_your_invitation'));
            return;
        }

        if ($participant->role !== 'invited' || $participant->status !== 'pending') {
            session()->flash('error', __('games.error_invitation_invalid'));
            return;
        }

        $participant->status = 'rejected';
        $participant->save();

        Log::info('Invitation declined', [
            'game_id' => $participant->game_id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'previous_status' => 'pending',
            'new_status' => 'rejected',
        ]);

        session()->flash('success', __('games.flash_invitation_declined'));
    }
}
