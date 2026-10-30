<?php

namespace App\Livewire\Discovery;

use App\Dto\DiscoveryFilters;
use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\Recurrence;
use App\Enums\SafetyTool;
use App\Services\DiscoveryQueryService;
use App\Services\PostHogAnalytics;
use App\Traits\HasGuestLocation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RalphJSmit\Laravel\SEO\Support\SEOData;

#[Layout('components.public-layout')]
class DiscoveryPage extends Component
{
    use HasGuestLocation;
    use ManagesDiscoveryFilters;

    // Mode
    #[Url]
    public string $mode = 'all';

    /**
     * Number of results shown on the first page of a discovery search.
     * Also the "is this the first page?" threshold for analytics dedup —
     * keep the dedup check coupled to this constant rather than a literal.
     */
    public const INITIAL_DISPLAY_COUNT = 12;

    // Load more
    public int $displayCount = self::INITIAL_DISPLAY_COUNT;

    // Page-specific filters
    /** @var array<int, int|string> */
    #[Url]
    public array $safety_tools = [];

    /** @var array<int, int|string> */
    #[Url]
    public array $category_ids = [];

    /** @var array<int, int|string> */
    #[Url]
    public array $mechanic_ids = [];

    // Entity-specific filters
    #[Url]
    public string $date = '';

    #[Url]
    public string $recurrence = '';

    // Updating hooks

    public function updating(): void
    {
        $this->displayCount = 12;
    }

    public function loadMore(): void
    {
        $this->displayCount += 12;
    }

    // Actions

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
        $this->displayCount = 12;
    }

    public function setDate(string $date): void
    {
        $this->date = $date;
        $this->displayCount = 12;
    }

    public function setRecurrence(string $recurrence): void
    {
        $this->recurrence = $recurrence;
        $this->displayCount = 12;
    }

    public function toggleSafetyTool(string $tool): void
    {
        $this->safety_tools = DiscoveryFilters::toggleArrayValue($this->safety_tools, $tool);
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
     * Page-specific filter surface: shared base + date/recurrence/category/
     * mechanic extras + city (city inclusion is the M072 drift fix — the
     * page previously missed ?city= in both clearFilters and hasActiveFilters).
     *
     * @return array<int, string>
     */
    protected function discoveryFilterSurface(): array
    {
        return DiscoveryFilters::SURFACE_DISCOVERY_PAGE;
    }

    // Render

    public function render(): View
    {
        $title = match ($this->mode) {
            'games' => __('discovery.seo_title_browse_games'),
            'campaigns' => __('discovery.seo_title_browse_campaigns'),
            default => __('discovery.seo_title_browse_all'),
        };

        seo(new SEOData(
            title: $title,
            description: __('discovery.seo_description_browse'),
        ));

        $service = app(DiscoveryQueryService::class);
        $user = Auth::user();
        $filters = DiscoveryFilters::fromLivewire($this);

        // Browser guest location, else the user's saved location (shared resolver)
        [$lat, $lng, $hasLocation] = $this->resolveSearchOrigin();

        // The service owns feed execution end to end (mode scoping, merge,
        // gathering cap, distance enrichment, pagination — M072 S03); the
        // component keeps props + loadMore.
        $feed = $service->results($this->mode, $filters, $user, $this->radius, $lat, $lng, $hasLocation, $this->displayCount);
        $results = $feed['results'];
        $this->usingFallbackRadius = $feed['usingFallback'];

        // Discovery intent analytics. Fired only on the first page of a filtered
        // search (displayCount === 12 dedupes loadMore expansions) with an active
        // filter set, for authenticated users. Zero-result searches are the
        // highest-value signal: pure unmet demand. Consent-gated via PostHogAnalytics.
        if ($user !== null && $this->displayCount === self::INITIAL_DISPLAY_COUNT && $this->hasUserSelectedFilters()) {
            $resultCount = is_countable($results) ? count($results) : 0;

            app(PostHogAnalytics::class)->capture(
                $user,
                'discovery.search',
                [
                    'mode' => $this->mode,
                    'result_count' => $resultCount,
                    'zero_results' => $resultCount === 0,
                    'has_radius' => $this->radius > 0,
                    'has_game_system' => filled($this->game_system_id),
                    'has_search_text' => filled($this->search),
                    'vibe_filter_count' => count($this->vibe_flags),
                    'category_filter_count' => count($this->category_ids),
                    'has_experience_level' => filled($this->experience_level),
                    'has_date' => filled($this->date),
                ],
            );
        }

        $curated = $service->curated($this->mode);

        return view('livewire.discovery.discovery-page', [
            'results' => $results,
            'recommendations' => $service->getRecommendations($user),
            'experienceLevels' => ExperienceLevel::cases(),
            'safetyToolGroups' => SafetyTool::grouped(),
            'languages' => ContentLanguage::cases(),
            'recurrenceOptions' => Recurrence::values(),
            'curatedCategories' => $curated['categories'],
            'curatedMechanics' => $curated['mechanics'],
            'radiusOptions' => DiscoveryQueryService::RADIUS_OPTIONS,
            'hasLocation' => $hasLocation,
            'activeFilters' => $this->hasActiveFilters(),
        ]);
    }
}
