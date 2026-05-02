<?php

namespace App\Livewire\Discovery;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\PlayStyle;
use App\Enums\SafetyTool;
use App\Enums\VibeFlag;
use App\Models\GameSystemCategory;
use App\Traits\EscapesLikeWildcards;
use App\Traits\HasGuestLocation;
use App\Traits\DiscoveryUtilities;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.public-layout')]
class AdventuresDiscovery extends Component
{
    use DiscoveryUtilities;
    use EscapesLikeWildcards;
    use HasGuestLocation;
    use WithPagination;

    // ── Shared filters ─────────────────────────────────

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?string $game_system_id = null;

    #[Url]
    public string $experience_level = '';

    #[Url]
    public array $vibe_flags = [];

    /** @var array<string, string|null> VibeFlag value → null|'favorite'|'avoid', for VibePreferencePicker */
    public array $vibePreferences = [];

    #[Url]
    public array $safety_tools = [];

    #[Url]
    public string $language = '';

    #[Url]
    public ?string $complexity_min = null;

    #[Url]
    public ?string $complexity_max = null;

    #[Url]
    public string $price = '';

    // ── Trait-required stub properties (DiscoveryUtilities accesses these) ──

    public string $date = '';

    public array $category_ids = [];

    public array $mechanic_ids = [];

    // ── Proximity filter ───────────────────────────────

    /** @var float Search radius in km (0 = no proximity filter) */
    #[Url(as: 'radius')]
    public float $radius = 0;

    /** @var bool Whether results came from the wider fallback radius */
    public bool $usingFallbackRadius = false;

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

    // ── Lifecycle ──────────────────────────────────────

    public function mount(): void
    {
        $user = Auth::user();
        if (!$this->language) {
            $this->language = ($user && $user->preferred_language)
                ? $user->preferred_language->value
                : app()->getLocale();
        }

        // Build vibePreferences from URL vibe_flags (all treated as favorites)
        foreach (VibeFlag::cases() as $flag) {
            if (in_array($flag->value, $this->vibe_flags, true)) {
                $this->vibePreferences[$flag->value] = 'favorite';
            } else {
                $this->vibePreferences[$flag->value] = null;
            }
        }

        // Pre-select vibe flags from user preferences (only if no URL values already set)
        if ($user && empty($this->vibe_flags)) {
            $resolvedVibes = $user->resolvedVibePreferences();
            if (!empty($resolvedVibes['favorites'])) {
                foreach ($resolvedVibes['favorites'] as $flagValue) {
                    $this->vibePreferences[$flagValue] = 'favorite';
                }
                $this->vibe_flags = $resolvedVibes['favorites'];
            }
        }
    }

    // ── Updating hooks ─────────────────────────────────

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

    public function updatingSafetyTools(): void
    {
        $this->resetPage();
    }

    public function updatingLanguage(): void
    {
        $this->resetPage();
    }

    public function updatingPrice(): void
    {
        $this->resetPage();
    }

    public function updatingRadius(): void
    {
        $this->resetPage();
    }

    public function updatingPlayStyles(): void
    {
        $this->resetPage();
    }

    public function updatingSessionType(): void
    {
        $this->resetPage();
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
        $this->resetPage();
    }

    public function setSessionType(string $type): void
    {
        if (!in_array($type, ['', 'campaign', 'oneshot'], true)) {
            return;
        }
        $this->session_type = $type;
        $this->resetPage();
    }

    public function toggleSessionZero(): void
    {
        $this->session_zero = !$this->session_zero;
        $this->resetPage();
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
        $this->resetPage();
    }

