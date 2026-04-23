<?php

namespace App\Traits;

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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\WithPagination;

/**
 * Shared discovery logic for Livewire components that browse games and/or campaigns.
 *
 * Provides:
 *  - Query builders with shared filters (search, system, vibe, safety, language, price, complexity, categories, mechanics)
 *  - Proximity filtering with fallback radius
 *  - Distance enrichment for paginated results
 *  - Recommendation engine using resolved user preferences
 *  - Curated category/mechanic lists scoped to board-game systems
 *
 * Required traits on the consuming component:
 *  - EscapesLikeWildcards  (for search escaping)
 *  - HasGuestLocation       (for proximity)
 *  - WithPagination         (for resetPage)
 *
 * Required public properties (defined on the component):
 *  - search, game_system_id, experience_level, vibe_flags, vibePreferences,
 *    safety_tools, language, complexity_min, complexity_max, price,
 *    category_ids, mechanic_ids, radius, date
 *
 * Optional public properties:
 *  - recurrence (for campaign filtering)
 */
trait DiscoveryUtilities
{
    // ── Constants ──────────────────────────────────────

    /** Available radius options in km */
    public const RADIUS_OPTIONS = [10, 25, 50];

    /** Fallback radius when primary radius returns empty */
    public const FALLBACK_RADIUS = 100;

    // ── Shared filter application ──────────────────────

