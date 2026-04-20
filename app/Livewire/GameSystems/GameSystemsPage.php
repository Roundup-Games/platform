<?php

namespace App\Livewire\GameSystems;

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
    public array $category_ids = [];

    #[Url]
    public array $mechanic_ids = [];

    #[Url]
    public ?int $min_players = null;

    #[Url]
    public ?int $max_players = null;

    #[Url]
    public ?string $complexity_min = null;

    #[Url]
    public ?string $complexity_max = null;

    #[Url]
    public bool $showExpansions = false;

    // ── Category / mechanic expansion state ────────────

    public bool $showAllCategories = false;

    public bool $showAllMechanics = false;

    // ── Pagination ─────────────────────────────────────

    protected const PER_PAGE = 24;

    // ── Updating hooks (reset page on filter change) ───

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingShowExpansions(): void
    {
        $this->resetPage();
    }

    // ── Chip toggle actions ────────────────────────────

    public function toggleCategory(int $categoryId): void
    {
        $key = array_search($categoryId, $this->category_ids);
        if ($key !== false) {
            unset($this->category_ids[$key]);
            $this->category_ids = array_values($this->category_ids);
        } else {
            $this->category_ids[] = $categoryId;
        }
        $this->resetPage();
    }

    public function toggleMechanic(int $mechanicId): void
    {
        $key = array_search($mechanicId, $this->mechanic_ids);
        if ($key !== false) {
            unset($this->mechanic_ids[$key]);
            $this->mechanic_ids = array_values($this->mechanic_ids);
        } else {
            $this->mechanic_ids[] = $mechanicId;
        }
        $this->resetPage();
    }

    // ── Actions ────────────────────────────────────────

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'min_players', 'max_players',
            'complexity_min', 'complexity_max',
            'category_ids', 'mechanic_ids',
            'showExpansions',
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
            || ! empty($this->category_ids)
            || ! empty($this->mechanic_ids)
            || $this->showExpansions;
    }

    // ── Query ──────────────────────────────────────────

    protected function buildQuery()
    {
        $query = GameSystem::query()
            ->with(['categories', 'mechanics'])
            ->withCount([
                'games as active_sessions_count' => function ($q) {
                    $q->where('status', 'scheduled')
                      ->where('date_time', '>', now())
                      ->where(function ($q2) {
                          $q2->where('visibility', 'public')
                             ->orWhere('visibility', 'protected');
                      });
                },
                'expansions as expansion_count',
            ]);

        // Base games only by default
        if (! $this->showExpansions) {
            $query->whereNull('base_game_id');
        }

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

        // Category filter (multi-select)
        $query->when($this->category_ids, fn ($q) => $q->whereHas('categories', fn ($q2) => $q2->whereIn('game_system_categories.id', $this->category_ids)));

        // Mechanic filter (multi-select)
        $query->when($this->mechanic_ids, fn ($q) => $q->whereHas('mechanics', fn ($q2) => $q2->whereIn('game_system_mechanics.id', $this->mechanic_ids)));

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

        // Load categories with game count for filter display
        $allCategories = GameSystemCategory::withCount('gameSystems')
            ->orderByDesc('game_systems_count')
            ->orderBy('name')
            ->get();

        $allMechanics = GameSystemMechanic::withCount('gameSystems')
            ->orderByDesc('game_systems_count')
            ->orderBy('name')
            ->get();

        return view('livewire.game-systems.game-systems-page', [
            'systems' => $systems,
            'allCategories' => $allCategories,
            'allMechanics' => $allMechanics,
            'visibleCategories' => $this->showAllCategories ? $allCategories : $allCategories->take(12),
            'visibleMechanics' => $this->showAllMechanics ? $allMechanics : $allMechanics->take(12),
        ]);
    }
}
