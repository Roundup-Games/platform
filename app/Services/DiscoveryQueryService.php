<?php

namespace App\Services;

use App\Dto\CitySummary;
use App\Dto\DiscoveryFilters;
use App\Dto\ProximityResult;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\ParticipantStatus;
use App\Enums\PlayStyle;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service encapsulating all discovery query logic previously spread across
 * the DiscoveryUtilities trait and duplicated on DiscoveryPage.
 *
 * The page-facing surface (M072 S03) is per-mode entry points: results()
 * executes a mode's feed (merge, gathering cap, distance enrichment,
 * paginator construction all owned here) and curated() folds the curated
 * filter lists; the builders remain public for recommendation/landing
 * consumers. Everything else is private.
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

    /** Feed modes accepted by {@see results()} and {@see curated()}. */
    public const MODE_ALL = 'all';

    public const MODE_GAMES = 'games';

    public const MODE_CAMPAIGNS = 'campaigns';

    public const MODE_ADVENTURES = 'adventures';

    public const MODE_BOARD_GAMES = 'boardgames';

    /** Fallback radius when primary radius returns empty */
    public const FALLBACK_RADIUS = 100;

    /**
     * Base feed page size (displayCount increments by this on loadMore).
     * The Gathering cap scales per this many items.
     */
    public const BASE_PAGE_SIZE = 12;

    /**
     * Maximum items in the recommendation rail. Also the perPage passed to
     * {@see applyGatheringCap()} so Gatherings are density-capped here exactly
     * as they are in the feed (R048).
     */
    public const RECOMMENDATION_RAIL_SIZE = 12;

    /**
     * Per-source hydration cap for merged (games + campaigns) discovery
     * results — bounds the "All" tab's eager-loaded hydration set.
     */
    private const MERGE_HYDRATION_CAP = 500;

    public function __construct(
        private readonly ProximityQuery $proximity,
        ?CityDirectoryService $cityDirectory = null,
    ) {
        // Nullable default keeps manual construction with just a
        // ProximityQuery working (tests); the container injects this
        // explicitly. CityDirectoryService has no dependencies.
        $this->cityDirectory = $cityDirectory ?? app(CityDirectoryService::class);
    }

    /** Memoized ?city= filter resolution for the current render (see resolveCityFilter). */
    private readonly CityDirectoryService $cityDirectory;

    /** @var array<string, ?CitySummary> Memo keyed by the requested slug — a
     *     single boolean flag would poison a second slug resolved on the same
     *     instance (e.g. if this service is ever bound as a singleton). */
    private array $cityFilterMemo = [];

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
    private function applySharedFilters(Builder $query, string $priceColumn, DiscoveryFilters $filters): void
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
            // Match via the canonical pivot: a Gathering (or any game/campaign)
            // appears in a system's feed iff that system is in its offered set
            // (R048). Both Game and Campaign route through the same pivot shape
            // (game_game_system / campaign_game_system), so the former
            // instanceof-Game branch collapses to one whereHas.
            $q->whereHas('gameSystems', fn (Builder $q) => $q->where('game_systems.id', $gameSystemId));
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
            $q->whereHas('gameSystems.categories', fn ($q) => $q->whereIn('game_system_categories.id', $categoryIds));
        });

        $query->when(! empty($mechanicIds), function ($q) use ($mechanicIds) {
            $q->whereHas('gameSystems.mechanics', fn ($q) => $q->whereIn('game_system_mechanics.id', $mechanicIds));
        });
    }

    /**
     * Scope a games query to a given game system type, honouring multi-system Gatherings.
     *
     * A game is included when ANY of its offered systems (via the game_game_system
     * pivot) is of $type, so a multi-system Gathering surfaces in every offered
     * type's feed (R048). The former cached-anchor + JSON-array union collapses
     * to a single whereHas('gameSystems').
     *
     * @param  Builder<Game>  $query
     * @param  string  $type  GameSystem type ('boardgame' or 'ttrpg')
     */
    private function applySystemTypeScope(Builder $query, string $type): void
    {
        $query->whereHas('gameSystems', fn (Builder $q) => $q->where('type', $type));
    }

    // ── Gathering cap (R048) ───────────────────────────────────────────

    /**
     * Resolve the maximum Gatherings allowed in a window of $perPage items.
     *
     * The cap scales with the window size: one `gathering_cap_per_page` (default
     * 1) per BASE_PAGE_SIZE items, so loading more (e.g. 24 items) permits
     * proportionally more Gatherings while keeping density bounded.
     */
    private function gatheringCapForWindow(int $perPage): int
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
     * reordering items with distinct primary keys. The pages no longer sort
     * by hand — this feeds the service's merge tail only.
     */
    private function gatheringRankKey(mixed $item): int
    {
        return $this->isGathering($item) ? 1 : 0;
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
     * @param  \Closure(Builder<Game>):void|null  $exclude  Optional additive exclusion applied to the base query (e.g. recommendations' owner/participant/application exclusion)
     * @return Builder<Game>
     */
    public function buildGamesQuery(DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, ?string $date, ?\Closure $exclude = null): Builder
    {
        $query = Game::query()
            ->visibleTo($user)
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->with(['owner', 'gameSystems', 'campaign', 'linkedLocation'])
            ->withCount(['participants as participants_count' => fn ($q) => $q->where('status', ParticipantStatus::Approved->value)]);

        if ($exclude !== null) {
            $exclude($query);
        }

        $query = $this->withOverflowCounts($query);

        $this->applySharedFilters($query, 'price', $filters);

        // City-hub filter (?city=): a pure additive constraint — an absent or
        // inert slug leaves the query untouched. The cluster location set
        // always comes from CityDirectoryService (single authority), never
        // re-derived here.
        $citySummary = $this->resolveCityFilter($filters);

        if ($citySummary !== null) {
            $query->whereIn('location_id', $citySummary->locationIds);
        }

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
     * @param  \Closure(Builder<Campaign>):void|null  $exclude  Optional additive exclusion applied to the base query (e.g. recommendations' owner/participant/application exclusion)
     * @return Builder<Campaign>
     */
    public function buildCampaignsQuery(DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, ?string $recurrence, ?\Closure $exclude = null): Builder
    {
        $query = Campaign::query()
            ->visibleTo($user)
            ->where('status', 'active')
            ->with(['owner', 'gameSystems'])
            ->with(['sessions' => fn ($q) => $q->where('status', 'scheduled')->where('date_time', '>', now())->orderBy('date_time')->limit(1)])
            ->withCount('sessions')
            ->withCount('participants');

        if ($exclude !== null) {
            $exclude($query);
        }

        $query = $this->withOverflowCounts($query);

        $this->applySharedFilters($query, 'price_per_session', $filters);

        // City-hub filter mirrors CityDirectoryService::countUpcomingCampaigns():
        // campaign city membership flows through session locations
        // (games.location_id via the sessions relation), so a campaign based
        // outside the city with sessions inside it still matches.
        $citySummary = $this->resolveCityFilter($filters);

        if ($citySummary !== null) {
            $query->whereHas('sessions', fn ($s) => $s->whereIn('location_id', $citySummary->locationIds));
        }

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

    // ── City-filter resolution ────────────────────────

    /**
     * Resolve the ?city= filter to its qualifying cluster summary, or null.
     *
     * Only a slug that CityDirectoryService resolves to a single city
     * cluster AND that passes the hub qualification thresholds filters
     * results — a city without a hub must not silently narrow discovery.
     * Memoized per service instance (per render via app()): both the games
     * and campaigns builders in one render share one resolution, which is
     * itself cached by the service (900s) so repeat calls are cache hits.
     */
    private function resolveCityFilter(DiscoveryFilters $filters): ?CitySummary
    {
        $slug = (string) $filters->citySlug;

        if (! array_key_exists($slug, $this->cityFilterMemo)) {
            $summary = filled($slug)
                ? $this->cityDirectory->resolveCity($slug)
                : null;

            $this->cityFilterMemo[$slug] = ($summary !== null && $this->cityDirectory->isQualifying($summary))
                ? $summary
                : null;
        }

        return $this->cityFilterMemo[$slug];
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
    private function applyProximitySubquery(Builder $query, string $table, float $lat, float $lng, float $radius): void
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
    private function applyCampaignProximitySubquery(Builder $query, float $lat, float $lng, float $radius): void
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
    private function getProximityDistances(string $entityType, float $lat, float $lng, float $radiusKm): array
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

    // ── Page-facing entry point ────────────────────────

    /**
     * Build the sectioned results feed for a discovery page — the single
     * page-facing entry point for feed execution (M072 S03).
     *
     * Owns everything the pages previously hand-assembled around the builders:
     * mode scoping (ttrpg / boardgame), the Adventures session constraints,
     * merge ordering, the R048 gathering cap, distance enrichment and paginator
     * construction. Every returned item carries ->discoverable_type ('game' or
     * 'campaign'); the merged modes also set ->discoverable_sort_key.
     *
     * Modes:
     *  - MODE_GAMES / MODE_CAMPAIGNS: single-source DB-paginated feeds (the
     *    DiscoveryPage entity tabs; date / recurrence flow via the DTO).
     *  - MODE_ALL: merged games + campaigns ordered by next occurrence desc.
     *  - MODE_ADVENTURES: ttrpg-scoped merged feed with the fork's session
     *    constraints and campaigns pinned above games.
     *  - MODE_BOARD_GAMES: boardgame-scoped games feed with the cap-aware
     *    candidate fetch (R048 backfill) replacing true DB pagination.
     *
     * @param  int  $perPage  Feed window size (the pages' displayCount)
     * @return array{results: LengthAwarePaginator<int, Game|Campaign>, usingFallback: bool}
     *                                                                                       usingFallback reports whether the proximity branch widened to
     *                                                                                       FALLBACK_RADIUS (drives the pages' widened-radius notice).
     */
    public function results(string $mode, DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, int $perPage = self::BASE_PAGE_SIZE): array
    {
        $feed = match ($mode) {
            self::MODE_GAMES => $this->gamesResults($filters, $user, $radius, $lat, $lng, $hasLocation, $perPage),
            self::MODE_CAMPAIGNS => $this->campaignsResults($filters, $user, $radius, $lat, $lng, $hasLocation, $perPage),
            self::MODE_ADVENTURES => $this->adventuresResults($filters, $user, $radius, $lat, $lng, $hasLocation, $perPage),
            self::MODE_BOARD_GAMES => $this->boardGamesResults($filters, $user, $radius, $lat, $lng, $hasLocation, $perPage),
            default => $this->mergedResults($filters, $user, $radius, $lat, $lng, $hasLocation, $perPage),
        };

        // Widen the games-only / campaigns-only arms to the unified
        // sectioned-feed shape: the paginator's TValue template is invariant,
        // so the narrow per-mode paginators cannot flow up to the declared
        // union on their own (the merged modes already produce the union).
        /** @var array{results: LengthAwarePaginator<int, Game|Campaign>, usingFallback: bool} $sectioned */
        $sectioned = $feed;

        return $sectioned;
    }

    /**
     * Games-tab feed: true DB pagination over the games builder with
     * distance enrichment (formerly getGamesResults).
     *
     * @return array{results: LengthAwarePaginator<int, Game>, usingFallback: bool}
     */
    private function gamesResults(DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, int $perPage): array
    {
        $query = $this->buildGamesQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $filters->date);
        $paginator = $query->paginate($perPage)->through(fn ($game) => tap($game, fn ($g) => $g->discoverable_type = 'game'));

        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $this->enrichWithDistance($paginator->getCollection(), 'game', $lat, $lng, $radius, false);
        }

        return ['results' => $paginator, 'usingFallback' => false];
    }

    /**
     * Campaigns-tab feed: true DB pagination over the campaigns builder with
     * distance enrichment (formerly getCampaignsResults).
     *
     * @return array{results: LengthAwarePaginator<int, Campaign>, usingFallback: bool}
     */
    private function campaignsResults(DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, int $perPage): array
    {
        $query = $this->buildCampaignsQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $filters->recurrence);
        $paginator = $query->paginate($perPage)->through(fn ($campaign) => tap($campaign, fn ($c) => $c->discoverable_type = 'campaign'));

        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $this->enrichWithDistance($paginator->getCollection(), 'campaign', $lat, $lng, $radius, false);
        }

        return ['results' => $paginator, 'usingFallback' => false];
    }

    /**
     * Board-games feed: boardgame-scoped games with the cap-aware candidate
     * fetch (formerly BoardGamesDiscovery::getBoardGameResults).
     *
     * R048: Gatherings are capped per feed page, so the true DB paginate is
     * replaced by a candidate fetch wide enough to backfill trimmed Gatherings
     * before building the paginator over the capped slice.
     *
     * @return array{results: LengthAwarePaginator<int, Game>, usingFallback: bool}
     */
    private function boardGamesResults(DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, int $perPage): array
    {
        $query = $this->buildGamesQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $filters->date);
        $this->applySystemTypeScope($query, 'boardgame');

        // Candidate fetch: the window plus a small surplus of focused games so
        // trimmed Gathering slots can be backfilled. count() runs before take()
        // so the LIMIT never contaminates the aggregate.
        $maxPerSlice = $this->gatheringCapForWindow($perPage);
        $total = $query->count();
        $candidates = $query->take($perPage + $maxPerSlice * 2)->get()
            ->each(fn (Game $game) => $game->discoverable_type = 'game');

        // Eager-load every offered GameSystem for the page's multi-system
        // Gatherings in ONE query so cards render the Gathering badge + all
        // offered system chips without per-card N+1 (R048). belongsToMany
        // eager-loading replaces the former manual batch resolver.
        $candidates->loadMissing('gameSystems');

        $items = $this->applyGatheringCap($candidates->toBase(), $perPage, $maxPerSlice)['items'];

        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            // No fallback-widening on this path: the feed enriches at the
            // selected radius (the former component flag was always false here).
            $this->enrichWithDistance($items, 'game', $lat, $lng, $radius, false);
        }

        $paginator = new Paginator(
            $items,
            $total,
            $perPage,
            Paginator::resolveCurrentPage(),
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );

        return ['results' => $paginator, 'usingFallback' => false];
    }

    /**
     * Enrich a collection of items with distance_km from ProximityQuery.
     *
     * @template TItem of Game|Campaign
     *
     * @param  Collection<int, TItem>  $items
     */
    private function enrichWithDistance(Collection $items, string $type, float $lat, float $lng, float $radius, bool $usingFallbackRadius): void
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

    // ── Merged feeds (games + campaigns) ──────────────

    /**
     * Reorder a campaigns builder by the merged-feed merge key: next future
     * scheduled session (created_at fallback) — the same key
     * decorateFeedItem() stamps onto hydrated campaigns in mergedResults().
     *
     * MERGE_HYDRATION_CAP truncation must keep exactly the rows the merge
     * would surface first; the builder's default created_at-desc order only
     * matches the fallback key, so a long-running campaign with an upcoming
     * session could be truncated out by newer-but-sessionless rows once more
     * than the cap matches. Ordering by the key itself makes the cap honest.
     *
     * Public (the S03 public-for-test seam convention) so the cap-order
     * contract is testable without a >500-row fixture.
     *
     * @param  Builder<Campaign>  $query
     * @return Builder<Campaign>
     */
    public function orderCampaignsByMergeKey(Builder $query): Builder
    {
        return $query->reorder(DB::raw(
            'COALESCE(('
            .'SELECT MIN(next_session.date_time) FROM games next_session '
            .'WHERE next_session.campaign_id = campaigns.id '
            ."AND next_session.status = '".GameStatus::Scheduled->value."' "
            .'AND next_session.date_time > NOW()'
            .'), campaigns.created_at)'
        ), 'desc');
    }

    /**
     * "All" tab: merged games + campaigns ordered by next occurrence desc
     * (formerly getMergedResults).
     *
     * Cap per-source hydration. Both queries eager-load 6 relations + 3
     * withCount subqueries, so an unbounded get() made the default "All"
     * tab O(public catalog) per render to display $perPage items. The cap
     * keeps the newest $cap games (SQL-ordered by the same key the merge
     * sorts by) and all matching campaigns; beyond the cap the total count
     * reported by the paginator becomes the capped count — acceptable for
     * discovery UX and strictly better than hydrating everything.
     *
     * @return array{results: LengthAwarePaginator<int, Game|Campaign>, usingFallback: bool}
     */
    private function mergedResults(DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, int $perPage): array
    {
        $games = $this->buildGamesQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $filters->date)
            ->reorder('date_time', 'desc')
            ->limit(self::MERGE_HYDRATION_CAP)
            ->get()
            ->each(fn (Game $game) => $this->decorateFeedItem($game, 'game', (int) ($game->date_time->timestamp ?? 0)));

        // Hydrate campaigns in merge-key order (see orderCampaignsByMergeKey)
        // so the hydration cap keeps the rows the merge surfaces first.
        $campaigns = $this->orderCampaignsByMergeKey(
            $this->buildCampaignsQuery($filters, $user, $radius, $lat, $lng, $hasLocation, $filters->recurrence),
        )
            ->limit(self::MERGE_HYDRATION_CAP)
            ->get()
            ->each(fn (Campaign $campaign) => $this->decorateFeedItem(
                $campaign,
                'campaign',
                (int) ($campaign->sessions->first()->date_time->timestamp ?? $campaign->created_at->timestamp ?? 0),
            ));

        /** @var Collection<int, Game|Campaign> $merged */
        $merged = collect([...$games, ...$campaigns]);

        return $this->mergeAndPaginate($merged, $radius, $lat, $lng, $hasLocation, $perPage);
    }

    /**
     * Adventures feed: ttrpg-scoped merged feed with the fork's session
     * constraints (session type, session zero, play styles) and its
     * campaigns-pinned-first ordering.
     *
     * Formerly ~40 LOC hand-assembled in AdventuresDiscovery::render(),
     * duplicating the merged pipeline. Ordering differs from MODE_ALL only in
     * the campaigns' merge key: PHP_INT_MAX-bucketed so campaigns always sort
     * above games (themselves newest-first), instead of interleaving by next
     * session date.
     *
     * @return array{results: LengthAwarePaginator<int, Game|Campaign>, usingFallback: bool}
     */
    private function adventuresResults(DiscoveryFilters $filters, ?User $user, float $radius, ?float $lat, ?float $lng, bool $hasLocation, int $perPage): array
    {
        $gamesQuery = $this->buildGamesQuery($filters, $user, $radius, $lat, $lng, $hasLocation, null);
        $this->applySystemTypeScope($gamesQuery, 'ttrpg');

        $campaignsQuery = $this->buildCampaignsQuery($filters, $user, $radius, $lat, $lng, $hasLocation, null)
            ->whereHas('gameSystems', fn (Builder $q) => $q->where('type', 'ttrpg'));

        // Session-type tabs constrain the games side and drop campaign
        // entities — both tabs are game-session views.
        if ($filters->sessionType === 'campaign') {
            // Campaign Sessions tab: show only games that belong to a campaign
            $gamesQuery->whereNotNull('campaign_id');
            $campaignsQuery->whereRaw('1 = 0');
        } elseif ($filters->sessionType === 'oneshot') {
            // One-shots tab: show only standalone games
            $gamesQuery->whereNull('campaign_id');
            $campaignsQuery->whereRaw('1 = 0');
        }

        // Session zero: games that ARE a session zero (safety tool flag OR
        // name indicates it) — again games only, not campaign entities.
        if ($filters->sessionZero) {
            $nameIsSessionZero = "(name->>'en' ~* '^(session\s*zero|session\s*0)' OR name->>'de' ~* '^(session\s*zero|session\s*0)')";

            $gamesQuery->where(function ($q) use ($nameIsSessionZero) {
                $q->whereJsonContains('safety_rules->tools', 'session-zero')
                    ->orWhereRaw($nameIsSessionZero);
            });

            $campaignsQuery->whereRaw('1 = 0');
        }

        // Play styles: match games/campaigns whose game system has categories
        // matching the selected PlayStyle editorial slug mappings.
        if (! empty($filters->playStyles)) {
            $slugs = collect($filters->playStyles)
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

        $games = $gamesQuery
            ->reorder('date_time', 'desc')
            ->limit(self::MERGE_HYDRATION_CAP)
            ->get()
            ->each(fn (Game $game) => $this->decorateFeedItem($game, 'game', (int) ($game->date_time->timestamp ?? 0)));

        // No reorder needed here: the builder's created_at-desc order already
        // matches this mode's merge key (PHP_INT_MAX - created_at, newest
        // first), so the cap keeps the newest campaigns.
        $campaigns = $campaignsQuery
            ->limit(self::MERGE_HYDRATION_CAP)
            ->get()
            ->each(fn (Campaign $campaign) => $this->decorateFeedItem(
                $campaign,
                'campaign',
                PHP_INT_MAX - (int) ($campaign->created_at->timestamp ?? 0),
            ));

        /** @var Collection<int, Game|Campaign> $merged */
        $merged = collect([...$campaigns, ...$games]);

        return $this->mergeAndPaginate($merged, $radius, $lat, $lng, $hasLocation, $perPage);
    }

    /**
     * Shared merge tail for the merged modes: proximity filtering (with the
     * widened-radius fallback) or the stable R048 ordering, the gathering
     * cap, and page-1 paginator construction.
     *
     * @param  Collection<int, Game|Campaign>  $merged
     * @return array{results: LengthAwarePaginator<int, Game|Campaign>, usingFallback: bool}
     */
    private function mergeAndPaginate(Collection $merged, float $radius, ?float $lat, ?float $lng, bool $hasLocation, int $perPage): array
    {
        $usingFallback = false;

        if ($radius > 0 && $hasLocation && $lat !== null && $lng !== null) {
            $proxResult = $this->applyProximityFilter($merged, $lat, $lng, $radius);
            $merged = $proxResult['collection'];
            $usingFallback = $proxResult['usingFallback'];
        } else {
            // R048 gathering_relevance_penalty tiebreak (stable sequential sort):
            // sort by the least-significant key first (Gathering demotion asc),
            // then the most-significant (merge key desc). Stability preserves
            // ordering within an identical key bucket, so a focused game beats
            // an equal-key Gathering without reordering distinct keys. The
            // former Adventures multi-key sortBy is equivalent: PHP sorts are
            // stable, so sequential single-key sorts compose the same order.
            $merged = $merged
                ->sortBy(fn (Campaign|Game $item) => $this->gatheringRankKey($item))
                ->sortByDesc('discoverable_sort_key')
                ->values();
        }

        $total = $merged->count();
        $items = $this->applyGatheringCap($merged, $perPage)['items'];

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
     * Tag a hydrated feed item with its section and merge sort key.
     */
    private function decorateFeedItem(Game|Campaign $item, string $type, int $sortKey): void
    {
        $item->discoverable_type = $type;
        $item->discoverable_sort_key = $sortKey;
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
    private function applyProximityFilter(Collection $merged, float $lat, float $lng, float $radius): array
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
     * @return array<int, Game|Campaign>|null
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
            /** @var list<string> $typeSystemIds */
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

        // Exclude user's own games/campaigns and ones they're already in or applied to
        $excludeUser = function (Builder $query) use ($user): void {
            $query->where('owner_id', '!=', $user->id)
                ->whereDoesntHave('participants', fn ($q) => $q->whereBelongsTo($user))
                ->whereDoesntHave('applications', fn ($q) => $q->whereBelongsTo($user));
        };

        // Helper to tag items with discoverable_type
        $tagItems = function ($items, string $type) {
            $items->each(fn ($item) => $item->discoverable_type = $type);

            return $items;
        };

        // All four tier queries ride the shared builders instead of
        // re-declaring the visibleTo/status/future preamble: neutral filters
        // (no page filter state, no proximity, no date window) carry the base
        // shape; match-tier constraints (allowed systems, vibe boost, owner
        // exclusion) apply additively on top.
        $neutralFilters = new DiscoveryFilters;
        $vibeMatch = fn (Builder $q) => $q->where(function ($q) use ($favoriteVibes) {
            foreach ($favoriteVibes as $vibe) {
                $q->orWhereJsonContains('vibe_flags', $vibe);
            }
        });

        // Primary query: favorite systems AND favorite vibes (boosted)
        $boostedGames = collect();
        $boostedCampaigns = collect();
        if (! empty($favoriteVibes)) {
            $boostedGames = $this->buildGamesQuery($neutralFilters, $user, 0.0, null, null, false, null, $excludeUser)
                ->where($this->matchAllowedSystems($allowedSystemIds))
                ->where($vibeMatch)
                ->limit(intdiv(self::RECOMMENDATION_RAIL_SIZE, 2))
                ->get();
            $tagItems($boostedGames, 'game');

            // Only include campaign recommendations when not scoped to a specific type
            if ($systemType === null) {
                $boostedCampaigns = $this->buildCampaignsQuery($neutralFilters, $user, 0.0, null, null, false, null, $excludeUser)
                    ->whereHas('gameSystems', fn ($q) => $q->whereIn('game_systems.id', $allowedSystemIds))
                    ->where($vibeMatch)
                    ->limit(intdiv(self::RECOMMENDATION_RAIL_SIZE, 2))
                    ->get();
                $tagItems($boostedCampaigns, 'campaign');
            }

        }

        // Fallback: favorite systems regardless of vibes
        $fallbackGames = $this->buildGamesQuery($neutralFilters, $user, 0.0, null, null, false, null, $excludeUser)
            ->where($this->matchAllowedSystems($allowedSystemIds))
            ->limit(self::RECOMMENDATION_RAIL_SIZE)
            ->get();
        $tagItems($fallbackGames, 'game');

        $fallbackCampaigns = collect();
        if ($systemType === null) {
            $fallbackCampaigns = $this->buildCampaignsQuery($neutralFilters, $user, 0.0, null, null, false, null, $excludeUser)
                ->whereHas('gameSystems', fn ($q) => $q->whereIn('game_systems.id', $allowedSystemIds))
                ->limit(self::RECOMMENDATION_RAIL_SIZE)
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

        // R048 density rule: cap Gatherings at ~1 per rail so multi-system
        // Gatherings cannot dominate recommendations, mirroring the feed cap.
        // The match-strength ordering (boosted before fallback) is preserved —
        // applyGatheringCap walks items in their existing order, keeping the
        // earliest (strongest-match) Gathering and backfilling freed slots with
        // focused games. The fallback buckets fetch RECOMMENDATION_RAIL_SIZE
        // (not half) so the cap has focused candidates to backfill with even
        // when a scoped/no-vibes path activates only one bucket. The feed's
        // relevance-penalty sort is intentionally NOT applied here:
        // recommendations are ordered by match tier, not by date, so the
        // tiebreak has no natural primary key across boosted/fallback tiers.
        /** @var Collection<int, Game|Campaign> $merged */
        return $this->applyGatheringCap($merged, self::RECOMMENDATION_RAIL_SIZE)['items']->all();
    }

    // ── Curated filter lists ───────────────────────────

    /**
     * Deterministically fold the curated filter lists for a page mode.
     *
     * MODE_ADVENTURES returns the TTRPG variant (genre categories, empty
     * mechanics list — the TTRPG fork exposes no mechanic filter); every
     * other mode returns the default boardgame-anchored set. Replaces the
     * three former public curated getters so a page cannot mix variants.
     *
     * @return array{categories: Collection<int, GameSystemCategory>, mechanics: Collection<int, GameSystemMechanic>}
     */
    public function curated(string $mode): array
    {
        if ($mode === self::MODE_ADVENTURES) {
            /** @var Collection<int, GameSystemMechanic> $noMechanics */
            $noMechanics = new Collection;

            return [
                'categories' => $this->getCuratedTtrpgCategories(),
                'mechanics' => $noMechanics,
            ];
        }

        return [
            'categories' => $this->getCuratedCategories(),
            'mechanics' => $this->getCuratedMechanics(),
        ];
    }

    /**
     * Top 15 categories by base-game system count, excluding expansion-related noise.
     * Excludes: 'Expansion for Base-game', 'Fan Expansion', 'Print & Play'.
     *
     * @return Collection<int, GameSystemCategory>
     */
    private function getCuratedCategories(): Collection
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
     * Top 15 genre categories scoped to TTRPG game systems.
     *
     * @return Collection<int, GameSystemCategory>
     */
    private function getCuratedTtrpgCategories(): Collection
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
    private function getCuratedMechanics(): Collection
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
     * its offered (non-anchor) systems. Campaign queries use the same pivot now (campaign_game_system).
     * matches via campaign_game_system pivot.
     *
     * @param  array<int, string>  $allowedSystemIds
     * @return \Closure(Builder<Game>):void
     */
    private function matchAllowedSystems(array $allowedSystemIds): \Closure
    {
        return function (Builder $q) use ($allowedSystemIds): void {
            $q->whereHas('gameSystems', fn ($q) => $q->whereIn('game_systems.id', $allowedSystemIds));
        };
    }
}
