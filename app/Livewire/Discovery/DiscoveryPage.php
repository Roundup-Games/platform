<?php

namespace App\Livewire\Discovery;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\SafetyTool;
use App\Enums\VibeFlag;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Services\ProximityQuery;
use App\Traits\EscapesLikeWildcards;
use App\Traits\HasGuestLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.public-layout')]
class DiscoveryPage extends Component
{
    use EscapesLikeWildcards;
    use HasGuestLocation;
    use WithPagination;

    // ── Proximity constants ────────────────────────────

    /** Available radius options in km */
    public const RADIUS_OPTIONS = [10, 25, 50];

    /** Fallback radius when primary radius returns empty */
    private const FALLBACK_RADIUS = 100;

    // ── Tab / mode filter ──────────────────────────────

    #[Url]
    public string $mode = 'all'; // 'all', 'games', 'campaigns'

    // ── Shared filters ─────────────────────────────────

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $game_system_id = null;

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

    #[Url]
    public array $category_ids = [];

    #[Url]
    public array $mechanic_ids = [];

    // ── Proximity filter ───────────────────────────────

    /** @var float Search radius in km (0 = no proximity filter) */
    #[Url(as: 'radius')]
    public float $radius = 0;

    /** @var bool Whether results came from the wider fallback radius */
    public bool $usingFallbackRadius = false;

    // ── Games-specific filters ─────────────────────────

    #[Url]
    public string $date = '';

    // ── Campaigns-specific filters ─────────────────────

    #[Url]
    public string $recurrence = '';

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

    public function updatingMode(): void
    {
        $this->resetPage();
    }

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

    public function updatingDate(): void
    {
        $this->resetPage();
    }

    public function updatingRecurrence(): void
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

    public function updatingRadius(): void
    {
        $this->resetPage();
    }

