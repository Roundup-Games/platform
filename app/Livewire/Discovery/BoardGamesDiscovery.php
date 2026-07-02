<?php

namespace App\Livewire\Discovery;

use App\Dto\DiscoveryFilters;
use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\Location;
use App\Models\User;
use App\Services\DiscoveryQueryService;
use App\Traits\HasGuestLocation;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RalphJSmit\Laravel\SEO\Support\SEOData;

#[Layout('components.public-layout')]
class BoardGamesDiscovery extends Component
{
    use HasGuestLocation;
    use ManagesDiscoveryFilters;

    // ── Shared filters (safety_tools kept empty; not exposed in board game UI) ──

    /** @var array<string> Safety tools — kept empty; not exposed in board game UI */
    public array $safety_tools = [];

    // ── Board-game-specific filters ─────────────────────

    /** @var array<int, int|string> */
    #[Url]
    public array $category_ids = [];

    /** @var array<int, int|string> */
    #[Url]
    public array $mechanic_ids = [];

    // ── Games-specific filters ─────────────────────────

    #[Url]
    public string $date = '';

    // ── Page-specific updating hooks ────────────────────

    public int $displayCount = 12;

    public function updatingDate(): void
    {
        $this->displayCount = 12;
    }

    public function updatingCategoryIds(): void
    {
        $this->displayCount = 12;
    }

    public function updatingMechanicIds(): void
    {
        $this->displayCount = 12;
    }

    public function loadMore(): void
    {
        $this->displayCount += 12;
    }

    // ── Actions ────────────────────────────────

    public function setDate(string $date): void
    {
        $this->date = $date;
        $this->displayCount = 12;
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
        $this->displayCount = 12;
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
        $this->displayCount = 12;
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'game_system_id', 'experience_level', 'vibe_flags',
            'language', 'price', 'complexity_min', 'complexity_max',
            'date', 'category_ids', 'mechanic_ids', 'radius',
        ]);
        $this->usingFallbackRadius = false;
        $this->displayCount = 12;
        // Reset vibe preferences to neutral
        foreach (VibeFlag::cases() as $flag) {
            $this->vibePreferences[$flag->value] = null;
        }
    }

    public function hasActiveFilters(): bool
    {
        return $this->search
            || $this->game_system_id
            || $this->experience_level
            || ! empty($this->vibe_flags)
            || ($this->language && $this->language !== app()->getLocale())
            || $this->price
            || $this->complexity_min
            || $this->complexity_max
            || $this->date
            || ! empty($this->category_ids)
            || ! empty($this->mechanic_ids)
            || $this->radius > 0;
    }

    // ── Render ─────────────────────────────────────────

    public function render(): View
    {
        seo(new SEOData(
            title: __('discovery.seo_title_browse_board_games'),
            description: __('discovery.seo_description_browse_board_games'),
        ));

        $service = app(DiscoveryQueryService::class);
        $user = Auth::user();
        $filters = DiscoveryFilters::fromLivewire($this);
        $hasLocation = $this->hasGuestLocation();
        $lat = $this->guestLat ?? null;
        $lng = $this->guestLng ?? null;

        // Fallback to the logged-in user's saved location when browser geolocation is unavailable
        if (! $hasLocation && $user?->location_id) {
            $userLocation = Location::find($user->location_id);
            if ($userLocation) {
                $lat = (float) $userLocation->latitude;
                $lng = (float) $userLocation->longitude;
                $hasLocation = true;
            }
        }

        $results = $this->getBoardGameResults($service, $filters, $user, $lat, $lng, $hasLocation);

        // Cross-track hint: count active public TTRPG campaigns
        $adventureCount = Campaign::where('status', 'active')
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
     *
     * R048: Gatherings are capped per feed page, so the true DB paginate is
     * replaced by a candidate fetch wide enough to backfill trimmed Gatherings
     * before building the paginator over the capped slice.
     *
     * @return LengthAwarePaginator<int, Game>
     */
    protected function getBoardGameResults(DiscoveryQueryService $service, DiscoveryFilters $filters, ?User $user, ?float $lat, ?float $lng, bool $hasLocation): LengthAwarePaginator
    {
        $query = $service->buildGamesQuery(
            $filters,
            $user,
            $this->radius,
            $lat,
            $lng,
            $hasLocation,
            $this->date,
        );
        $service->applySystemTypeScope($query, 'boardgame');

        // Candidate fetch: the window plus a small surplus of focused games so
        // trimmed Gathering slots can be backfilled. count() runs before take()
        // so the LIMIT never contaminates the aggregate.
        $maxPerSlice = $service->gatheringCapForWindow($this->displayCount);
        $total = $query->count();
        $candidates = $query->take($this->displayCount + $maxPerSlice * 2)->get()
            ->each(fn ($game) => $game->discoverable_type = 'game');

        $capped = $service->applyGatheringCap($candidates->toBase(), $this->displayCount, $maxPerSlice);
        $items = $capped['items'];

        if ($this->radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $service->enrichWithDistance($items, 'game', $lat, $lng, $this->radius, $this->usingFallbackRadius);
        }

        return new LengthAwarePaginator(
            $items,
            $total,
            $this->displayCount,
            LengthAwarePaginator::resolveCurrentPage(),
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }
}
