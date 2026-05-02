<?php

namespace App\Livewire\GameSystems;

use App\Enums\PlayStyle;
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

    #[Url]
    public string $type = 'all'; // 'all', 'boardgame', 'ttrpg'

    /** @var string[] Active play style enum values (only used in TTRPG mode) */
    #[Url]
    public array $play_styles = [];

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

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingPlayStyles(): void
    {
        $this->resetPage();
    }

    // ── Type toggle ────────────────────────────────────

    public function setType(string $type): void
    {
        $this->type = $type;
        $this->resetPage();
    }

    // ── Chip toggle actions ────────────────────────────

    public function toggleCategory(string $categoryId): void
    {
        $key = array_search($categoryId, $this->category_ids, true);
        if ($key !== false) {
            unset($this->category_ids[$key]);
            $this->category_ids = array_values($this->category_ids);
        } else {
            $this->category_ids[] = $categoryId;
        }
        $this->resetPage();
    }

    public function toggleMechanic(string $mechanicId): void
    {
        $key = array_search($mechanicId, $this->mechanic_ids, true);
        if ($key !== false) {
            unset($this->mechanic_ids[$key]);
            $this->mechanic_ids = array_values($this->mechanic_ids);
        } else {
            $this->mechanic_ids[] = $mechanicId;
        }
        $this->resetPage();
    }

    public function togglePlayStyle(string $style): void
    {
        $index = array_search($style, $this->play_styles, true);
        if ($index !== false) {
            unset($this->play_styles[$index]);
            $this->play_styles = array_values($this->play_styles);
        } else {
            $this->play_styles[] = $style;
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
            'showExpansions', 'type', 'play_styles',
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
            || $this->showExpansions
            || $this->type !== 'all'
            || ! empty($this->play_styles);
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
            $op = $this->likeOperator();
            $q->where('name', $op, "%{$escaped}%")
              ->orWhere('description', $op, "%{$escaped}%");
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

        // Play style filter: map selected PlayStyle enum values to category slugs
        if (!empty($this->play_styles)) {
            $slugs = collect($this->play_styles)
                ->map(fn (string $value) => PlayStyle::tryFrom($value))
                ->filter()
                ->flatMap(fn (PlayStyle $style) => $style->categorySlugs())
                ->unique()
                ->values()
                ->all();

            if (!empty($slugs)) {
                $query->whereHas('categories', fn ($q) => $q->whereIn('slug', $slugs));
            }
        }

        // Type filter
        $query->when($this->type !== 'all', fn ($q) => $q->where('type', $this->type));

        // Sort by platform_score DESC (cold-start systems with 0 fall to bottom), then name ASC
        $query->orderByDesc('platform_score')
              ->orderBy('name', 'asc');

        return $query;
    }

    // ── Render ─────────────────────────────────────────

    public function render()
    {
        $systems = $this->buildQuery()->paginate(self::PER_PAGE);

        // Load categories with game count for filter display, scoped to selected type
        $categoryQuery = GameSystemCategory::query()
            ->withCount(['gameSystems' => function ($q) {
                if ($this->type !== 'all') {
                    $q->where('type', $this->type);
                }
            }])
            ->orderByDesc('game_systems_count')
            ->orderBy('name');

        $allCategories = $categoryQuery->get();

        $mechanicQuery = GameSystemMechanic::query()
            ->withCount(['gameSystems' => function ($q) {
                if ($this->type !== 'all') {
                    $q->where('type', $this->type);
                }
            }])
            ->orderByDesc('game_systems_count')
            ->orderBy('name');

        $allMechanics = $mechanicQuery->get();

        return view('livewire.game-systems.game-systems-page', [
            'systems' => $systems,
            'allCategories' => $allCategories,
            'allMechanics' => $allMechanics,
            'visibleCategories' => $this->showAllCategories ? $allCategories : $allCategories->take(12),
            'visibleMechanics' => $this->showAllMechanics ? $allMechanics : $allMechanics->take(12),
            'playStyleGroups' => $this->getPlayStyleGroups(),
        ]);
    }

    /**
     * Get play style groups from the PlayStyle enum for TTRPG mode filtering.
     *
     * @return array<string, array{label: string, options: array<string, string>, descriptions: array<string, string>, icons: array<string, string>}>
     */
    protected function getPlayStyleGroups(): array
    {
        $groups = PlayStyle::grouped();

        // Enrich with icon data for the chip UI
        foreach ($groups as $key => &$group) {
            $icons = [];
            foreach (PlayStyle::cases() as $style) {
                $icons[$style->value] = $style->icon();
            }
            $group['icons'] = $icons;
        }

        return $groups;
    }
}