    public function setRadius(float $radius): void
    {
        if ($radius != 0 && !in_array($radius, self::RADIUS_OPTIONS, false)) {
            return;
        }
        $this->radius = $radius;
        $this->usingFallbackRadius = false;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'game_system_id', 'experience_level', 'vibe_flags',
            'safety_tools', 'language', 'price', 'complexity_min', 'complexity_max',
            'play_styles', 'session_type', 'session_zero', 'radius',
        ]);
        $this->usingFallbackRadius = false;
        // Reset vibe preferences to neutral
        foreach (VibeFlag::cases() as $flag) {
            $this->vibePreferences[$flag->value] = null;
        }
        $this->resetPage();
    }

    // ── Picker event listeners ─────────────────────────

    #[On('value-updated')]
    public function onGameSystemUpdated($value): void
    {
        $this->game_system_id = $value;
        $this->resetPage();
    }

    #[On('vibe-preferences-changed')]
    public function onVibePreferencesChanged(array $preferences): void
    {
        $this->vibePreferences = $preferences;
        // Extract only favorites for the query filter
        $this->vibe_flags = collect($preferences)
            ->filter(fn ($value) => $value === 'favorite')
            ->keys()
            ->values()
            ->all();
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search
            || $this->game_system_id
            || $this->experience_level
            || !empty($this->vibe_flags)
            || !empty($this->safety_tools)
            || ($this->language && $this->language !== app()->getLocale())
            || $this->price
            || $this->complexity_min
            || $this->complexity_max
            || !empty($this->play_styles)
            || $this->session_type
            || $this->session_zero
            || $this->radius > 0;
    }

    // ── Render ─────────────────────────────────────────

    public function render()
    {
        // Build base queries from trait, then scope to TTRPG systems
        $campaignsQuery = $this->buildCampaignsQuery()
            ->whereHas('gameSystem', fn ($q) => $q->where('type', 'ttrpg'));

        $gamesQuery = $this->buildGamesQuery()
            ->whereHas('gameSystem', fn ($q) => $q->where('type', 'ttrpg'));

        // Apply session_type filter
        if ($this->session_type === 'campaign') {
            $gamesQuery->whereNotNull('campaign_id');
        } elseif ($this->session_type === 'oneshot') {
            $gamesQuery->whereNull('campaign_id');
            $campaignsQuery->whereRaw('1 = 0'); // Exclude campaigns
        }

        // Apply session_zero filter: items that include session-zero in safety_rules
        if ($this->session_zero) {
            $gamesQuery->whereJsonContains('safety_rules->tools', 'session-zero');
            $campaignsQuery->whereJsonContains('safety_rules->tools', 'session-zero');
        }

        // Apply play_styles filter: match games/campaigns whose game system has
        // categories matching the selected PlayStyle editorial slug mappings
        if (!empty($this->play_styles)) {
            $slugs = collect($this->play_styles)
                ->map(fn (string $value) => PlayStyle::tryFrom($value))
                ->filter()
                ->flatMap(fn (PlayStyle $style) => $style->categorySlugs())
                ->unique()
                ->values()
                ->all();

            if (!empty($slugs)) {
                $gamesQuery->whereHas('gameSystem.categories', fn ($q) => $q->whereIn('slug', $slugs));
                $campaignsQuery->whereHas('gameSystem.categories', fn ($q) => $q->whereIn('slug', $slugs));
            }
        }

        // Campaigns boosted (sorted first), then games by date
        $perPage = 12;
        $page = (int) request()->get('page', 1);

        $campaigns = $campaignsQuery->get()->each(fn ($item) => [
            $item->discoverable_type = 'campaign',
            $item->discoverable_sort_key = PHP_INT_MAX - ($item->created_at?->timestamp ?? 0),
        ]);

        $games = $gamesQuery->get()->each(fn ($item) => [
            $item->discoverable_type = 'game',
            $item->discoverable_sort_key = $item->date_time?->timestamp ?? 0,
        ]);

        $merged = $campaigns->merge($games);

        // Apply proximity filtering if radius is set
        if ($this->radius > 0 && $this->hasGuestLocation()) {
            $merged = $this->applyProximityFilter($merged);
        } else {
            $merged = $merged->sortByDesc('discoverable_sort_key')->values();
        }

        $total = $merged->count();
        $items = $merged->slice(($page - 1) * $perPage, $perPage)->values();

        $results = new \Illuminate\Pagination\LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);

        // Log TTRPG-specific filter usage for adoption tracking
        $this->logFilterUsage();

        // Cross-track hint: count upcoming public board game sessions
        $boardGameSessionCount = \App\Models\Game::where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->where('visibility', 'public')
            ->whereHas('gameSystem', fn ($q) => $q->where('type', 'boardgame'))
            ->count();

        return view('livewire.discovery.adventures-discovery', [
            'results' => $results,
            'recommendations' => $this->getRecommendations('ttrpg'),
            'experienceLevels' => ExperienceLevel::cases(),
            'safetyToolGroups' => SafetyTool::grouped(),
            'languages' => ContentLanguage::cases(),
            'curatedCategories' => $this->getCuratedCategories('ttrpg'),
            'playStyleGroups' => $this->getPlayStyleGroups(),
            'radiusOptions' => self::RADIUS_OPTIONS,
            'hasLocation' => $this->hasGuestLocation(),
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
        if (!empty($this->play_styles) || !empty($this->session_type) || $this->session_zero) {
            Log::info('TTRPG discovery filters used', [
                'page' => 'adventures',
                'play_styles' => $this->play_styles,
                'session_type' => $this->session_type,
                'session_zero' => $this->session_zero,
            ]);
        }
    }
}
