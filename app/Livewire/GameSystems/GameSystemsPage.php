<?php

namespace App\Livewire\GameSystems;

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.public-layout')]
class GameSystemsPage extends Component
{
    use EscapesLikeWildcards;
    use WithPagination;

    // ── Filters ────────────────────────────────────────

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $min_players = null;

    #[Url]
    public ?int $max_players = null;

    #[Url]
    public ?string $complexity_min = null;

    #[Url]
    public ?string $complexity_max = null;

    #[Url]
    public ?int $category_id = null;

    #[Url]
    public ?int $mechanic_id = null;

    // ── Pagination ─────────────────────────────────────

    protected const PER_PAGE = 24;

    // ── Updating hooks (reset page on filter change) ───

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingMinPlayers(): void
    {
        $this->resetPage();
    }

    public function updatingMaxPlayers(): void
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

    public function updatingCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatingMechanicId(): void
    {
        $this->resetPage();
    }

    // ── Actions ────────────────────────────────────────

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'min_players', 'max_players',
            'complexity_min', 'complexity_max',
            'category_id', 'mechanic_id',
        ]);
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search
            || $this->min_players
            || $this->max_players
            || $this->complexity_min
            || $this->complexity_max
            || $this->category_id
            || $this->mechanic_id;
    }

    // ── Query ──────────────────────────────────────────

    protected function buildQuery()
    {
        $query = GameSystem::query()
            ->with(['categories', 'mechanics'])
            ->withCount(['games as active_sessions_count' => function ($q) {
                $q->where('status', 'scheduled')
                  ->where('date_time', '>', now())
                  ->where(function ($q2) {
                      $q2->where('visibility', 'public')
                         ->orWhere('visibility', 'protected');
                  });
            }]);

        // Search by name
        $query->when($this->search, fn ($q) => $q->where(function ($q) {
            $escaped = $this->escapeLikeWildcards($this->search);
            $q->where('name', 'like', "%{$escaped}%")
              ->orWhere('description', 'like', "%{$escaped}%");
        }));

        // Player count range
        $query->when($this->min_players, fn ($q) => $q->where('max_players', '>=', $this->min_players));
        $query->when($this->max_players, fn ($q) => $q->where('min_players', '<=', $this->max_players));

        // Complexity (BGG weight) range
        $query->when($this->complexity_min, fn ($q) => $q->where('bgg_average_weight', '>=', (float) $this->complexity_min));
        $query->when($this->complexity_max, fn ($q) => $q->where('bgg_average_weight', '<=', (float) $this->complexity_max));

        // Category filter
        $query->when($this->category_id, fn ($q) => $q->whereHas('categories', fn ($q2) => $q2->where('game_system_categories.id', $this->category_id)));

        // Mechanic filter
        $query->when($this->mechanic_id, fn ($q) => $q->whereHas('mechanics', fn ($q2) => $q2->where('game_system_mechanics.id', $this->mechanic_id)));

        // Sort by BGG rank (nulls last)
        $query->orderByRaw('CASE WHEN bgg_rank IS NOT NULL THEN 0 ELSE 1 END')
              ->orderBy('bgg_rank', 'asc')
              ->orderBy('name', 'asc');

        return $query;
    }

    // ── Render ─────────────────────────────────────────

    public function render()
    {
        $systems = $this->buildQuery()->paginate(self::PER_PAGE);

        return view('livewire.game-systems.game-systems-page', [
            'systems' => $systems,
            'categories' => GameSystemCategory::orderBy('name')->get(['id', 'name']),
            'mechanics' => GameSystemMechanic::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