    /**
     * Apply common filters to a games or campaigns query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $priceColumn  Column name for price filtering ('price' for games, 'price_per_session' for campaigns)
     */
    protected function applySharedFilters($query, string $priceColumn): void
    {
        $query->when($this->search, fn ($q) => $q->where(function ($q) {
            $escaped = $this->escapeLikeWildcards($this->search);
            $op = $this->likeOperator();
            $q->where('name', $op, "%{$escaped}%")
              ->orWhere('description', $op, "%{$escaped}%");
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

    // ── Games query ────────────────────────────────────

    /**
     * Build the base games query with visibility, status, date, and shared filters.
     */
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

    // ── Campaigns query ────────────────────────────────

    /**
     * Build the base campaigns query with visibility, status, and shared filters.
     */
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
        if (property_exists($this, 'recurrence') && $this->recurrence) {
            $query->where('recurrence', $this->recurrence);
        }

        // When radius > 0 with location, apply proximity sub-filter via campaign sessions
        if ($this->radius > 0 && $this->hasGuestLocation()) {
            $this->applyCampaignProximitySubquery($query);
        }

        return $query->orderBy('created_at', 'desc');
    }

    // ── Proximity helpers ──────────────────────────────

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

        return $gameResults
            ->filter(fn ($r) => $r->entity->campaign_id !== null)
            ->groupBy('entity.campaign_id')
            ->map(fn ($group) => $group->sortBy('distance_km')->first()->distance_km)
            ->toArray();
    }

    // ── Paginated results ──────────────────────────────

    /**
     * Get paginated games results with optional distance enrichment.
     */
    protected function getGamesResults()
    {
        $query = $this->buildGamesQuery();
        $paginator = $query->paginate(12)->through(fn ($game) => tap($game, fn ($g) => $g->discoverable_type = 'game'));

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
        $effectiveRadius = property_exists($this, 'usingFallbackRadius') && $this->usingFallbackRadius
            ? self::FALLBACK_RADIUS
            : $this->radius;

        if ($type === 'game') {
            $distances = $this->getProximityDistances($proximity, 'game', $effectiveRadius);
            $items->each(function ($item) use ($distances) {
                $item->distance_km = $distances[$item->id] ?? null;
            });
        } else {
            $campaignDistances = $this->getProximityCampaignDistances($proximity, $effectiveRadius);
            $items->each(function ($item) use ($campaignDistances) {
                $item->distance_km = $campaignDistances[$item->id] ?? null;
            });
        }
    }

    // ── Merged results (games + campaigns) ─────────────

    /**
     * Merge games and campaigns into a unified, paginated collection.
     * Each item gets a ->discoverable_type attribute ('game' or 'campaign')
     * and a ->discoverable_sort_key for consistent ordering.
     *
     * When radius > 0 and guest location is available, results are filtered
     * by proximity and sorted by distance instead of timestamp.
     */
    protected function getMergedResults(): \Illuminate\Pagination\LengthAwarePaginator
    {
        $perPage = 12;
        $page = (int) request()->get('page', 1);

        $gamesQuery = $this->buildGamesQuery();
        $campaignsQuery = $this->buildCampaignsQuery();

        $games = $gamesQuery->get()->each(fn ($item) => [
            $item->discoverable_type = 'game',
            $item->discoverable_sort_key = $item->date_time?->timestamp ?? 0,
        ]);

        $campaigns = $campaignsQuery->get()->each(fn ($item) => [
            $item->discoverable_type = 'campaign',
            $item->discoverable_sort_key = $item->created_at?->timestamp ?? 0,
        ]);

        $merged = $games->merge($campaigns);

        if ($this->radius > 0 && $this->hasGuestLocation()) {
            $merged = $this->applyProximityFilter($merged);
        } else {
            $merged = $merged->sortByDesc('discoverable_sort_key')->values();
        }

        $total = $merged->count();
        $items = $merged->slice(($page - 1) * $perPage, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /**
     * Apply proximity filtering to merged results.
     *
     * Uses ProximityQuery to get nearby games, then intersects with the
     * already-filtered results. Items without a location match are removed.
     * If no results match within the selected radius, falls back to FALLBACK_RADIUS.
     *
     * Each remaining item gets a ->distance_km attribute for display.
     */
    protected function applyProximityFilter($merged): \Illuminate\Support\Collection
    {
        $proximity = app(ProximityQuery::class);

        if (property_exists($this, 'usingFallbackRadius')) {
            $this->usingFallbackRadius = false;
        }

        $gameDistances = $this->getProximityDistances($proximity, 'game', $this->radius);
        $campaignDistances = $this->getProximityCampaignDistances($proximity, $this->radius);

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
            if (property_exists($this, 'usingFallbackRadius')) {
                $this->usingFallbackRadius = true;
            }

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

        return $filtered->sortBy('distance_km')->values();
    }

    // ── Recommendations ────────────────────────────────

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
     *
     * @param  string|null  $systemType  Scope recommendations to a game system type (e.g., 'boardgame', 'ttrpg'). Null = all types.
     */
    protected function getRecommendations(?string $systemType = null): ?array
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

        // Scope to a specific system type if requested
        if ($systemType !== null) {
            $typeSystemIds = GameSystem::where('type', $systemType)->pluck('id')->toArray();
            $allowedSystemIds = array_values(array_intersect($allowedSystemIds, $typeSystemIds));
        }

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

            // Only include campaign recommendations when not scoped to a specific type
            $boostedCampaigns = collect();
            if ($systemType === null) {
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
            }

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

        $fallbackCampaigns = collect();
        if ($systemType === null) {
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
        }

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
     *
     * @param  string|null  $type  Scope to a game system type (e.g., 'boardgame', 'ttrpg'). Null = boardgame (default behavior).
     */
    protected function getCuratedCategories(?string $type = null)
    {
        // TTRPG scoping: simpler query without expansion exclusions
        if ($type === 'ttrpg') {
            return $this->getCuratedTtrpgCategories();
        }

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
     * Top 15 genre categories scoped to TTRPG game systems.
     */
    protected function getCuratedTtrpgCategories(): \Illuminate\Support\Collection
    {
        return GameSystemCategory::query()
            ->whereHas('gameSystems', fn ($q) => $q->where('type', 'ttrpg'))
            ->withCount(['gameSystems' => fn ($q) => $q->where('type', 'ttrpg')])
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
}
