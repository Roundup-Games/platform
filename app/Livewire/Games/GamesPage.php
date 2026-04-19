<?php

namespace App\Livewire\Games;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class GamesPage extends Component
{
    use EscapesLikeWildcards;
    use WithPagination;

    // Community filters
    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $game_system_id = null;

    #[Url]
    public string $experience_level = '';

    #[Url]
    public array $vibe_flags = [];

    #[Url]
    public string $language = '';

    #[Url]
    public string $date = '';

    #[Url]
    public string $price = '';

    #[Url]
    public ?string $complexity_min = null;

    #[Url]
    public ?string $complexity_max = null;

    public function mount(): void
    {
        if (Auth::guest()) {
            $this->redirect(route('discover', app()->getLocale()));
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingGameSystemId(): void
    {
        $this->resetPage();
    }

    public function updatingExperienceLevel(): void
    {
        $this->resetPage();
    }

    public function updatingVibeFlags(): void
    {
        $this->resetPage();
    }

    public function updatingLanguage(): void
    {
        $this->resetPage();
    }

    public function updatingDate(): void
    {
        $this->resetPage();
    }

    public function updatingPrice(): void
    {
        $this->resetPage();
    }

    public function updatingComplexityMin(): void
    {
        $this->resetPage();
    }

    public function updatingComplexityMax(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'game_system_id', 'experience_level', 'vibe_flags',
            'language', 'date', 'price', 'complexity_min', 'complexity_max',
        ]);
        $this->resetPage();
    }

    public function toggleVibeFlag(string $flag): void
    {
        $index = array_search($flag, $this->vibe_flags, true);
        if ($index !== false) {
            unset($this->vibe_flags[$index]);
            $this->vibe_flags = array_values($this->vibe_flags);
        } else {
            $this->vibe_flags[] = $flag;
        }
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search
            || $this->game_system_id
            || $this->experience_level
            || !empty($this->vibe_flags)
            || $this->language
            || $this->date
            || $this->price
            || $this->complexity_min
            || $this->complexity_max;
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

        // Community games query — visibility-scoped, filtered, paginated
        $communityQuery = Game::query()
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public');
                $q->orWhere(function ($q) use ($user) {
                    $q->where('visibility', 'protected')
                        ->where(function ($q) use ($user) {
                            $allowedOwnerIds = $user->getAllowedOwnerIdsForProtectedContent();
                            $q->whereIn('owner_id', $allowedOwnerIds)
                                ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $user->id));
                        });
                });
            })
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->with(['owner', 'gameSystem', 'campaign'])
            ->withCount('participants');

        // Search
        $communityQuery->when($this->search, fn ($q) => $q->where(function ($q) {
            $escaped = $this->escapeLikeWildcards($this->search);
            $q->where('name', 'like', "%{$escaped}%")
              ->orWhere('description', 'like', "%{$escaped}%");
        }));

        // Game system filter
        $communityQuery->when($this->game_system_id, fn ($q) => $q->where('game_system_id', $this->game_system_id));

        // Experience level filter
        $communityQuery->when($this->experience_level, fn ($q) => $q->where('experience_level', $this->experience_level));

        // Vibe flags filter (JSON containment)
        $communityQuery->when(!empty($this->vibe_flags), function ($q) {
            foreach ($this->vibe_flags as $flag) {
                $q->whereJsonContains('vibe_flags', $flag);
            }
        });

        // Language filter
        $communityQuery->when($this->language, fn ($q) => $q->where('language', $this->language));

        // Date range filter
        $communityQuery->when($this->date === 'upcoming', fn ($q) => $q->where('date_time', '>=', now()));
        $communityQuery->when($this->date === 'this_week', fn ($q) => $q->whereBetween('date_time', [now()->startOfWeek(), now()->endOfWeek()]));
        $communityQuery->when($this->date === 'this_month', fn ($q) => $q->whereBetween('date_time', [now()->startOfMonth(), now()->endOfMonth()]));

        // Price filter
        $communityQuery->when($this->price === 'free', fn ($q) => $q->where(fn ($q) => $q->where('price', 0)->orWhereNull('price')));
        $communityQuery->when($this->price === 'paid', fn ($q) => $q->where('price', '>', 0));

        // Complexity range
        $communityQuery->when($this->complexity_min, fn ($q) => $q->where('complexity', '>=', (float) $this->complexity_min));
        $communityQuery->when($this->complexity_max, fn ($q) => $q->where('complexity', '<=', (float) $this->complexity_max));

        $communityGames = $communityQuery->orderBy('date_time')->paginate(12);

        return view('livewire.games.games-page', [
            'ownedGames' => $ownedGames,
            'participatingGames' => $participatingGames,
            'pendingInvitations' => $pendingInvitations,
            'communityGames' => $communityGames,
            'gameSystems' => GameSystem::orderBy('name')->get(['id', 'name']),
            'experienceLevels' => ExperienceLevel::cases(),
            'vibeFlagGroups' => VibeFlag::grouped(),
            'languages' => ContentLanguage::cases(),
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
