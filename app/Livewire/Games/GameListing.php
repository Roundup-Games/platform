<?php

namespace App\Livewire\Games;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Models\Game;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.public-layout')]
class GameListing extends Component
{
    use EscapesLikeWildcards;
    use WithPagination;

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

        $query = Game::query()
            // Visibility scoping: public for everyone, protected for authed, private excluded
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public');
                if ($user) {
                    $q->orWhere('visibility', 'protected');
                }
            })
            // Only scheduled upcoming games
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->with(['owner', 'gameSystem', 'campaign'])
            ->withCount('participants');

        // Search
        $query->when($this->search, fn ($q) => $q->where(function ($q) {
            $escaped = $this->escapeLikeWildcards($this->search);
            $q->where('name', 'like', "%{$escaped}%")
              ->orWhere('description', 'like', "%{$escaped}%");
        }));

        // Game system filter
        $query->when($this->game_system_id, fn ($q) => $q->where('game_system_id', $this->game_system_id));

        // Experience level filter
        $query->when($this->experience_level, fn ($q) => $q->where('experience_level', $this->experience_level));

        // Vibe flags filter (JSON containment)
        $query->when(!empty($this->vibe_flags), function ($q) {
            foreach ($this->vibe_flags as $flag) {
                $q->whereJsonContains('vibe_flags', $flag);
            }
        });

        // Language filter
        $query->when($this->language, fn ($q) => $q->where('language', $this->language));

        // Date range filter
        $query->when($this->date === 'upcoming', fn ($q) => $q->where('date_time', '>=', now()));
        $query->when($this->date === 'this_week', fn ($q) => $q->whereBetween('date_time', [now()->startOfWeek(), now()->endOfWeek()]));
        $query->when($this->date === 'this_month', fn ($q) => $q->whereBetween('date_time', [now()->startOfMonth(), now()->endOfMonth()]));

        // Price filter
        $query->when($this->price === 'free', fn ($q) => $q->where(fn ($q) => $q->where('price', 0)->orWhereNull('price')));
        $query->when($this->price === 'paid', fn ($q) => $q->where('price', '>', 0));

        // Complexity range
        $query->when($this->complexity_min, fn ($q) => $q->where('complexity', '>=', (float) $this->complexity_min));
        $query->when($this->complexity_max, fn ($q) => $q->where('complexity', '<=', (float) $this->complexity_max));

        $games = $query->orderBy('date_time')->paginate(12);

        return view('livewire.games.game-listing', [
            'games' => $games,
            'gameSystems' => \App\Models\GameSystem::orderBy('name')->get(['id', 'name']),
            'experienceLevels' => ExperienceLevel::cases(),
            'vibeFlagGroups' => VibeFlag::grouped(),
            'languages' => ContentLanguage::cases(),
        ]);
    }
}
