<?php

namespace App\Services;

use App\Dto\DiscoveryFilters;
use App\Dto\ProximityResult;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Models\User;
use App\Traits\QueriesTranslatableColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

/**
 * Service encapsulating all discovery query logic previously spread across
 * the DiscoveryUtilities trait and duplicated on DiscoveryPage.
 *
 * All methods accept explicit parameters instead of reading from $this-> properties,
 * making the service fully testable and container-injectable.
 */
class DiscoveryQueryService
{
    use QueriesTranslatableColumns;
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
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $priceColumn  Column name for price filtering ('price' for games, 'price_per_session' for campaigns)
     * @param  DiscoveryFilters  $filters  Typed filter DTO — all entity IDs are UUID strings
     */
    public function applySharedFilters(Builder $query, string $priceColumn, DiscoveryFilters $filters): void
    {
        $search = $filters->search;
        $gameSystemId = $filters->gameSystemId;
        $experienceLevel = $filters->experienceLevel;
        $vibeFlags = $filters->vibeFlags;
        $safetyTools = $filters->safetyTools;
        $language = $filters->language;
        $priceMax = is_numeric($filters->price) ? (float) $filters->price : null;
        $complexityMin = is_numeric($filters->complexityMin) ? (float) $filters->complexityMin : null;
        $complexityMax = is_numeric($filters->complexityMax) ? (float) $filters->complexityMax : null;
        $categoryIds = $filters->categoryIds;
        $mechanicIds = $filters->mechanicIds;

        $query->when(! empty($search), fn ($q) => $q->where(function ($q) use ($search) {
            $this->whereTranslatableLike($q, 'name', (string) $search);
            $this->orWhereTranslatableLike($q, 'description', (string) $search);
        }));

        $query->when(! empty($gameSystemId), fn ($q) => $q->where('game_system_id', $gameSystemId));
        $query->when(! empty($experienceLevel), fn ($q) => $q->where('experience_level', $experienceLevel));

        $query->when(! empty($vibeFlags), function ($q) use ($vibeFlags) {
            foreach ($vibeFlags as $flag) {
                $q->whereJsonContains('vibe_flags', $flag);
            }
        });

        $query->when(! empty($safetyTools), function ($q) use ($safetyTools) {
            foreach ($safetyTools as $tool) {
                $q->whereJsonContains('safety_rules->tools', $tool);
            }
        });

        $query->when(! empty($language), fn ($q) => $q->where('language', $language));

        $query->when(($filters->price) === 'free', fn ($q) => $q->where(fn ($q) => $q->where($priceColumn, 0)->orWhereNull($priceColumn)));
        $query->when(($filters->price) === 'paid', fn ($q) => $q->where($priceColumn, '>', 0));

        $query->when(! empty($complexityMin), fn ($q) => $q->where('complexity', '>=', $complexityMin));
        $query->when(! empty($complexityMax), fn ($q) => $q->where('complexity', '<=', $complexityMax));

        $query->when(! empty($categoryIds), function ($q) use ($categoryIds) {
            $q->whereHas('gameSystem.categories', fn ($q) => $q->whereIn('game_system_categories.id', $categoryIds));
        });

        $query->when(! empty($mechanicIds), function ($q) use ($mechanicIds) {
            $q->whereHas('gameSystem.mechanics', fn ($q) => $q->whereIn('game_system_mechanics.id', $mechanicIds));
        });
    }

    // ── Games query ────────────────────────────────────

