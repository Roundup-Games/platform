<?php

namespace App\Livewire\Games;

use App\Models\Game;
use App\Traits\ManagesParticipants;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

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

        $viewer = Auth::user();
        $isOwner = $viewer && $this->game->owner_id === $viewer->id;
        $isParticipant = $viewer && $this->game->participants
            ->contains(fn ($p) => $p->user_id === $viewer->id);

        $userInvitation = null;
        $hasExistingApplication = false;
        if ($viewer) {
            $userInvitation = $this->game->participants
                ->first(fn ($p) => $p->user_id === $viewer->id
                    && $p->role === 'invited'
                    && $p->status === 'pending');

            $hasExistingApplication = $this->game->applications()
                ->where('user_id', $viewer->id)
                ->exists();
        }

        $canApply = $viewer
            && ! $isOwner
            && ! $isParticipant
            && ! $hasExistingApplication
            && $this->game->visibility !== 'private';

        $canReview = false;
        $reviews = collect();

        if ($viewer) {
            $canReview = app(\App\Services\ReviewEligibilityService::class)
                ->canReviewSession($viewer, $this->game);
        }

        $reviews = \App\Models\Review::where('reviewable_type', \App\Models\Game::class)
            ->where('reviewable_id', $this->game->id)
            ->published()
            ->with('reviewer')
            ->latest()
            ->limit(10)
            ->get();

        return view('livewire.games.game-detail', [
            'game' => $this->game,
            'isOwner' => $isOwner,
            'isParticipant' => $isParticipant,
            'userInvitation' => $userInvitation,
            'canApply' => $canApply,
            'hasExistingApplication' => $hasExistingApplication,
            'isGuest' => Auth::guest(),
            'reviews' => $reviews,
            'canReview' => $canReview,
        ])->layout(Auth::guest() ? 'components.public-layout' : 'layouts.app');
    }
}
