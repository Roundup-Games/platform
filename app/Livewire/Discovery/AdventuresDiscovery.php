<?php

namespace App\Livewire\Discovery;

use App\Dto\DiscoveryFilters;
use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\PlayStyle;
use App\Enums\SafetyTool;
use App\Models\Game;
use App\Services\DiscoveryQueryService;
use App\Traits\HasGuestLocation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RalphJSmit\Laravel\SEO\Support\SEOData;

#[Layout('components.public-layout')]
class AdventuresDiscovery extends Component
{
    use HasGuestLocation;
    use ManagesDiscoveryFilters;

    // ── Shared filters (safety_tools differs per page) ──

    /** @var array<int, string> */
    #[Url]
    public array $safety_tools = [];

    // ── Adventures-specific filters ─────────────────────

    /** @var string[] Active play style vibe flags */
    #[Url]
    public array $play_styles = [];

    /** @var string '' = all, 'campaign' = campaigns only, 'oneshot' = one-shot games only */
    #[Url]
    public string $session_type = '';

    /** @var bool Filter for items that include session zero in safety_rules */
    #[Url]
    public bool $session_zero = false;

    // ── Page-specific updating hooks ────────────────────

    public int $displayCount = 12;

    public function updatingSafetyTools(): void
    {
        $this->displayCount = 12;
    }

    public function updatingPlayStyles(): void
    {
        $this->displayCount = 12;
    }

    public function updatingSessionType(): void
    {
        $this->displayCount = 12;
    }

    public function loadMore(): void
    {
        $this->displayCount += 12;
    }

    // ── Actions ────────────────────────────────────────

    public function togglePlayStyle(string $style): void
    {
        $this->play_styles = DiscoveryFilters::toggleArrayValue($this->play_styles, $style);
        $this->displayCount = 12;
    }

    public function setSessionType(string $type): void
    {
        if (! in_array($type, ['', 'campaign', 'oneshot'], true)) {
            return;
        }
        $this->session_type = $type;
        $this->displayCount = 12;
    }

    public function toggleSessionZero(): void
    {
        $this->session_zero = ! $this->session_zero;
        $this->displayCount = 12;
    }

    public function toggleSafetyTool(string $tool): void
    {
        $this->safety_tools = DiscoveryFilters::toggleArrayValue($this->safety_tools, $tool);
        $this->displayCount = 12;
    }

    /**
     * Page-specific filter surface: shared base + TTRPG extras (safety
     * tools, play styles, session type/session zero) + city.
     *
     * @return array<int, string>
     */
    protected function discoveryFilterSurface(): array
    {
        return DiscoveryFilters::SURFACE_ADVENTURES;
    }

    // ── Render ─────────────────────────────────────────

    public function render(): View
    {
        // ?city= folds the canonical to the city's hub when it resolves to a
        // qualifying cluster; null keeps the transformer's path defaults.
        [$cityCanonical, $cityAlternates] = $this->citySeo();

        seo(new SEOData(
            title: __('discovery.seo_title_browse_adventures'),
            description: __('discovery.seo_description_browse_adventures'),
            canonical_url: $cityCanonical,
            alternates: $cityAlternates,
        ));

        $service = app(DiscoveryQueryService::class);
        $user = Auth::user();

        // Browser guest location, else the user's saved location (shared resolver)
        [$lat, $lng, $hasLocation] = $this->resolveSearchOrigin();

        $filters = DiscoveryFilters::fromLivewire($this);

        // The service owns the TTRPG merge pipeline end to end — system-type
        // scoping, the session_type/session_zero/play_styles constraints,
        // merge ordering (campaigns pinned first), the gathering cap, distance
        // enrichment and pagination (M072 S03). The component keeps props,
        // actions and loadMore only.
        $feed = $service->results(DiscoveryQueryService::MODE_ADVENTURES, $filters, $user, $this->radius, $lat, $lng, $hasLocation, $this->displayCount);
        $results = $feed['results'];
        $this->usingFallbackRadius = $feed['usingFallback'];

        // Log TTRPG-specific filter usage for adoption tracking
        $this->logFilterUsage();

        // Cross-track hint: count upcoming public board game sessions
        $boardGameSessionCount = Game::where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->visibleTo(null)
            ->whereHas('gameSystems', fn ($q) => $q->where('type', 'boardgame'))
            ->count();

        $curated = $service->curated(DiscoveryQueryService::MODE_ADVENTURES);

        return view('livewire.discovery.adventures-discovery', [
            'results' => $results,
            'recommendations' => $service->getRecommendations($user, 'ttrpg'),
            'experienceLevels' => ExperienceLevel::cases(),
            'safetyToolGroups' => SafetyTool::grouped(),
            'languages' => ContentLanguage::cases(),
            'curatedCategories' => $curated['categories'],
            'playStyleGroups' => $this->getPlayStyleGroups(),
            'radiusOptions' => DiscoveryQueryService::RADIUS_OPTIONS,
            'hasLocation' => $hasLocation,
            'boardGameSessionCount' => $boardGameSessionCount,
        ]);
    }

    // ── TTRPG-specific helpers ─────────────────────────

    /**
     * Get play style groups for the TTRPG filter surface.
     *
     * Uses the PlayStyle enum with 5 editorial groupings
     * (Narrative-first, Tactical, OSR, Sandbox, Horror),
     * each with label, icon, and hover description.
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

    /**
     * Log TTRPG-specific filter usage for understanding adoption patterns.
     */
    protected function logFilterUsage(): void
    {
        if (! empty($this->play_styles) || ! empty($this->session_type) || $this->session_zero) {
            Log::info('TTRPG discovery filters used', [
                'page' => 'adventures',
                'play_styles' => $this->play_styles,
                'session_type' => $this->session_type,
                'session_zero' => $this->session_zero,
            ]);
        }
    }
}
