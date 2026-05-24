<?php

namespace App\Livewire\Discovery;

use App\Dto\DiscoveryFilters;
use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Services\DiscoveryQueryService;
use App\Traits\HasGuestLocation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.public-layout')]
class BoardGamesDiscovery extends Component
{
    use HasGuestLocation;
    use ManagesDiscoveryFilters;
    use WithPagination;

    // ── Shared filters (safety_tools kept empty; not exposed in board game UI) ──

    /** @var array<string> Safety tools — kept empty; not exposed in board game UI */
    public array $safety_tools = [];

    // ── Board-game-specific filters ─────────────────────

    #[Url]
    public array $category_ids = [];

    #[Url]
    public array $mechanic_ids = [];

    // ── Games-specific filters ─────────────────────────

    #[Url]
    public string $date = '';

    // ── Page-specific updating hooks ────────────────────

    public function updatingDate(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryIds(): void
    {
        $this->resetPage();
    }

    public function updatingMechanicIds(): void
    {
        $this->resetPage();
    }

    // ── Actions ────────────────────────────────

    public function setDate(string $date): void
    {
        $this->date = $date;
        $this->resetPage();
    }

    public function toggleCategory(string $categoryId): void
    {
        $index = array_search($categoryId, $this->category_ids, true);
        if ($index !== false) {
            unset($this->category_ids[$index]);
            $this->category_ids = array_values($this->category_ids);
        } else {
            $this->category_ids[] = $categoryId;
        }
        $this->resetPage();
    }

    public function toggleMechanic(string $mechanicId): void
    {
        $index = array_search($mechanicId, $this->mechanic_ids, true);
        if ($index !== false) {
            unset($this->mechanic_ids[$index]);
            $this->mechanic_ids = array_values($this->mechanic_ids);
        } else {
            $this->mechanic_ids[] = $mechanicId;
        }
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'game_system_id', 'experience_level', 'vibe_flags',
            'language', 'price', 'complexity_min', 'complexity_max',
            'date', 'category_ids', 'mechanic_ids', 'radius',
        ]);
        $this->usingFallbackRadius = false;
        // Reset vibe preferences to neutral
        foreach (VibeFlag::cases() as $flag) {
            $this->vibePreferences[$flag->value] = null;
        }
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search
            || $this->game_system_id
            || $this->experience_level
            || !empty($this->vibe_flags)
            || ($this->language && $this->language !== app()->getLocale())
            || $this->price
            || $this->complexity_min
            || $this->complexity_max
            || $this->date
            || !empty($this->category_ids)
            || !empty($this->mechanic_ids)
            || $this->radius > 0;
    }

    // ── Render ─────────────────────────────────────────

    public function render()
    {
        seo(new \RalphJSmit\Laravel\SEO\Support\SEOData(
            title: __('discovery.seo_title_browse_board_games'),
            description: __('discovery.seo_description_browse_board_games'),
        ));

        $service = app(DiscoveryQueryService::class);
        $user = Auth::user();
        $filters = DiscoveryFilters::fromLivewire($this);
        $hasLocation = $this->hasGuestLocation();
        $lat = $this->guestLat ?? null;
        $lng = $this->guestLng ?? null;

        $results = $this->getBoardGameResults($service, $filters, $user, $lat, $lng, $hasLocation);

        // Cross-track hint: count active public TTRPG campaigns
        $adventureCount = \App\Models\Campaign::where('status', 'active')
            ->visibleTo(null)
            ->whereHas('gameSystem', fn ($q) => $q->where('type', 'ttrpg'))
            ->count();

        return view('livewire.discovery.board-games-discovery', [
            'results' => $results,
            'recommendations' => $service->getRecommendations($user, 'boardgame'),
            'experienceLevels' => ExperienceLevel::cases(),
            'languages' => ContentLanguage::cases(),
            'curatedCategories' => $service->getCuratedCategories(),
            'curatedMechanics' => $service->getCuratedMechanics(),
            'radiusOptions' => DiscoveryQueryService::RADIUS_OPTIONS,
            'hasLocation' => $hasLocation,
            'adventureCount' => $adventureCount,
        ]);
    }

    /**
     * Build and paginate board-game-scoped results.
     *
     * Adds type=boardgame constraint so only board games appear
     * on the board games discovery page (no TTRPG bleed-through).
     */
    protected function getBoardGameResults(DiscoveryQueryService $service, DiscoveryFilters $filters, $user, ?float $lat, ?float $lng, bool $hasLocation)
    {
        $query = $service->buildGamesQuery(
            $filters->toArray(),
            $user,
            $this->radius,
            $lat,
            $lng,
            $hasLocation,
            $this->date,
        )->whereHas('gameSystem', fn ($q) => $q->where('type', 'boardgame'));

        $paginator = $query->paginate(12)->through(fn ($game) => tap($game, fn ($g) => $g->discoverable_type = 'game'));

        if ($this->radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $service->enrichWithDistance($paginator->getCollection(), 'game', $lat, $lng, $this->radius, $this->usingFallbackRadius);
        }

        return $paginator;
    }
}
