<?php

namespace App\Livewire\Games;

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\Review;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.public-layout')]
class PublicGameDetail extends Component
{
    public Game $game;

    #[Locked]
    public ?string $validatedShareToken = null;

    public function mount(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('view', $game);
        $this->game = $game;

        // Capture valid share token on initial page load
        if ($game->hasValidShareToken()) {
            $this->validatedShareToken = request()->query('share');
        }

        // Set share_intent cookie for guests visiting via share link
        if (Auth::guest() && $this->validatedShareToken !== null) {
            Cookie::queue('share_intent', json_encode([
                'entity_type' => 'game',
                'entity_id' => $game->id,
                'share_token' => $this->validatedShareToken,
            ]), 24 * 60);
        }
    }

    #[Computed]
    public function isOwner(): bool
    {
        return ($id = Auth::id()) && $this->game->owner_id === $id;
    }

    #[Computed]
    public function approvedParticipantsCount(): int
    {
        return $this->game->participants
            ->where('status', ParticipantStatus::Approved->value)
            ->count();
    }

    #[Computed]
    public function reviews()
    {
        if (Auth::guest()) {
            return collect();
        }

        return Review::where('reviewable_type', Game::class)
            ->where('reviewable_id', $this->game->id)->published()
            ->with('reviewer')->latest()->limit(10)->get();
    }

    #[Computed]
    public function hasShareLink(): bool
    {
        return $this->game->share_token !== null;
    }

    #[Computed]
    public function shareLinkUrl(): ?string
    {
        if ($this->game->share_token === null) {
            return null;
        }

        return route('games.detail', $this->game->id) . '?share=' . $this->game->share_token;
    }

    public function render()
    {
        $relations = [
            'owner', 'campaign', 'gameSystem.categories', 'gameSystem.mechanics',
            'gameSystem.publishers', 'gameSystem.baseGame', 'gameSystem.expansions',
            'linkedLocation',
        ];
        if (Auth::check()) {
            $relations[] = 'participants.user';
        } else {
            $relations[] = 'participants';
        }
        $this->game->load($relations);

        seo()->for($this->game);

        return view('livewire.games.public-game-detail', [
            'game' => $this->game,
            'isOwner' => $this->isOwner(),
            'isGuest' => Auth::guest(),
            'reviews' => $this->reviews(),
            'approvedParticipantsCount' => $this->approvedParticipantsCount(),
            'hasShareLink' => $this->hasShareLink(),
            'shareLinkUrl' => $this->shareLinkUrl(),
        ]);
    }
}
