<?php

namespace App\Services;

use App\Dto\DiscoveryFilters;
use App\Dto\ProximityResult;
use App\Enums\GameType;
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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
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
    use QueriesTranslatableColumns;
    // ── Constants (synced with config/discovery.php) ───

    /** Available radius options in km */
    public const RADIUS_OPTIONS = [10, 25, 50];

    /** Fallback radius when primary radius returns empty */
    public const FALLBACK_RADIUS = 100;

    /**
     * Base feed page size (displayCount increments by this on loadMore).
     * The Gathering cap scales per this many items.
     */
    public const BASE_PAGE_SIZE = 12;

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

        $query->when(! empty($gameSystemId), function (Builder $q) use ($gameSystemId) {
            // Games match when the id is the cached anchor OR any offered system
            // (R048: a Gathering appears in each offered system's feed).
            // Campaign has no game_systems column, so it stays anchor-only.
            if ($q->getModel() instanceof Game) {
                $q->where(fn (Builder $q) => $q->where('game_system_id', $gameSystemId)->orWhereJsonContains('game_systems', $gameSystemId));
            } else {
                $q->where('game_system_id', $gameSystemId);
            }
        });
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

    /**
     * Scope a games query to a given game system type, honouring multi-system Gatherings.
     *
     * A game is included when its anchor system (game_system_id) is of $type OR
     * when any of its offered game_systems is a system of $type. Type-system IDs
     * are resolved once, then each is added as an orWhereJsonContains so a
     * multi-system Gathering surfaces in every offered type's feed.
     *
     * Apply ONLY to Game queries — Campaign has no game_systems column. For
     * campaign queries keep the anchor-only whereHas('gameSystem', type=$type).
     *
     * @param  Builder<Game>  $query
     * @param  string  $type  GameSystem type ('boardgame' or 'ttrpg')
     */
    public function applySystemTypeScope(Builder $query, string $type): void
    {
        $typeSystemIds = GameSystem::where('type', $type)
            ->pluck('id')
            ->filter(fn (mixed $id) => is_string($id))
            ->values()
            ->all();

        $query->where(function (Builder $q) use ($type, $typeSystemIds) {
            $q->whereHas('gameSystem', fn (Builder $q) => $q->where('type', $type));
            foreach ($typeSystemIds as $id) {
                $q->orWhereJsonContains('game_systems', $id);
            }
        });
    }

    // ── Gathering cap (R048) ───────────────────────────────────────────

    /**
     * Resolve the maximum Gatherings allowed in a window of $perPage items.
     *
     * The cap scales with the window size: one `gathering_cap_per_page` (default
     * 1) per BASE_PAGE_SIZE items, so loading more (e.g. 24 items) permits
     * proportionally more Gatherings while keeping density bounded.
     */
    public function gatheringCapForWindow(int $perPage): int
    {
        $configured = config('discovery.gathering_cap_per_page', 1);
        $baseCap = is_numeric($configured) ? (int) $configured : 1;
        $pagesInWindow = max(1, (int) ceil($perPage / self::BASE_PAGE_SIZE));

        return $baseCap * $pagesInWindow;
    }

    /**
     * Cap Gatherings in a candidate collection so they cannot dominate a feed page.
     *
     * Walks $items in their existing feed order (date_time or distance) keeping
     * the first $maxPerSlice Gatherings encountered; every further Gathering is
     * trimmed and its slot is backfilled with the next focused (non-Gathering)
     * candidate, preserving the prior order. The result is re-sliced to exactly
     * $perPage items.
     *
     * Deterministic: a stable single pass keeps the relative order of every item
     * that survives. Returns the number of trimmed Gatherings for observability.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $items  Candidates already in feed order.
     * @return array{items: Collection<int, TModel>, trimmed: int}
     */
    public function applyGatheringCap(Collection $items, int $perPage, ?int $maxPerSlice = null): array
    {
        $maxPerSlice ??= $this->gatheringCapForWindow($perPage);

        $gatheringsInWindow = $items
            ->take($perPage)
            ->filter(fn (mixed $item) => $this->isGathering($item))
            ->count();
        $gatheringsTrimmed = max(0, $gatheringsInWindow - $maxPerSlice);

        if ($gatheringsTrimmed > 0) {
            Log::debug('game.discovery.gathering_cap_applied', [
                'gatherings_in_window' => $gatheringsInWindow,
                'gatherings_trimmed' => $gatheringsTrimmed,
            ]);
        }

        if ($gatheringsTrimmed === 0) {
            return [
                'items' => $items->take($perPage)->values(),
                'trimmed' => 0,
            ];
        }

        $result = new Collection;
        $keptGatherings = 0;
        foreach ($items as $item) {
            if ($result->count() >= $perPage) {
                break;
            }
            if ($this->isGathering($item)) {
                if ($keptGatherings >= $maxPerSlice) {
                    continue; // trimmed
                }
                $keptGatherings++;
            }
            $result->push($item);
        }

        return [
            'items' => $result->values(),
            'trimmed' => $gatheringsTrimmed,
        ];
    }

    /**
     * Whether a feed item is a Gathering.
     *
     * Only Game models carry game_type; Campaigns (and any non-Game item) are
     * never Gatherings and pass through untouched.
     */
    private function isGathering(mixed $item): bool
    {
        return $item instanceof Game && $item->game_type === GameType::Gathering;
    }

    /**
     * Secondary sort value for the R048 gathering_relevance_penalty tiebreaker.
     *
     * Returns 1 for a Gathering, 0 for everything else. Used as a stable
     * secondary `sortBy` key so a focused single-system game ranks above an
     * otherwise-equal Gathering (same date_time or distance) without ever
     * reordering items with distinct primary keys. Exposed publicly so both the
     * service sort sites and Livewire components can share one definition.
     */
    public function gatheringRankKey(mixed $item): int
    {
        return $this->isGathering($item) ? 1 : 0;
    }

    /**
     * Eager-load every offered GameSystem for a collection of games in ONE query.
     *
     * Collects every game_systems UUID across the collection, resolves them with a
     * single GameSystem::whereIn, and injects each game's subset via setRelation so
     * the memoized {@see Game::gameSystems()} resolver never re-queries per-card.
     * Non-Game items (Campaigns in a merged collection) are skipped; games with no
     * game_systems are seeded with an empty relation so the resolver short-circuits.
     *
     * @template TItem of mixed
     *
     * @param  Collection<int, TItem>  $items  Feed-page items (Games and/or Campaigns).
     */
    public function eagerLoadGameSystems(Collection $items): void
    {
        /** @var Collection<int, Game> $games */
        $games = $items->filter(fn (mixed $item) => $item instanceof Game)->values();

        if ($games->isEmpty()) {
            return;
        }

        $allIds = [];
        foreach ($games as $game) {
            $ids = is_array($game->game_systems) ? $game->game_systems : [];
            foreach ($ids as $id) {
                if (! in_array($id, $allIds, true)) {
                    $allIds[] = $id;
                }
            }
        }

        if ($allIds === []) {
            // No multi-system games — seed each with an empty relation so the
            // resolver short-circuits without ever querying.
            $games->each(fn (Game $game) => $game->setRelation('gameSystems', new EloquentCollection));

            return;
        }

        $resolved = GameSystem::whereIn('id', $allIds)->get();

        $games->each(function (Game $game) use ($resolved): void {
            $ids = is_array($game->game_systems) ? $game->game_systems : [];

            $subset = new EloquentCollection;
            foreach ($ids as $id) {
                $system = $resolved->firstWhere('id', $id);
                if ($system !== null) {
                    $subset->push($system);
                }
            }
            $game->setRelation('gameSystems', $subset);
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
            ->with(['owner', 'gameSystem', 'campaign', 'linkedLocation'])
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

        // R048: demote Gatherings below focused single-system games sharing the
        // same date_time. The CASE is a stable tiebreaker only — distinct
        // date_times still rule because this is a secondary orderBy. Driven by
        // the tunable gathering_relevance_penalty magnitude (binary tier today,
        // headroom reserved for future ranking tiers).
        return $query->orderBy('date_time')
            ->orderByRaw("CASE WHEN game_type = 'gathering' THEN 1 ELSE 0 END ASC");
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
            ->where($this->buildVisibilityClause($user))
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

        // Campaign ID set via the shared nearest-session primitive. Only the keys
        // are needed (to constrain the WHERE IN), but routing through
        // nearestSessionByCampaign keeps one source of truth for campaign-session
        // detection across the discovery paths. Keys are string campaign IDs by
        // construction (the primitive filters getAttribute('campaign_id') through
        // is_string).
        $nearbyCampaignIds = $this->proximity
            ->nearestSessionByCampaign($nearbyResults)
            ->keys()
            ->all();

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
     * Delegates the "group by campaign, keep nearest session" pipeline to
     * {@see ProximityQuery::nearestSessionByCampaign()} — the single tested source
     * of truth shared with NearbySessions.
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

        return $this->proximity
            ->nearestSessionByCampaign($gameResults)
            ->map(fn (ProximityResult $r) => $r->distanceKm)
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

        // Resolve every offered GameSystem for the page's multi-system Gatherings
        // in ONE query so cards render the Gathering badge + all offered system
        // chips without per-card N+1 (R048).
        $this->eagerLoadGameSystems($paginator->getCollection()->toBase());

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

        $games = $gamesQuery->get()->each(function ($item) {
            $item->discoverable_type = 'game';
            $item->discoverable_sort_key = (int) ($item->date_time->timestamp ?? 0);
        });

        // Resolve every offered GameSystem for the page's multi-system Gatherings
        // in ONE query so cards render the Gathering badge + all offered system
        // chips without per-card N+1 (R048). Seeded before the merge so the
        // Campaign items in $merged are simply skipped.
        $this->eagerLoadGameSystems($games->toBase());

        $campaigns = $campaignsQuery->get()->each(function ($item) {
            $item->discoverable_type = 'campaign';
            $item->discoverable_sort_key = (int) ($item->sessions->first()->date_time->timestamp ?? $item->created_at->timestamp ?? 0);
        });

        /** @var Collection<int, Game|Campaign> $merged */
        $merged = collect([...$games, ...$campaigns]);

        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $proxResult = $this->applyProximityFilter($merged, $lat, $lng, $radius);
            $merged = $proxResult['collection'];
            $usingFallback = $proxResult['usingFallback'];
        } else {
            // R048 gathering_relevance_penalty tiebreak (stable sequential sort):
            // sort by the least-significant key first (Gathering demotion asc),
            // then the most-significant (date desc). Stability preserves the
            // Gathering ordering within an identical date bucket, so a focused
            // game beats an equal-date Gathering without reordering distinct
            // date_times.
            $merged = $merged
                ->sortBy(fn (Campaign|Game $g) => $this->gatheringRankKey($g))
                ->sortByDesc('discoverable_sort_key')
                ->values();
        }

        $total = $merged->count();
        $capped = $this->applyGatheringCap($merged, $perPage);
        $items = $capped['items'];

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
        [$filtered, $usingFallback] = $this->filterByProximity($merged, $lat, $lng, $radius);

        // R048 gathering_relevance_penalty tiebreak (stable sequential sort):
        // least-significant first (Gathering demotion asc), then most-significant
        // (distance asc) so a focused game beats an equal-distance Gathering
        // without reordering distinct distances.
        $filtered = $filtered
            ->sortBy(fn (Campaign|Game $g) => $this->gatheringRankKey($g))
            ->sortBy('distance_km')
            ->values();

        return [
            'collection' => $filtered,
            'usingFallback' => $usingFallback,
        ];
    }

    /**
     * Filter merged items by proximity, falling back to a wider radius.
     *
     * Games: distances computed in-memory from the hydrated collection (already
     * bbox-filtered by buildGamesQuery) via Haversine — no redundant DB scan.
     * Campaigns: distances via ProximityQuery (sessions at multiple locations).
     *
     * The fallback re-filters the SAME collection at FALLBACK_RADIUS. This works
     * because the bbox (which fetched the collection) is a superset of the
     * Haversine circle — games in the box corners (between circle and box edge)
     * pass the wider Haversine radius even though they failed the tighter one.
     *
     * @param  Collection<int, Game|Campaign>  $merged
     * @return array{0: Collection<int, Game|Campaign>, 1: bool}
     */
    private function filterByProximity(Collection $merged, float $lat, float $lng, float $radius): array
    {
        $filtered = $this->filterAndAttachDistances(
            $merged,
            $this->computeGameDistances($merged, $lat, $lng, $radius),
            $this->getProximityCampaignDistances($lat, $lng, $radius),
        );

        if ($filtered->isNotEmpty()) {
            return [$filtered, false];
        }

        // Fallback to wider radius.
        return [
            $this->filterAndAttachDistances(
                $merged,
                $this->computeGameDistances($merged, $lat, $lng, self::FALLBACK_RADIUS),
                $this->getProximityCampaignDistances($lat, $lng, self::FALLBACK_RADIUS),
            ),
            true,
        ];
    }

    /**
     * Compute a [gameId => distance_km] map from the hydrated collection.
     *
     * Games in the merged collection already carry linkedLocation (eager-loaded
     * by buildGamesQuery), so Haversine distances are computed without a DB scan.
     *
     * @param  Collection<int, Game|Campaign>  $merged
     * @return array<string, float>
     */
    private function computeGameDistances(Collection $merged, float $lat, float $lng, float $radius): array
    {
        $distances = [];
        foreach ($merged as $item) {
            if ($item->discoverable_type !== 'game') {
                continue;
            }
            $location = $item->linkedLocation;
            if (! $location || $location->latitude === null || $location->longitude === null) {
                continue;
            }
            $distance = ProximityQuery::haversineDistance($lat, $lng, (float) $location->latitude, (float) $location->longitude);
            if ($distance <= $radius) {
                $distances[(string) $item->id] = $distance;
            }
        }

        return $distances;
    }

    /**
     * Filter the merged collection by distance maps and attach distance_km.
     *
     * @param  Collection<int, Game|Campaign>  $merged
     * @param  array<string, mixed>  $gameDistances
     * @param  array<string, mixed>  $campaignDistances
     * @return Collection<int, Game|Campaign>
     */
    private function filterAndAttachDistances(Collection $merged, array $gameDistances, array $campaignDistances): Collection
    {
        return $merged->filter(function ($item) use ($gameDistances, $campaignDistances) {
            if ($item->discoverable_type === 'game') {
                return isset($gameDistances[(string) $item->id]);
            }

            return isset($campaignDistances[(string) $item->id]);
        })->map(function ($item) use ($gameDistances, $campaignDistances) {
            if ($item->discoverable_type === 'game') {
                $val = $gameDistances[(string) $item->id] ?? null;
            } else {
                $val = $campaignDistances[(string) $item->id] ?? null;
            }
            $item->distance_km = is_numeric($val) ? (float) $val : null;

            return $item;
        });
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

        $visibilityClause = $this->buildVisibilityClause($user);

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
                ->where($this->matchAllowedSystems($allowedSystemIds))
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
            // Resolve every offered GameSystem for the band's multi-system Gatherings
            // in ONE query so cards render all offered system chips without N+1 (R048).
            $this->eagerLoadGameSystems($boostedGames->toBase());

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
            ->where($this->matchAllowedSystems($allowedSystemIds))
            ->where('owner_id', '!=', $user->id)
            ->whereDoesntHave('participants', fn ($q) => $q->where('user_id', $user->id))
            ->with(['owner', 'gameSystem', 'campaign'])
            ->withCount('participants');

        $fallbackGames = $this->withOverflowCounts($fallbackGames)
            ->orderBy('date_time')
            ->limit(6)
            ->get();
        $tagItems($fallbackGames, 'game');
        // Resolve every offered GameSystem for the band's multi-system Gatherings
        // in ONE query so cards render all offered system chips without N+1 (R048).
        $this->eagerLoadGameSystems($fallbackGames->toBase());

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
     * Build a where-group matching games whose anchor OR any offered system is allowed.
     *
     * Recommendations use this so a Gathering surfaces when a user favors any of
     * its offered (non-anchor) systems. Campaign queries do not use it — Campaign
     * has no game_systems column and stays anchor-only (whereIn on game_system_id).
     *
     * @param  array<int, string>  $allowedSystemIds
     * @return \Closure(Builder<Game>):void
     */
    private function matchAllowedSystems(array $allowedSystemIds): \Closure
    {
        return function (Builder $q) use ($allowedSystemIds): void {
            $q->whereIn('game_system_id', $allowedSystemIds);
            foreach ($allowedSystemIds as $id) {
                $q->orWhereJsonContains('game_systems', $id);
            }
        };
    }

    /**
     * Build a connection-aware visibility clause for games and campaigns.
     *
     * Public items are visible to everyone. Protected items are visible only to the
     * owner's connections (friends, teammates) and existing participants. The single
     * shared builder for both discovery query paths (the former
     * buildVisibilityClauseCallback was a byte-identical duplicate).
     *
     * @param  User|null  $user  Current viewer
     */
    private function buildVisibilityClause(?User $user): \Closure
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
