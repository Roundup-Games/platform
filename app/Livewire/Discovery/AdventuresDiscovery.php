<?php

namespace App\Livewire\Discovery;

use App\Dto\DiscoveryFilters;
use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\PlayStyle;
use App\Enums\SafetyTool;
use App\Enums\VibeFlag;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\Location;
use App\Services\DiscoveryQueryService;
use App\Traits\HasGuestLocation;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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
        $index = array_search($style, $this->play_styles, true);
        if ($index !== false) {
            unset($this->play_styles[$index]);
            $this->play_styles = array_values($this->play_styles);
        } else {
            $this->play_styles[] = $style;
        }
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
        $index = array_search($tool, $this->safety_tools, true);
        if ($index !== false) {
            unset($this->safety_tools[$index]);
            $this->safety_tools = array_values($this->safety_tools);
        } else {
            $this->safety_tools[] = $tool;
        }
        $this->displayCount = 12;
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'game_system_id', 'experience_level', 'vibe_flags',
            'safety_tools', 'language', 'price', 'complexity_min', 'complexity_max',
            'play_styles', 'session_type', 'session_zero', 'radius',
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
            || ! empty($this->safety_tools)
            || ($this->language && $this->language !== app()->getLocale())
            || $this->price
            || $this->complexity_min
            || $this->complexity_max
            || ! empty($this->play_styles)
            || $this->session_type
            || $this->session_zero
            || $this->radius > 0;
    }

    // ── Render ─────────────────────────────────────────

    public function render(): View
    {
        seo(new SEOData(
            title: __('discovery.seo_title_browse_adventures'),
            description: __('discovery.seo_description_browse_adventures'),
        ));

        $service = app(DiscoveryQueryService::class);
        $user = Auth::user();
        $hasLocation = $this->hasGuestLocation();
        $lat = $this->guestLat;
        $lng = $this->guestLng;

        // Fallback to the logged-in user's saved location when browser geolocation is unavailable
        if (! $hasLocation && $user?->location_id) {
            $userLocation = Location::find($user->location_id);
            if ($userLocation) {
                $lat = (float) $userLocation->latitude;
                $lng = (float) $userLocation->longitude;
                $hasLocation = true;
            }
        }

        $filters = DiscoveryFilters::fromLivewire($this);

        // Build base queries via service, then scope to TTRPG systems
        $campaignsQuery = $service->buildCampaignsQuery(
            $filters, $user, $this->radius, $lat, $lng, $hasLocation, null,
        )->whereHas('gameSystems', fn ($q) => $q->where('type', 'ttrpg'));

        $gamesQuery = $service->buildGamesQuery(
            $filters, $user, $this->radius, $lat, $lng, $hasLocation, null,
        );
        $service->applySystemTypeScope($gamesQuery, 'ttrpg');

        // Apply session_type filter
        if ($this->session_type === 'campaign') {
            // Campaign Sessions tab: show only games that belong to a campaign
            $gamesQuery->whereNotNull('campaign_id');
            $campaignsQuery->whereRaw('1 = 0'); // No separate campaign entities in this tab
        } elseif ($this->session_type === 'oneshot') {
            // One-shots tab: show only standalone games
            $gamesQuery->whereNull('campaign_id');
            $campaignsQuery->whereRaw('1 = 0'); // Exclude campaign entities
        }

        // Apply session_zero filter: find games that ARE session zeros
        // (safety tool flag OR name indicates it IS a session zero)
        if ($this->session_zero) {
            $nameIsSessionZero = "(name->>'en' ~* '^(session\s*zero|session\s*0)' OR name->>'de' ~* '^(session\s*zero|session\s*0)')";

            $gamesQuery->where(function ($q) use ($nameIsSessionZero) {
                $q->whereJsonContains('safety_rules->tools', 'session-zero')
                    ->orWhereRaw($nameIsSessionZero);
            });

            // Session zero filter: show game sessions only, not campaign entities
            $campaignsQuery->whereRaw('1 = 0');
        }

        // Apply play_styles filter: match games/campaigns whose game system has
        // categories matching the selected PlayStyle editorial slug mappings
        if (! empty($this->play_styles)) {
            $slugs = collect($this->play_styles)
                ->map(fn (string $value) => PlayStyle::tryFrom($value))
                ->filter()
                ->flatMap(fn (PlayStyle $style) => $style->categorySlugs())
                ->unique()
                ->values()
                ->all();

            if (! empty($slugs)) {
                $gamesQuery->whereHas('gameSystems.categories', fn ($q) => $q->whereIn('slug', $slugs));
                $campaignsQuery->whereHas('gameSystems.categories', fn ($q) => $q->whereIn('slug', $slugs));
            }
        }

        // Campaigns boosted (sorted first), then games by date
        $perPage = $this->displayCount;

        $campaigns = $campaignsQuery->get()->each(function ($item) use ($service) {
            $item->discoverable_type = 'campaign';
            $item->discoverable_sort_key = PHP_INT_MAX - (int) ($item->created_at->timestamp ?? 0);
            // Pre-compute the Gathering tiebreak so the merged sort can use a
            // uniform string-key shape (phpstan rejects sortBy() entries that
            // mix [string,dir] tuples with [Closure,dir] tuples). Mirrors
            // discoverable_sort_key: a per-item decoration, not a column.
            $item->discoverable_gathering_rank = $service->gatheringRankKey($item);
        });

        $games = $gamesQuery->get()->each(function ($item) use ($service) {
            $item->discoverable_type = 'game';
            $item->discoverable_sort_key = (int) ($item->date_time->timestamp ?? 0);
            $item->discoverable_gathering_rank = $service->gatheringRankKey($item);
        });

        /** @var Collection<int, Campaign|Game> $merged */
        $merged = collect()->merge($campaigns)->merge($games);

        // Apply proximity filtering if radius is set
        if ($this->radius > 0 && $hasLocation) {
            $result = $service->applyProximityFilter($merged, (float) $lat, (float) $lng, $this->radius);
            $merged = $result['collection'];
            $this->usingFallbackRadius = $result['usingFallback'];
        } else {
            // Stable multi-key sort: primary date desc (discoverable_sort_key),
            // then Gathering demotion asc (focused=0 before Gathering=1) per R048
            // gathering_relevance_penalty — a tiebreaker that only reorders items
            // within the same primary-key bucket. Both keys are pre-computed
            // decorations so the sortBy() call uses a uniform [string,dir] shape.
            $merged = $merged->sortBy([
                ['discoverable_sort_key', 'desc'],
                ['discoverable_gathering_rank', 'asc'],
            ])->values();
        }

        $total = $merged->count();
        $capped = $service->applyGatheringCap($merged, $this->displayCount);
        $items = $capped['items'];

        $results = new LengthAwarePaginator($items, $total, $this->displayCount, 1, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);

        // Log TTRPG-specific filter usage for adoption tracking
        $this->logFilterUsage();

        // Cross-track hint: count upcoming public board game sessions
        $boardGameSessionCount = Game::where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->visibleTo(null)
            ->whereHas('gameSystems', fn ($q) => $q->where('type', 'boardgame'))
            ->count();

        return view('livewire.discovery.adventures-discovery', [
            'results' => $results,
            'recommendations' => $service->getRecommendations($user, 'ttrpg'),
            'experienceLevels' => ExperienceLevel::cases(),
            'safetyToolGroups' => SafetyTool::grouped(),
            'languages' => ContentLanguage::cases(),
            'curatedCategories' => $service->getCuratedCategories('ttrpg'),
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
