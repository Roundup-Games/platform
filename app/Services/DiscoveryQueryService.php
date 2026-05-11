<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service encapsulating all discovery query logic previously spread across
 * the DiscoveryUtilities trait and duplicated on DiscoveryPage.
 *
 * All methods accept explicit parameters instead of reading from $this-> properties,
 * making the service fully testable and container-injectable.
 */
class DiscoveryQueryService
{
    // ── Constants (synced with config/discovery.php) ───

    /** Available radius options in km */
    public const RADIUS_OPTIONS = [10, 25, 50];

    /** Fallback radius when primary radius returns empty */
    public const FALLBACK_RADIUS = 100;

    public function __construct(
        private readonly ProximityQuery $proximity,
    ) {}

    // ── Shared filter application ──────────────────────

    /**
     * Apply common filters to a games or campaigns query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $priceColumn  Column name for price filtering ('price' for games, 'price_per_session' for campaigns)
     * @param  array  $filters  Filter DTO/array with: search, game_system_id, experience_level, vibe_flags, safety_tools, language, price, complexity_min, complexity_max, category_ids, mechanic_ids
     */
    public function applySharedFilters($query, string $priceColumn, array $filters): void
    {
        $query->when(!empty($filters['search']), fn ($q) => $q->where(function ($q) use ($filters) {
            $escaped = $this->escapeLikeWildcards($filters['search']);
            $op = $this->likeOperator();
            $q->where('name', $op, "%{$escaped}%")
              ->orWhere('description', $op, "%{$escaped}%");
        }));

        $query->when(!empty($filters['game_system_id']), fn ($q) => $q->where('game_system_id', $filters['game_system_id']));
        $query->when(!empty($filters['experience_level']), fn ($q) => $q->where('experience_level', $filters['experience_level']));

        $query->when(!empty($filters['vibe_flags']), function ($q) use ($filters) {
            foreach ($filters['vibe_flags'] as $flag) {
                $q->whereJsonContains('vibe_flags', $flag);
            }
        });

        $query->when(!empty($filters['safety_tools']), function ($q) use ($filters) {
            foreach ($filters['safety_tools'] as $tool) {
                $q->whereJsonContains('safety_rules->tools', $tool);
            }
        });

        $query->when(!empty($filters['language']), fn ($q) => $q->where('language', $filters['language']));

        $query->when(($filters['price'] ?? '') === 'free', fn ($q) => $q->where(fn ($q) => $q->where($priceColumn, 0)->orWhereNull($priceColumn)));
        $query->when(($filters['price'] ?? '') === 'paid', fn ($q) => $q->where($priceColumn, '>', 0));

        $query->when(!empty($filters['complexity_min']), fn ($q) => $q->where('complexity', '>=', (float) $filters['complexity_min']));
        $query->when(!empty($filters['complexity_max']), fn ($q) => $q->where('complexity', '<=', (float) $filters['complexity_max']));

        $query->when(!empty($filters['category_ids']), function ($q) use ($filters) {
            $q->whereHas('gameSystem.categories', fn ($q) => $q->whereIn('game_system_categories.id', $filters['category_ids']));
        });

        $query->when(!empty($filters['mechanic_ids']), function ($q) use ($filters) {
            $q->whereHas('gameSystem.mechanics', fn ($q) => $q->whereIn('game_system_mechanics.id', $filters['mechanic_ids']));
        });
    }

    // ── Games query ────────────────────────────────────

    /**
     * Build the base games query with visibility, status, date, and shared filters.
     *
     * @param  array  $filters  Shared filter array
     * @param  \App\Models\User|null  $user  Current user for visibility scoping
     * @param  float  $radius  Search radius in km (0 = no proximity filter)
     * @param  float|null  $lat  Guest latitude
     * @param  float|null  $lng  Guest longitude
     * @param  bool  $hasLocation  Whether guest location is available
     * @param  string|null  $date  Date filter ('upcoming', 'this_week', 'this_month')
     */
    public function buildGamesQuery(array $filters, $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, ?string $date)
    {
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

        $query = $this->withOverflowCounts($query);

        $this->applySharedFilters($query, 'price', $filters);

        // Games-specific: date range
        $query->when($date === 'upcoming', fn ($q) => $q->where('date_time', '>=', now()));
        $query->when($date === 'this_week', fn ($q) => $q->whereBetween('date_time', [now()->startOfWeek(), now()->endOfWeek()]));
        $query->when($date === 'this_month', fn ($q) => $q->whereBetween('date_time', [now()->startOfMonth(), now()->endOfMonth()]));

        // When radius > 0 with location, apply proximity sub-filter
        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $this->applyProximitySubquery($query, 'games', $lat, $lng, $radius);
        }