    /**
     * Build the base games query with visibility, status, date, and shared filters.
     *
     * Visibility logic:
     *  - Public: visible to everyone.
     *  - Protected ("Connections Only"): visible to the owner, their mutual follows (friends),
     *    teammates on active teams, and existing participants.
     *  - Private: excluded from discovery entirely.
     *
     * @return Builder<Game>
     */
    public function buildGamesQuery(DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, ?string $date): Builder
    {
        $query = Game::query()
            ->where($this->buildVisibilityClause($user))
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->with(['owner', 'gameSystem', 'campaign'])
            ->withCount(['participants as participants_count' => fn ($q) => $q->where('status', ParticipantStatus::Approved->value)]);

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
     * Visibility logic mirrors buildGamesQuery — protected campaigns are restricted
     * to the owner's connections (friends, teammates) and existing participants.
     *
     * @return Builder<Campaign>
     */
    public function buildCampaignsQuery(DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, ?string $recurrence): Builder
    {
        $query = Campaign::query()
            ->where($this->buildVisibilityClause($user, 'campaigns'))
            ->where('status', 'active')
            ->with(['owner', 'gameSystem'])
            ->with(['sessions' => fn ($q) => $q->where('status', 'scheduled')->where('date_time', '>', now())->orderBy('date_time')->limit(1)])
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
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function applyProximitySubquery(Builder $query, string $table, float $lat, float $lng, float $radius): void
    {
        $bounds = $this->proximity->boundingBox($lat, $lng, $radius);

        $query->whereHas('linkedLocation', function ($q) use ($bounds) {
            $q->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->whereBetween('latitude', [$bounds->minLat, $bounds->maxLat])
                ->whereBetween('longitude', [$bounds->minLng, $bounds->maxLng]);
        });
    }

    /**
     * Apply proximity subquery to a campaigns query builder.
     * Filters to campaigns that have at least one scheduled session within the radius.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function applyCampaignProximitySubquery(Builder $query, float $lat, float $lng, float $radius): void
    {
        $nearbyResults = $this->proximity->nearby(
            $lat,
            $lng,
            $radius,
            'game',
            ['limit' => 200, 'status_filter' => false],
        );

        $nearbyCampaignIds = $nearbyResults
            ->filter(function (mixed $r) {
                if (! $r instanceof ProximityResult) {
                    return false;
                }
                $entity = $r->entity;

                return property_exists($entity, 'campaign_id') && $entity->campaign_id !== null;
            })
            ->pluck('entity.campaign_id')
            ->filter(fn (mixed $v) => is_string($v))
            ->unique()
            ->values()
            ->toArray();

        $query->whereIn('id', $nearbyCampaignIds);
    }

    /**
     * Get a distance map [entity_id => distance_km] from ProximityQuery for a given entity type.
     *
     * @return array<string, mixed>
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

        return $results->mapWithKeys(function (mixed $r) {
            if (! $r instanceof ProximityResult) {
                return [];
            }

            $key = $r->entity->getKey();
            if (! is_string($key)) {
                return [];
            }

            return [$key => $r->distanceKm];
        })->toArray();
    }

    /**
     * Get a distance map [campaign_id => distance_km] for campaigns via their scheduled sessions' locations.
     *
     * @return array<string, mixed>
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
            ->filter(function (mixed $r) {
                if (! $r instanceof ProximityResult) {
                    return false;
                }
                $entity = $r->entity;

                return property_exists($entity, 'campaign_id') && $entity->campaign_id !== null;
            })
            ->groupBy('entity.campaign_id')
            ->map(function (mixed $group) {
                if (! $group instanceof Collection) { // @phpstan-ignore instanceof.alwaysTrue
                    return null;
                }
                $first = $group->sortBy(fn (mixed $a, mixed $b) => ($a instanceof ProximityResult && $b instanceof ProximityResult) ? $a->distanceKm <=> $b->distanceKm : 0)->first(); // @phpstan-ignore instanceof.alwaysFalse, booleanAnd.alwaysFalse
                if (! $first instanceof ProximityResult) {
                    return null;
                }

                return $first->distanceKm;
            })
            ->filter()
            ->toArray();
    }

    // ── Paginated results ──────────────────────────────

    /**
     * Get paginated games results with optional distance enrichment.
     *
     * @return LengthAwarePaginator<int, Game>
     */
    public function getGamesResults(DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, ?string $date, int $perPage = 12): LengthAwarePaginator
    {
        $query = $this->buildGamesQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $date);
        $paginator = $query->paginate($perPage)->through(fn ($game) => tap($game, fn ($g) => $g->discoverable_type = 'game'));

        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $this->enrichWithDistance($paginator->getCollection(), 'game', $lat, $lng, $radius, false);
        }

        return $paginator;
    }

