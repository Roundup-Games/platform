<?php

namespace App\Livewire\Discovery;

use App\Dto\DiscoveryFilters;
use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Models\Campaign;
use App\Services\DiscoveryQueryService;
use App\Traits\HasGuestLocation;
use Illuminate\Contracts\View\View;
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
        $this->category_ids = DiscoveryFilters::toggleArrayValue($this->category_ids, $categoryId);
        $this->displayCount = 12;
    }

    public function toggleMechanic(string $mechanicId): void
    {
        $this->mechanic_ids = DiscoveryFilters::toggleArrayValue($this->mechanic_ids, $mechanicId);
        $this->displayCount = 12;
    }

    /**
     * Page-specific filter surface: shared base + board-game extras (date,
     * category, mechanic) + city. safety_tools is deliberately absent — the
     * board-game fork does not expose that filter.
     *
     * @return array<int, string>
     */
    protected function discoveryFilterSurface(): array
    {
        return DiscoveryFilters::SURFACE_BOARD_GAMES;
    }

    // ── Render ─────────────────────────────────────────

    public function render(): View
    {
        // ?city= folds the canonical to the city's hub when it resolves to a
        // qualifying cluster; null keeps the transformer's path defaults.
        [$cityCanonical, $cityAlternates] = $this->citySeo();

        seo(new SEOData(
            title: __('discovery.seo_title_browse_board_games'),
            description: __('discovery.seo_description_browse_board_games'),
            canonical_url: $cityCanonical,
            alternates: $cityAlternates,
        ));

        $service = app(DiscoveryQueryService::class);
        $user = Auth::user();
        $filters = DiscoveryFilters::fromLivewire($this);

        // Browser guest location, else the user's saved location (shared resolver)
        [$lat, $lng, $hasLocation] = $this->resolveSearchOrigin();

        // The service owns the boardgame pipeline end to end — boardgame
        // scoping, the cap-aware candidate fetch with Gathering backfill,
        // distance enrichment and pagination (M072 S03). The component keeps
        // props, actions and loadMore only.
        $feed = $service->results(DiscoveryQueryService::MODE_BOARD_GAMES, $filters, $user, $this->radius, $lat, $lng, $hasLocation, $this->displayCount);
        $results = $feed['results'];
        $this->usingFallbackRadius = $feed['usingFallback'];

        // Cross-track hint: count active public TTRPG campaigns
        $adventureCount = Campaign::where('status', 'active')
            ->visibleTo(null)
            ->whereHas('gameSystems', fn ($q) => $q->where('type', 'ttrpg'))
            ->count();

        $curated = $service->curated(DiscoveryQueryService::MODE_BOARD_GAMES);

        return view('livewire.discovery.board-games-discovery', [
            'results' => $results,
            'recommendations' => $service->getRecommendations($user, 'boardgame'),
            'experienceLevels' => ExperienceLevel::cases(),
            'languages' => ContentLanguage::cases(),
            'curatedCategories' => $curated['categories'],
            'curatedMechanics' => $curated['mechanics'],
            'radiusOptions' => DiscoveryQueryService::RADIUS_OPTIONS,
            'hasLocation' => $hasLocation,
            'adventureCount' => $adventureCount,
        ]);
    }
}