        return $query->orderBy('date_time');
    }

    // ── Campaigns query ────────────────────────────────

    /**
     * Build the base campaigns query with visibility, status, and shared filters.
     *
     * @param  array  $filters  Shared filter array
     * @param  \App\Models\User|null  $user  Current user for visibility scoping
     * @param  float  $radius  Search radius in km
     * @param  float|null  $lat  Guest latitude
     * @param  float|null  $lng  Guest longitude
     * @param  bool  $hasLocation  Whether guest location is available
     * @param  string|null  $recurrence  Recurrence filter for campaigns
     */
    public function buildCampaignsQuery(array $filters, $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, ?string $recurrence)
    {
        $query = Campaign::query()
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public');
                if ($user) {
                    $q->orWhere('visibility', 'protected');
                }
            })
            ->where('status', 'active')
            ->with(['owner', 'gameSystem'])
            ->withCount('sessions')
            ->withCount('participants');

        $query = $this->withOverflowCounts($query);

        $this->applySharedFilters($query, 'price_per_session', $filters);

        // Campaigns-specific: recurrence
        if ($recurrence) {
            $query->where('recurrence', $recurrence);
        }

        // When radius > 0 with location, apply proximity sub-filter via campaign sessions
        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $this->applyCampaignProximitySubquery($query, $lat, $lng, $radius);
        }

        return $query->orderBy('created_at', 'desc');
    }

    // ── Proximity helpers ──────────────────────────────

    /**
     * Apply proximity subquery to a games query builder.
     * Filters to games whose linked location falls within the given radius.
     */
    public function applyProximitySubquery($query, string $table, float $lat, float $lng, float $radius): void
    {
        $bounds = $this->proximity->boundingBox($lat, $lng, $radius);

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
    public function applyCampaignProximitySubquery($query, float $lat, float $lng, float $radius): void
    {
        $nearbyResults = $this->proximity->nearby(
            $lat,
            $lng,
            $radius,
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
    public function getProximityDistances(string $entityType, float $lat, float $lng, float $radiusKm): array
    {
        $results = $this->proximity->nearby(
            $lat,
            $lng,
            $radiusKm,
            $entityType,
            ['limit' => 200, 'status_filter' => false],
        );

        return $results->mapWithKeys(fn ($r) => [$r->entity->id => $r->distance_km])->toArray();
    }

    /**
     * Get a distance map [campaign_id => distance_km] for campaigns via their scheduled sessions' locations.
     */
    public function getProximityCampaignDistances(float $lat, float $lng, float $radiusKm): array
    {
        $gameResults = $this->proximity->nearby(
            $lat,
            $lng,
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
    public function getGamesResults(array $filters, $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, ?string $date): LengthAwarePaginator
    {
        $query = $this->buildGamesQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $date);
        $paginator = $query->paginate(12)->through(fn ($game) => tap($game, fn ($g) => $g->discoverable_type = 'game'));

        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $this->enrichWithDistance($paginator->getCollection(), 'game', $lat, $lng, $radius, false);
        }

        return $paginator;
    }

    /**
     * Get paginated campaigns results with optional distance enrichment.
     */
    public function getCampaignsResults(array $filters, $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, ?string $recurrence): LengthAwarePaginator
    {
        $query = $this->buildCampaignsQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $recurrence);
        $paginator = $query->paginate(12)->through(fn ($campaign) => tap($campaign, fn ($c) => $c->discoverable_type = 'campaign'));

        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $this->enrichWithDistance($paginator->getCollection(), 'campaign', $lat, $lng, $radius, false);
        }

        return $paginator;
    }

    /**
     * Enrich a collection of items with distance_km from ProximityQuery.
     */
    public function enrichWithDistance($items, string $type, float $lat, float $lng, float $radius, bool $usingFallbackRadius): void
    {
        $effectiveRadius = $usingFallbackRadius
            ? self::FALLBACK_RADIUS
            : $radius;

        if ($type === 'game') {
            $distances = $this->getProximityDistances('game', $lat, $lng, $effectiveRadius);
            $items->each(function ($item) use ($distances) {
                $item->distance_km = $distances[$item->id] ?? null;
            });
        } else {
            $campaignDistances = $this->getProximityCampaignDistances($lat, $lng, $effectiveRadius);
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
     *
     * @return array{results: LengthAwarePaginator, usingFallback: bool}
     */
    public function getMergedResults(array $filters, $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, ?string $date, ?string $recurrence): array
    {
        $perPage = 12;
        $page = (int) request()->get('page', 1);
        $usingFallback = false;

        $gamesQuery = $this->buildGamesQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $date);
        $campaignsQuery = $this->buildCampaignsQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $recurrence);

        $games = $gamesQuery->get()->each(fn ($item) => [
            $item->discoverable_type = 'game',
            $item->discoverable_sort_key = $item->date_time?->timestamp ?? 0,
        ]);

        $campaigns = $campaignsQuery->get()->each(fn ($item) => [
            $item->discoverable_type = 'campaign',
            $item->discoverable_sort_key = $item->created_at?->timestamp ?? 0,
        ]);

        $merged = $games->merge($campaigns);

        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $proxResult = $this->applyProximityFilter($merged, $lat, $lng, $radius);
            $merged = $proxResult['collection'];
            $usingFallback = $proxResult['usingFallback'];
        } else {
            $merged = $merged->sortByDesc('discoverable_sort_key')->values();
        }

        $total = $merged->count();
        $items = $merged->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new Paginator($items, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);

        return [
            'results' => $paginator,
            'usingFallback' => $usingFallback,
        ];
    }

    /**
     * Apply proximity filtering to merged results.
     *
     * Uses ProximityQuery to get nearby games, then intersects with the
     * already-filtered results. Items without a location match are removed.
     * If no results match within the selected radius, falls back to FALLBACK_RADIUS.
     *
     * Each remaining item gets a ->distance_km attribute for display.
     *
     * @return array{collection: Collection, usingFallback: bool}
     */
    public function applyProximityFilter($merged, float $lat, float $lng, float $radius): array
    {
        $usingFallback = false;

        $gameDistances = $this->getProximityDistances('game', $lat, $lng, $radius);
        $campaignDistances = $this->getProximityCampaignDistances($lat, $lng, $radius);

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
            $usingFallback = true;

            $gameDistances = $this->getProximityDistances('game', $lat, $lng, self::FALLBACK_RADIUS);
            $campaignDistances = $this->getProximityCampaignDistances($lat, $lng, self::FALLBACK_RADIUS);

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

        return [
            'collection' => $filtered->sortBy('distance_km')->values(),
            'usingFallback' => $usingFallback,
        ];
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
     * @param  \App\Models\User|null  $user  Current user (null returns null)
     * @param  string|null  $systemType  Scope recommendations to a game system type (e.g., 'boardgame', 'ttrpg'). Null = all types.
     * @return array|null  Array of Game|Campaign models with discoverable_type attribute, or null if no recommendations
     */
    public function getRecommendations($user, ?string $systemType = null): ?array
    {
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
        $boostedGames = collect();
        $boostedCampaigns = collect();
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
                ->withCount('participants');

            $games = $this->withOverflowCounts($games)
                ->orderBy('date_time')
                ->limit(6)
                ->get();
            $tagItems($boostedGames, 'game');

            // Only include campaign recommendations when not scoped to a specific type
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
                    ->withCount('participants');

                $boostedCampaigns = $this->withOverflowCounts($boostedCampaigns)
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
            ->withCount('participants');

        $fallbackGames = $this->withOverflowCounts($fallbackGames)
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
                ->withCount('participants');

            $fallbackCampaigns = $this->withOverflowCounts($fallbackCampaigns)
                ->orderBy('created_at', 'desc')
                ->limit(6)
                ->get();
            $tagItems($fallbackCampaigns, 'campaign');
        }

        // Merge: boosted first, then fallback (dedup by type+id)
        $seen = collect();
        $merged = collect();

        // Add boosted items first
        foreach ($boostedGames->merge($boostedCampaigns) as $item) {
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
    public function getCuratedCategories(?string $type = null)
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
    public function getCuratedTtrpgCategories(): Collection
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
    public function getCuratedMechanics()
    {
        return GameSystemMechanic::query()
            ->whereHas('gameSystems', fn ($q) => $q->where(fn ($q) => $q->whereNull('base_game_id')->orWhere('bgg_type', 'boardgame')))
            ->withCount(['gameSystems' => fn ($q) => $q->where(fn ($q) => $q->whereNull('base_game_id')->orWhere('bgg_type', 'boardgame'))])
            ->orderByDesc('game_systems_count')
            ->limit(15)
            ->get(['id', 'name', 'slug']);
    }

    // ── SQL helpers (inlined from EscapesLikeWildcards trait) ──

    /**
     * Apply overflow (waitlist + bench) count subqueries to a game or campaign query.
     * Consolidates the repeated withCount calls for waitlisted_count and benched_count.
     */
    private function withOverflowCounts($query)
    {
        return $query
            ->withCount(['participants as waitlisted_count' => fn ($q) => $q->where('status', 'waitlisted')])
            ->withCount(['participants as benched_count' => fn ($q) => $q->where('status', 'benched')]);
    }

    /**
     * Escape SQL LIKE wildcard characters (%, _) in user-provided search strings.
     */
    private function escapeLikeWildcards(string $search): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $search,
        );
    }

    /**
     * Return the case-insensitive LIKE operator for the current database driver.
     * PostgreSQL requires 'ilike' for case-insensitive matching; MySQL's 'like' is already case-insensitive.
     */
    private function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