    /**
     * Get paginated campaigns results with optional distance enrichment.
     *
     * @return LengthAwarePaginator<int, Campaign>
     */
    public function getCampaignsResults(DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, ?string $recurrence, int $perPage = 12): LengthAwarePaginator
    {
        $query = $this->buildCampaignsQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $recurrence);
        $paginator = $query->paginate($perPage)->through(fn ($campaign) => tap($campaign, fn ($c) => $c->discoverable_type = 'campaign'));

        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $this->enrichWithDistance($paginator->getCollection(), 'campaign', $lat, $lng, $radius, false);
        }

        return $paginator;
    }

    /**
     * Enrich a collection of items with distance_km from ProximityQuery.
     *
     * @template TItem of Game|Campaign
     *
     * @param  Collection<int, TItem>  $items
     */
    public function enrichWithDistance(Collection $items, string $type, float $lat, float $lng, float $radius, bool $usingFallbackRadius): void
    {
        $effectiveRadius = $usingFallbackRadius
            ? self::FALLBACK_RADIUS
            : $radius;

        if ($type === 'game') {
            $distances = $this->getProximityDistances('game', $lat, $lng, $effectiveRadius);
            $items->each(function ($item) use ($distances) {
                $val = $distances[$item->id] ?? null;
                $item->distance_km = is_numeric($val) ? (float) $val : null;
            });
        } else {
            $campaignDistances = $this->getProximityCampaignDistances($lat, $lng, $effectiveRadius);
            $items->each(function ($item) use ($campaignDistances) {
                $val = $campaignDistances[$item->id] ?? null;
                $item->distance_km = is_numeric($val) ? (float) $val : null;
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
     * @return array{results: LengthAwarePaginator<int, Game|Campaign>, usingFallback: bool}
     */
    public function getMergedResults(DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, ?string $date, ?string $recurrence, int $perPage = 12): array
    {
        $usingFallback = false;

        $gamesQuery = $this->buildGamesQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $date);
        $campaignsQuery = $this->buildCampaignsQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $recurrence);

        $games = $gamesQuery->get()->each(fn ($item) => [
            $item->discoverable_type = 'game',
            $item->discoverable_sort_key = (int) ($item->date_time->timestamp ?? 0),
        ]);

        $campaigns = $campaignsQuery->get()->each(fn ($item) => [
            $item->discoverable_type = 'campaign',
            $item->discoverable_sort_key = (int) ($item->sessions->first()->date_time->timestamp ?? $item->created_at->timestamp ?? 0),
        ]);

        /** @var Collection<int, Game|Campaign> $merged */
        $merged = collect([...$games, ...$campaigns]);

        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $proxResult = $this->applyProximityFilter($merged, $lat, $lng, $radius);
            $merged = $proxResult['collection'];
            $usingFallback = $proxResult['usingFallback'];
        } else {
            $merged = $merged->sortByDesc('discoverable_sort_key')->values();
        }

        $total = $merged->count();
        $items = $merged->take($perPage)->values();

        $paginator = new Paginator($items, $total, $perPage, 1, [
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
     * @param  Collection<int, Game|Campaign>  $merged
     * @return array{collection: Collection<int, Game|Campaign>, usingFallback: bool}
     */
    public function applyProximityFilter(Collection $merged, float $lat, float $lng, float $radius): array
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
                $val = $gameDistances[$item->id] ?? null;
            } else {
                $val = $campaignDistances[$item->id] ?? null;
            }
            $item->distance_km = is_numeric($val) ? (float) $val : null;

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
                    $val = $gameDistances[$item->id] ?? null;
                } else {
                    $val = $campaignDistances[$item->id] ?? null;
                }
                $item->distance_km = is_numeric($val) ? (float) $val : null;

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
     * @param  User|null  $user  Current user (null returns null)
     * @param  string|null  $systemType  Scope recommendations to a game system type (e.g., 'boardgame', 'ttrpg'). Null = all types.
     * @return array<string, mixed>|null
     */
    public function getRecommendations(?User $user, ?string $systemType = null): ?array
    {
        if (! $user) {
            return null;
        }

        $resolved = $user->resolvedGameSystemPreferences();
        $resolvedVibes = $user->resolvedVibePreferences();

        $favorites = $resolved['favorites'] ?? collect();
        $implied = $resolved['implied_favorites'] ?? collect();
        $avoided = $resolved['avoided'] ?? collect();

        $pluckIds = fn (mixed $collection): array => ($collection instanceof Collection ? $collection : collect())
            ->pluck('id')
            ->filter(fn (mixed $id) => is_string($id))
            ->values()
            ->toArray();

        $favoriteIds = $pluckIds($favorites);
        $impliedIds = $pluckIds($implied);
        $avoidedIds = $pluckIds($avoided);
        $favoriteVibes = is_array($resolvedVibes['favorites'] ?? null) ? $resolvedVibes['favorites'] : [];

        // All allowed system IDs: favorites + implied, minus avoided
        $allowedSystemIds = array_values(array_diff(
            array_merge($favoriteIds, $impliedIds),
            $avoidedIds,
        ));

        // Scope to a specific system type if requested
        if ($systemType !== null) {
            $typeSystemIds = GameSystem::where('type', $systemType)
                ->pluck('id')
                ->filter(fn (mixed $id) => is_string($id))
                ->values()
                ->toArray();
            $allowedSystemIds = array_values(array_intersect($allowedSystemIds, $typeSystemIds));
        }

        if (empty($allowedSystemIds)) {
            return null;
        }

        $visibilityClause = $this->buildVisibilityClauseCallback($user);

        // Exclude user's own games/campaigns and ones they're already in or applied to
        $excludeUser = function ($query) use ($user) {
            $query->where('owner_id', '!=', $user->id)
                ->whereDoesntHave('participants', fn ($q) => $q->where('user_id', $user->id))
                ->whereDoesntHave('applications', fn ($q) => $q->where('user_id', $user->id));
        };

        // Helper to tag items with discoverable_type
        $tagItems = function ($items, string $type) {
            $items->each(fn ($item) => $item->discoverable_type = $type);

            return $items;
        };

        // Primary query: favorite systems AND favorite vibes (boosted)
        $boostedGames = collect();
        $boostedCampaigns = collect();
        if (! empty($favoriteVibes)) {
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
                ->where('owner_id', '!=', $user->id)
                ->whereDoesntHave('participants', fn ($q) => $q->where('user_id', $user->id))
                ->with(['owner', 'gameSystem', 'campaign'])
                ->withCount('participants');

            $boostedGames = $this->withOverflowCounts($boostedGames)
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
                    ->where('owner_id', '!=', $user->id)
                    ->whereDoesntHave('participants', fn ($q) => $q->where('user_id', $user->id))
                    ->with(['owner', 'gameSystem'])
                    ->withCount('sessions')
                    ->withCount('participants');

                $boostedCampaigns = $this->withOverflowCounts($boostedCampaigns)
                    ->orderBy('created_at', 'desc')
                    ->limit(6)
                    ->get();
                $tagItems($boostedCampaigns, 'campaign');
            }

        }

        // Fallback: favorite systems regardless of vibes
        $fallbackGames = Game::query()
            ->where($visibilityClause)
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->whereIn('game_system_id', $allowedSystemIds)
            ->where('owner_id', '!=', $user->id)
            ->whereDoesntHave('participants', fn ($q) => $q->where('user_id', $user->id))
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
                ->where('owner_id', '!=', $user->id)
                ->whereDoesntHave('participants', fn ($q) => $q->where('user_id', $user->id))
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
            $key = $item->discoverable_type.':'.$item->id;
            if (! $seen->has($key)) {
                $seen->put($key, true);
                $merged->push($item);
            }
        }

        // Add fallback items (not already present)
        foreach ($fallbackGames->merge($fallbackCampaigns) as $item) {
            $key = $item->discoverable_type.':'.$item->id;
            if (! $seen->has($key)) {
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
     * @return Collection<int, GameSystemCategory>
     */
    public function getCuratedCategories(?string $type = null): Collection
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
     *
     * @return Collection<int, GameSystemCategory>
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
     *
     * @return Collection<int, GameSystemMechanic>
     */
    public function getCuratedMechanics(): Collection
    {
        return GameSystemMechanic::query()
            ->whereHas('gameSystems', fn ($q) => $q->where(fn ($q) => $q->whereNull('base_game_id')->orWhere('bgg_type', 'boardgame')))
            ->withCount(['gameSystems' => fn ($q) => $q->where(fn ($q) => $q->whereNull('base_game_id')->orWhere('bgg_type', 'boardgame'))])
            ->orderByDesc('game_systems_count')
            ->limit(15)
            ->get(['id', 'name', 'slug']);
    }

    // ── SQL helpers ────────────────────────────────────

    /**
     * Apply overflow (waitlist + bench) count subqueries to a game or campaign query.
     * Consolidates the repeated withCount calls for waitlisted_count and benched_count.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function withOverflowCounts(Builder $query): Builder
    {
        return $query
            ->withCount(['participants as waitlisted_count' => fn ($q) => $q->where('status', 'waitlisted')])
            ->withCount(['participants as benched_count' => fn ($q) => $q->where('status', 'benched')]);
    }

    /**
     * Build a connection-aware visibility clause for games or campaigns.
     *
     * Public items are visible to everyone.
     * Protected items are visible only to the owner's connections (friends, teammates)
     * and existing participants.
     *
     * @param  User|null  $user  Current viewer
     * @param  string  $table  Table name for participant subquery ('games' or 'campaigns')
     */
    private function buildVisibilityClause($user, string $table = 'games'): \Closure
    {
        return function ($q) use ($user) {
            $q->where('visibility', 'public');

            if ($user) {
                $q->orWhere(function ($q) use ($user) {
                    $q->where('visibility', 'protected')
                        ->where(function ($q) use ($user) {
                            $allowedOwnerIds = app(SocialGraphService::class)
                                ->getAllowedOwnerIdsForProtectedContent($user);
                            $q->whereIn('owner_id', $allowedOwnerIds)
                                ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $user->id));
                        });
                });
            }
        };
    }

    /**
     * Build a connection-aware visibility callback for inline use in query builders.
     * Returns the same logic as buildVisibilityClause but as a standalone callable.
     */
    private function buildVisibilityClauseCallback(?User $user): \Closure
    {
        return function ($q) use ($user) {
            $q->where('visibility', 'public');

            if ($user) {
                $q->orWhere(function ($q) use ($user) {
                    $q->where('visibility', 'protected')
                        ->where(function ($q) use ($user) {
                            $allowedOwnerIds = app(SocialGraphService::class)
                                ->getAllowedOwnerIdsForProtectedContent($user);
                            $q->whereIn('owner_id', $allowedOwnerIds)
                                ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $user->id));
                        });
                });
            }
        };
    }
}