    // ── Actions ────────────────────────────────────────

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
        $this->resetPage();
    }

    public function setDate(string $date): void
    {
        $this->date = $date;
        $this->resetPage();
    }

    public function setRecurrence(string $recurrence): void
    {
        $this->recurrence = $recurrence;
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

    public function toggleCategory(int $categoryId): void
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

    public function toggleMechanic(int $mechanicId): void
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
            'safety_tools', 'language', 'price', 'complexity_min', 'complexity_max',
            'date', 'recurrence', 'category_ids', 'mechanic_ids', 'radius',
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
            || $this->date
            || $this->recurrence
            || !empty($this->category_ids)
            || !empty($this->mechanic_ids)
            || $this->radius > 0;
    }

    // ── Query builders ─────────────────────────────────

    protected function applySharedFilters($query, string $priceColumn): void
    {
        $query->when($this->search, fn ($q) => $q->where(function ($q) {
            $escaped = $this->escapeLikeWildcards($this->search);
            $q->where('name', $this->likeOperator(), "%{$escaped}%")
              ->orWhere('description', $this->likeOperator(), "%{$escaped}%");
        }));

        $query->when($this->game_system_id, fn ($q) => $q->where('game_system_id', $this->game_system_id));
        $query->when($this->experience_level, fn ($q) => $q->where('experience_level', $this->experience_level));

        $query->when(!empty($this->vibe_flags), function ($q) {
            foreach ($this->vibe_flags as $flag) {
                $q->whereJsonContains('vibe_flags', $flag);
            }
        });

        $query->when(!empty($this->safety_tools), function ($q) {
            foreach ($this->safety_tools as $tool) {
                $q->whereJsonContains('safety_rules->tools', $tool);
            }
        });

        $query->when($this->language, fn ($q) => $q->where('language', $this->language));

        $query->when($this->price === 'free', fn ($q) => $q->where(fn ($q) => $q->where($priceColumn, 0)->orWhereNull($priceColumn)));
        $query->when($this->price === 'paid', fn ($q) => $q->where($priceColumn, '>', 0));

        $query->when($this->complexity_min, fn ($q) => $q->where('complexity', '>=', (float) $this->complexity_min));
        $query->when($this->complexity_max, fn ($q) => $q->where('complexity', '<=', (float) $this->complexity_max));

        $query->when(!empty($this->category_ids), function ($q) {
            $q->whereHas('gameSystem.categories', fn ($q) => $q->whereIn('game_system_categories.id', $this->category_ids));
        });

        $query->when(!empty($this->mechanic_ids), function ($q) {
            $q->whereHas('gameSystem.mechanics', fn ($q) => $q->whereIn('game_system_mechanics.id', $this->mechanic_ids));
        });
    }

    protected function buildGamesQuery()
    {
        $user = Auth::user();

        $query = Game::query()
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public');
                if ($user) {
                    $q->orWhere('visibility', 'protected');
                }
            })
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->with(['owner', 'gameSystem', 'campaign'])
            ->withCount('participants');

        $this->applySharedFilters($query, 'price');

        // Games-specific: date range
        $query->when($this->date === 'upcoming', fn ($q) => $q->where('date_time', '>=', now()));
        $query->when($this->date === 'this_week', fn ($q) => $q->whereBetween('date_time', [now()->startOfWeek(), now()->endOfWeek()]));
        $query->when($this->date === 'this_month', fn ($q) => $q->whereBetween('date_time', [now()->startOfMonth(), now()->endOfMonth()]));

        // When radius > 0 with location, apply proximity sub-filter
        if ($this->radius > 0 && $this->hasGuestLocation()) {
            $this->applyProximitySubquery($query, 'games');
        }

        return $query->orderBy('date_time');
    }

    protected function buildCampaignsQuery()
    {
        $user = Auth::user();

        $query = Campaign::query()
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public');
                if ($user) {
                    $q->orWhere('visibility', 'protected');
                }
            })
            ->where('status', 'active')
            ->with(['owner', 'gameSystem'])
            ->withCount('sessions');

        $this->applySharedFilters($query, 'price_per_session');

        // Campaigns-specific: recurrence
        $query->when($this->recurrence, fn ($q) => $q->where('recurrence', $this->recurrence));

        // When radius > 0 with location, apply proximity sub-filter via campaign sessions
        if ($this->radius > 0 && $this->hasGuestLocation()) {
            $this->applyCampaignProximitySubquery($query);
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Apply proximity subquery to a games query builder.
     * Filters to games whose linked location falls within the current radius.
     */
    protected function applyProximitySubquery($query, string $table): void
    {
        $proximity = app(ProximityQuery::class);
        $bounds = $proximity->boundingBox($this->guestLat, $this->guestLng, $this->radius);

        $query->whereHas('linkedLocation', function ($q) use ($bounds) {
            $q->whereNotNull('latitude')
              ->whereNotNull('longitude')
              ->whereBetween('latitude', [$bounds['minLat'], $bounds['maxLat']])
              ->whereBetween('longitude', [$bounds['minLng'], $bounds['maxLng']]);
        });
    }

    /**
     * Apply proximity subquery to a campaigns query builder.
     * Filters to campaigns that have at least one scheduled session within the radius.
     */
    protected function applyCampaignProximitySubquery($query): void
    {
        $proximity = app(ProximityQuery::class);

        // Get nearby game IDs within radius
        $nearbyResults = $proximity->nearby(
            $this->guestLat,
            $this->guestLng,
            $this->radius,
            'game',
            ['limit' => 200, 'status_filter' => false],
        );

        $nearbyCampaignIds = $nearbyResults
            ->filter(fn ($r) => $r->entity->campaign_id !== null)
            ->pluck('entity.campaign_id')
            ->unique()
            ->values()
            ->toArray();

        $query->whereIn('id', $nearbyCampaignIds);
    }

    /**
     * Merge games and campaigns into a unified, paginated collection.
     * Each item gets a ->discoverable_type attribute ('game' or 'campaign')
     * and a ->discoverable_sort_key for consistent ordering.
     *
     * When radius > 0 and guest location is available, results are filtered
     * by proximity and sorted by distance instead of timestamp.
     */
    protected function getMergedResults(): Paginator
    {
        $perPage = 12;
        $page = (int) request()->get('page', 1);

        $gamesQuery = $this->buildGamesQuery();
        $campaignsQuery = $this->buildCampaignsQuery();

        // Add type discriminator and unified sort key
        $games = $gamesQuery->get()->each(fn ($item) => [
            $item->discoverable_type = 'game',
            $item->discoverable_sort_key = $item->date_time?->timestamp ?? 0,
        ]);

        $campaigns = $campaignsQuery->get()->each(fn ($item) => [
            $item->discoverable_type = 'campaign',
            $item->discoverable_sort_key = $item->created_at?->timestamp ?? 0,
        ]);

        // Merge and sort: games (by date_time) first, then campaigns (by created_at)
        $merged = $games->merge($campaigns);

        // ── Proximity filtering & distance sorting ──
        if ($this->radius > 0 && $this->hasGuestLocation()) {
            $merged = $this->applyProximityFilter($merged);
        } else {
            $merged = $merged->sortByDesc('discoverable_sort_key')->values();
        }

        $total = $merged->count();
        $items = $merged->slice(($page - 1) * $perPage, $perPage)->values();

        return new Paginator($items, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /**
     * Apply proximity filtering to merged results.
     *
     * Uses ProximityQuery to get nearby games, then intersects with the
     * already-filtered results. Items without a location match are removed.
     * If no results match within the selected radius, falls back to 100km.
     *
     * Each remaining item gets a ->distance_km attribute for display.
     */
    protected function applyProximityFilter($merged): \Illuminate\Support\Collection
    {
        $proximity = app(ProximityQuery::class);
        $this->usingFallbackRadius = false;

        // Get distance maps for both entity types
        $gameDistances = $this->getProximityDistances($proximity, 'game', $this->radius);
        $campaignDistances = $this->getProximityCampaignDistances($proximity, $this->radius);

        // Tag items with distance_km, filter out those without a match
        $filtered = $merged->filter(function ($item) use ($gameDistances, $campaignDistances) {
            if ($item->discoverable_type === 'game') {
                return isset($gameDistances[$item->id]);
            }
            return isset($campaignDistances[$item->id]);
        })->map(function ($item) use ($gameDistances, $campaignDistances) {
            if ($item->discoverable_type === 'game') {
                $item->distance_km = $gameDistances[$item->id];
            } else {
                $item->distance_km = $campaignDistances[$item->id];
            }
            return $item;
        });

        // Fallback to wider radius when primary returns empty
        if ($filtered->isEmpty()) {
            $this->usingFallbackRadius = true;
            $gameDistances = $this->getProximityDistances($proximity, 'game', self::FALLBACK_RADIUS);
            $campaignDistances = $this->getProximityCampaignDistances($proximity, self::FALLBACK_RADIUS);

            $filtered = $merged->filter(function ($item) use ($gameDistances, $campaignDistances) {
                if ($item->discoverable_type === 'game') {
                    return isset($gameDistances[$item->id]);
                }
                return isset($campaignDistances[$item->id]);
            })->map(function ($item) use ($gameDistances, $campaignDistances) {
                if ($item->discoverable_type === 'game') {
                    $item->distance_km = $gameDistances[$item->id];
                } else {
                    $item->distance_km = $campaignDistances[$item->id];
                }
                return $item;
            });
        }

        // Sort by distance ascending
        return $filtered->sortBy('distance_km')->values();
    }

    /**
     * Get a distance map [entity_id => distance_km] from ProximityQuery for a given entity type.
     */
    protected function getProximityDistances(ProximityQuery $proximity, string $entityType, float $radiusKm): array
    {
        $results = $proximity->nearby(
            $this->guestLat,
            $this->guestLng,
            $radiusKm,
            $entityType,
            ['limit' => 200, 'status_filter' => false],
        );

        return $results->mapWithKeys(fn ($r) => [$r->entity->id => $r->distance_km])->toArray();
    }

    /**
     * Get a distance map [campaign_id => distance_km] for campaigns via their scheduled sessions' locations.
     */
    protected function getProximityCampaignDistances(ProximityQuery $proximity, float $radiusKm): array
    {
        $gameResults = $proximity->nearby(
            $this->guestLat,
            $this->guestLng,
            $radiusKm,
            'game',
            ['limit' => 200, 'status_filter' => false],
        );

        // Group by campaign_id, take the nearest session per campaign
        return $gameResults
            ->filter(fn ($r) => $r->entity->campaign_id !== null)
            ->groupBy('entity.campaign_id')
            ->map(fn ($group) => $group->sortBy('distance_km')->first()->distance_km)
            ->toArray();
    }

    /**
     * Get recommended items for logged-in users using resolved preferences.
     *
     * Uses resolvedGameSystemPreferences() (favorites + implied_favorites, excluding avoided)
     * and resolvedVibePreferences() (favorite vibe strings for boosting).
     *
     * Two-query approach:
     *  1. Primary (boosted): items matching favorite systems AND favorite vibes.
     *  2. Fallback: items matching favorite systems regardless of vibes.
     *  Merged with boosted first, deduplicated.
     */
    protected function getRecommendations(): ?array
    {
        $user = Auth::user();
        if (!$user) {
            return null;
        }

        $resolved = $user->resolvedGameSystemPreferences();
        $resolvedVibes = $user->resolvedVibePreferences();

        $favoriteIds = $resolved['favorites']->pluck('id')->toArray();
        $impliedIds = $resolved['implied_favorites']->pluck('id')->toArray();
        $avoidedIds = $resolved['avoided']->pluck('id')->toArray();
        $favoriteVibes = $resolvedVibes['favorites'];

        // All allowed system IDs: favorites + implied, minus avoided
        $allowedSystemIds = array_values(array_diff(
            array_merge($favoriteIds, $impliedIds),
            $avoidedIds,
        ));

        if (empty($allowedSystemIds)) {
            return null;
        }

        $visibilityClause = function ($q) use ($user) {
            $q->where('visibility', 'public');
            if ($user) {
                $q->orWhere('visibility', 'protected');
            }
        };

        // Helper to tag items with discoverable_type
        $tagItems = function ($items, string $type) {
            $items->each(fn ($item) => $item->discoverable_type = $type);
            return $items;
        };

        // Primary query: favorite systems AND favorite vibes (boosted)
        $boostedIds = collect();
        if (!empty($favoriteVibes)) {
            $boostedGames = Game::query()
                ->where($visibilityClause)
                ->where('status', 'scheduled')
                ->where('date_time', '>', now())
                ->whereIn('game_system_id', $allowedSystemIds)
                ->where(function ($q) use ($favoriteVibes) {
                    foreach ($favoriteVibes as $vibe) {
                        $q->orWhereJsonContains('vibe_flags', $vibe);
                    }
                })
                ->with(['owner', 'gameSystem', 'campaign'])
                ->withCount('participants')
                ->orderBy('date_time')
                ->limit(6)
                ->get();
            $tagItems($boostedGames, 'game');

            $boostedCampaigns = Campaign::query()
                ->where($visibilityClause)
                ->where('status', 'active')
                ->whereIn('game_system_id', $allowedSystemIds)
                ->where(function ($q) use ($favoriteVibes) {
                    foreach ($favoriteVibes as $vibe) {
                        $q->orWhereJsonContains('vibe_flags', $vibe);
                    }
                })
                ->with(['owner', 'gameSystem'])
                ->withCount('sessions')
                ->orderBy('created_at', 'desc')
                ->limit(6)
                ->get();
            $tagItems($boostedCampaigns, 'campaign');

            $boostedIds = $boostedGames->merge($boostedCampaigns)->pluck('id', 'discoverable_type');
        }

        // Fallback: favorite systems regardless of vibes
        $fallbackGames = Game::query()
            ->where($visibilityClause)
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->whereIn('game_system_id', $allowedSystemIds)
            ->with(['owner', 'gameSystem', 'campaign'])
            ->withCount('participants')
            ->orderBy('date_time')
            ->limit(6)
            ->get();
        $tagItems($fallbackGames, 'game');

        $fallbackCampaigns = Campaign::query()
            ->where($visibilityClause)
            ->where('status', 'active')
            ->whereIn('game_system_id', $allowedSystemIds)
            ->with(['owner', 'gameSystem'])
            ->withCount('sessions')
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get();
        $tagItems($fallbackCampaigns, 'campaign');

        // Merge: boosted first, then fallback (dedup by type+id)
        $seen = collect();
        $merged = collect();

        // Add boosted items first
        foreach (collect($boostedGames ?? [])->merge($boostedCampaigns ?? []) as $item) {
            $key = $item->discoverable_type . ':' . $item->id;
            if (!$seen->has($key)) {
                $seen->put($key, true);
                $merged->push($item);
            }
        }

        // Add fallback items (not already present)
        foreach ($fallbackGames->merge($fallbackCampaigns) as $item) {
            $key = $item->discoverable_type . ':' . $item->id;
            if (!$seen->has($key)) {
                $seen->put($key, true);
                $merged->push($item);
            }
        }

        if ($merged->isEmpty()) {
            return null;
        }

        return $merged->take(12)->all();
    }

    // ── Curated filter lists ───────────────────────────

    /**
     * Top 15 categories by base-game system count, excluding expansion-related noise.
     * Excludes: 'Expansion for Base-game', 'Fan Expansion', 'Print & Play'.
     */
    protected function getCuratedCategories()
    {
        $excludedSlugs = [
            'expansion-for-base-game',
            'fan-expansion',
            'print-play',
        ];

        return GameSystemCategory::query()
            ->whereNotIn('slug', $excludedSlugs)
            ->whereHas('gameSystems', fn ($q) => $q->where(fn ($q) => $q->whereNull('base_game_id')->orWhere('bgg_type', 'boardgame')))
            ->withCount(['gameSystems' => fn ($q) => $q->where(fn ($q) => $q->whereNull('base_game_id')->orWhere('bgg_type', 'boardgame'))])
            ->orderByDesc('game_systems_count')
            ->limit(15)
            ->get(['id', 'name', 'slug']);
    }

    /**
     * Top 15 mechanics by base-game system count.
     */
    protected function getCuratedMechanics()
    {
        return GameSystemMechanic::query()
            ->whereHas('gameSystems', fn ($q) => $q->where(fn ($q) => $q->whereNull('base_game_id')->orWhere('bgg_type', 'boardgame')))
            ->withCount(['gameSystems' => fn ($q) => $q->where(fn ($q) => $q->whereNull('base_game_id')->orWhere('bgg_type', 'boardgame'))])
            ->orderByDesc('game_systems_count')
            ->limit(15)
            ->get(['id', 'name', 'slug']);
    }

    // ── Render ─────────────────────────────────────────

    public function render()
    {
        $results = match ($this->mode) {
            'games' => $this->getGamesResults(),
            'campaigns' => $this->getCampaignsResults(),
            default => $this->getMergedResults(),
        };

        return view('livewire.discovery.discovery-page', [
            'results' => $results,
            'recommendations' => $this->getRecommendations(),
            'experienceLevels' => ExperienceLevel::cases(),
            'safetyToolGroups' => SafetyTool::grouped(),
            'languages' => ContentLanguage::cases(),
            'recurrenceOptions' => ['weekly', 'bi-weekly', 'monthly'],
            'curatedCategories' => $this->getCuratedCategories(),
            'curatedMechanics' => $this->getCuratedMechanics(),
            'radiusOptions' => self::RADIUS_OPTIONS,
            'hasLocation' => $this->hasGuestLocation(),
        ]);
    }

    /**
     * Get paginated games results with optional distance enrichment.
     */
    protected function getGamesResults()
    {
        $query = $this->buildGamesQuery();
        $paginator = $query->paginate(12)->through(fn ($game) => tap($game, fn ($g) => $g->discoverable_type = 'game'));

        // Enrich with distance_km when proximity filter is active
        if ($this->radius > 0 && $this->hasGuestLocation()) {
            $this->enrichWithDistance($paginator->getCollection(), 'game');
        }

        return $paginator;
    }

    /**
     * Get paginated campaigns results with optional distance enrichment.
     */
    protected function getCampaignsResults()
    {
        $query = $this->buildCampaignsQuery();
        $paginator = $query->paginate(12)->through(fn ($campaign) => tap($campaign, fn ($c) => $c->discoverable_type = 'campaign'));

        // Enrich with distance_km when proximity filter is active
        if ($this->radius > 0 && $this->hasGuestLocation()) {
            $this->enrichWithDistance($paginator->getCollection(), 'campaign');
        }

        return $paginator;
    }

    /**
     * Enrich a collection of items with distance_km from ProximityQuery.
     */
    protected function enrichWithDistance($items, string $type): void
    {
        $proximity = app(ProximityQuery::class);

        if ($type === 'game') {
            $distances = $this->getProximityDistances($proximity, 'game', $this->usingFallbackRadius ? self::FALLBACK_RADIUS : $this->radius);
            $items->each(function ($item) use ($distances) {
                $item->distance_km = $distances[$item->id] ?? null;
            });
        } else {
            $campaignDistances = $this->getProximityCampaignDistances($proximity, $this->usingFallbackRadius ? self::FALLBACK_RADIUS : $this->radius);
            $items->each(function ($item) use ($campaignDistances) {
                $item->distance_km = $campaignDistances[$item->id] ?? null;
            });
        }
    }
}
