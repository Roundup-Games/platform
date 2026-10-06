<?php

namespace App\Services;

use App\Dto\CitySummary;
use App\Enums\CampaignStatus;
use App\Enums\EventStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\City;
use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Resolves city clusters and guards city hub eligibility (M062).
 *
 * A city cluster is the set of locations whose normalized city name
 * (Str::slug(city)) matches the requested slug AND whose geohash region
 * prefix matches — Location.city plus geohash_4, per the M062 goal. A slug
 * that maps to locations in more than one geohash region is ambiguous
 * (same city name in different regions, e.g. multiple German Neustadts)
 * and resolves to null unless a curated cities.region_prefix pins one
 * region exactly (62-04).
 *
 * Curation (62-04) is enforced here — inside the resolution — so every
 * consumer (the hub guard, the 62-03 sitemap + canonical folding, the
 * featured-cities rail) inherits hide/feature semantics from one place
 * (MEM1023): a hidden cities row sentinel-caches STATUS_HIDDEN, a featured
 * row force-qualifies over both thresholds (MEM995), and its translatable
 * intro rides the summary. A curated row for an unknown slug conjures
 * nothing — no locations is still not_found.
 *
 * Qualification is OR-based: a city qualifies on either the upcoming public
 * activity threshold or the verified-venue threshold — both read through
 * CityHubSettings (DB rows over config cityhubs.*). Non-qualifying cities
 * get a real 404 downstream — never a soft empty page.
 */
class CityDirectoryService
{
    /**
     * Geohash prefix length that delimits one city cluster. 3 chars ≈ a
     * 156km × 156km cell — regional scale: Berlin and its districts share a
     * prefix while Berlin/Hamburg/Munich each get their own. A 4-char prefix
     * (~39km) would false-positive on metros straddling a tile boundary.
     */
    private const CLUSTER_GEOHASH_PREFIX_LENGTH = 3;

    private const CACHE_PREFIX = 'city-hubs:summary:';

    /**
     * Max items rendered per hub section (T03). Each source query is
     * SQL-limited to this size and the merged sessions section is re-cut
     * to it after chronological sorting, so a hub render is bounded at
     * 4 fixed-cost queries regardless of city size.
     */
    public const SECTION_LIMIT = 12;

    public const STATUS_OK = 'ok';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_HIDDEN = 'hidden';

    public function __construct(
        private readonly CityHubSettings $hubSettings
    ) {}

    /**
     * Resolve a city slug to its cluster summary, or null when the slug is
     * unknown or ambiguous. The full resolution (cluster + activity counts)
     * is cached; negative results are cached too so 404 traffic for
     * non-qualifying cities does not re-run the queries per request.
     */
    public function resolveCity(string $slug): ?CitySummary
    {
        $resolution = $this->cachedResolution($slug);
        $summary = $resolution['summary'];

        return $resolution['status'] === self::STATUS_OK && is_array($summary)
            ? CitySummary::fromArray($summary)
            : null;
    }

    /**
     * Resolution status for guard-rejection logging: 'ok', 'not_found',
     * 'ambiguous', or 'hidden' (a curated hide, 62-04). The remaining
     * rejection reason, 'below_threshold', is derived by the caller when
     * status is 'ok' but isQualifying() is false — the structured
     * cityhub.rejected log composes all of these (T04).
     */
    public function resolveStatus(string $slug): string
    {
        return $this->cachedResolution($slug)['status'];
    }

    /**
     * Does this city meet a qualification threshold? A featured curated
     * row force-qualifies over both thresholds (MEM995) — a curated hub
     * ships even when its natural activity is quiet. Otherwise either the
     * upcoming public activity count (games + campaigns + events) or the
     * verified-venue count suffices, with both thresholds read through
     * CityHubSettings so admin rows override config without a deploy
     * (62-04) — the config fallback keeps the no-row behavior identical.
     */
    public function isQualifying(CitySummary $summary): bool
    {
        if ($summary->featured) {
            return true;
        }

        return $summary->upcomingActivityCount() >= $this->hubSettings->minUpcomingSessions()
            || $summary->verifiedVenuesCount >= $this->hubSettings->minVerifiedVenues();
    }

    /**
     * All currently-qualifying city summaries, keyed by nothing in
     * particular — consumers (sitemap + cross-links, 62-03) iterate it.
     * Hidden curated cities resolve null and drop out; featured ones pass
     * isQualifying and stay — both automatically, because curation is
     * enforced inside the resolution this iterates. Each city's
     * resolution is cached individually, so a warm call is one lookup per
     * distinct city slug; the cold pass is the full computation.
     *
     * @return Collection<int, CitySummary>
     */
    public function qualifyingCities(): Collection
    {
        return $this->knownCitySlugs()
            ->map(fn (string $slug): ?CitySummary => $this->resolveCity($slug))
            ->filter(fn (?CitySummary $summary): bool => $summary !== null)
            ->filter(fn (CitySummary $summary): bool => $this->isQualifying($summary))
            ->values();
    }

    /**
     * Featured curated cities for the discovery portal's rail (62-04 T06):
     * featured, unhidden rows — each resolved through the same cached
     * resolution every other surface uses, then kept only when a real hub
     * can serve it. Curation never conjures a hub: a featured row for a
     * slug with no (or ambiguous or hidden) locations resolves null and
     * drops out, and isQualifying() keeps only summaries a hub link can
     * safely point at — featured ones force-qualify over both thresholds
     * (MEM995), the rest pass on their own activity. The hidden filter in
     * the query is defense-in-depth: hidden rows resolve null anyway.
     *
     * @return Collection<int, CitySummary>
     */
    public function featuredCities(): Collection
    {
        return City::query()
            ->where('featured', true)
            ->where('hidden', false)
            ->orderBy('city')
            ->limit(self::SECTION_LIMIT)
            ->get()
            ->map(fn (City $city): ?CitySummary => $this->resolveCity($city->slug))
            ->filter(fn (?CitySummary $summary): bool => $summary !== null)
            ->filter(fn (CitySummary $summary): bool => $this->isQualifying($summary))
            ->values();
    }

    /**
     * Sitemap lastmod for one city hub: the max updated_at across the
     * cluster's locations and every entity the hub's content derives from
     * — upcoming public games, active public campaigns with an upcoming
     * in-cluster session, and public upcoming events. Mirrors the count
     * queries' visibility/status/window semantics exactly, so lastmod moves
     * exactly when qualifying hub content changes. Null when nothing
     * relevant exists (the sitemap falls back to today).
     *
     * Each query uses orderByDesc + value (not max): value hydrates the
     * model, so the datetime cast yields a Carbon instead of a raw string.
     */
    public function lastModifiedFor(CitySummary $city): ?Carbon
    {
        [$from, $to] = $this->upcomingWindow();

        $candidates = [
            Location::query()
                ->whereIn('id', $city->locationIds)
                ->orderByDesc('updated_at')
                ->value('updated_at'),
            Game::query()
                ->whereIn('location_id', $city->locationIds)
                ->where('visibility', Visibility::Public->value)
                ->where('status', GameStatus::Scheduled->value)
                ->whereBetween('date_time', [$from, $to])
                ->orderByDesc('updated_at')
                ->value('updated_at'),
            Campaign::query()
                ->where('visibility', Visibility::Public->value)
                ->where('status', CampaignStatus::Active->value)
                ->whereHas('sessions', fn ($query) => $query
                    ->whereIn('location_id', $city->locationIds)
                    ->where('status', GameStatus::Scheduled->value)
                    ->whereBetween('date_time', [$from, $to]))
                ->orderByDesc('updated_at')
                ->value('updated_at'),
            Event::query()
                ->whereIn('location_id', $city->locationIds)
                ->where('is_public', true)
                ->whereIn('status', [
                    EventStatus::Published->value,
                    EventStatus::RegistrationOpen->value,
                    EventStatus::RegistrationClosed->value,
                    EventStatus::InProgress->value,
                ])
                ->whereBetween('start_date', [$from, $to])
                ->orderByDesc('updated_at')
                ->value('updated_at'),
        ];

        $latest = collect($candidates)
            ->filter(fn ($updatedAt): bool => $updatedAt instanceof Carbon)
            ->max();

        return $latest instanceof Carbon ? $latest : null;
    }

    /**
     * Upcoming public sessions for the hub's sessions section: future
     * scheduled public games (both discovery forks — the query is not
     * game-system-type scoped, so boardgame and ttrpg sessions surface
     * together), active public campaigns with an upcoming in-cluster
     * session, and public events at cluster locations. Matches the guard
     * counts' visibility/status/window semantics exactly, so what a hub
     * qualifies on is what it can list.
     *
     * Items are merged chronologically (game date_time / campaign next
     * session / event start_date) and each is tagged with runtime
     * attributes — hub_item_type ('game'|'campaign'|'event') and
     * hub_sort_at (Carbon) — the same tagged-attribute pattern discovery
     * uses for discoverable_type, letting one Blade loop render mixed
     * entities with the existing card partials.
     *
     * @return Collection<int, Game|Campaign|Event>
     */
    public function upcomingSessions(CitySummary $city): Collection
    {
        [$from, $to] = $this->upcomingWindow();

        $games = $this->upcomingGamesFor($city->locationIds, $from, $to);
        $campaigns = $this->upcomingCampaignsFor($city->locationIds, $from, $to);
        $events = $this->upcomingEventsFor($city->locationIds, $from, $to);

        return $games
            ->merge($campaigns)
            ->merge($events)
            ->sortBy(fn (Game|Campaign|Event $item) => $item->hub_sort_at)
            ->take(self::SECTION_LIMIT)
            ->values();
    }

    /**
     * Verified venues for the hub's venues section — the same eligibility
     * rule as countVerifiedVenues (publicVenuePage scope + slug), so the
     * listed set can never disagree with the count that qualified the
     * city. Ordered by name for a stable, deterministic listing.
     *
     * @return Collection<int, Location>
     */
    public function verifiedVenues(CitySummary $city): Collection
    {
        return Location::query()
            ->whereIn('id', $city->locationIds)
            ->publicVenuePage()
            ->whereNotNull('slug')
            ->orderBy('name')
            ->limit(self::SECTION_LIMIT)
            ->get();
    }

    /**
     * Forget one city's cached resolution (summary + status). The single
     * external flush hook for city summary caches: CACHE_PREFIX is private
     * so callers cannot re-key the cache themselves — invalidation goes
     * through here (CityHubCacheObserver, and 62-04's curated-city save
     * flush). A plain Cache::forget; the next resolveCity() recomputes.
     */
    public function forget(string $slug): void
    {
        Cache::forget(self::CACHE_PREFIX.Str::slug($slug));
    }

    /**
     * Forget every known city's cached resolution and return the count.
     * The threshold-change invalidation path (CityHubSettings::set in
     * Filament): threshold edits change which cached summaries belong in
     * the qualifying set, so an admin change drops them all to recompute.
     * Iterates the existing per-slug forget() — the single external flush
     * hook — instead of Cache::tags, which this app never uses.
     */
    public function forgetAll(): int
    {
        $slugs = $this->knownCitySlugs();

        $slugs->each(fn (string $slug) => $this->forget($slug));

        return $slugs->count();
    }

    /**
     * Public known-slug accessor for the scheduled cityhubs:recompute
     * command (62-04 T07): every city whose summary cache the command
     * forgets and re-warms. A thin delegation so the derivation (distinct
     * city values pulled and Str::slug'd in PHP — slug() cannot run in
     * SQL) stays owned by knownCitySlugs() in one place.
     *
     * @return Collection<int, string>
     */
    public function citySlugs(): Collection
    {
        return $this->knownCitySlugs();
    }

    /**
     * Cache wrapper. The closure ALWAYS returns an array — Cache::remember
     * does not persist null returns (they re-run the closure), so negative
     * resolutions are encoded as status-only arrays instead.
     *
     * @return array{status: string, summary: array<string, mixed>|null}
     */
    private function cachedResolution(string $slug): array
    {
        $slug = Str::slug($slug);

        return Cache::remember(
            self::CACHE_PREFIX.$slug,
            now()->addSeconds($this->configInt('cityhubs.cache_ttl', 900)),
            fn () => $this->computeResolution($slug),
        );
    }

    /**
     * @return array{status: string, summary: array<string, mixed>|null}
     */
    private function computeResolution(string $slug): array
    {
        if ($slug === '') {
            return ['status' => self::STATUS_NOT_FOUND, 'summary' => null];
        }

        $locations = $this->locationsForSlug($slug);

        if ($locations->isEmpty()) {
            // A curated row for an unknown slug must never conjure a hub:
            // no locations is still not_found, however the row is flagged.
            return ['status' => self::STATUS_NOT_FOUND, 'summary' => null];
        }

        $clusters = $locations
            ->groupBy(fn (Location $location) => substr((string) $location->geohash_4, 0, self::CLUSTER_GEOHASH_PREFIX_LENGTH))
            ->filter(fn (Collection $cluster, string $prefix) => $prefix !== '');

        $curated = City::query()->where('slug', $slug)->first();

        if ($curated?->hidden === true) {
            // Hidden beats every other flag — featured included — and every
            // public surface: the hub, the sitemap, the rail (62-04).
            // Sentinel-cached like the other negatives because
            // Cache::remember cannot persist null.
            return ['status' => self::STATUS_HIDDEN, 'summary' => null];
        }

        if ($clusters->count() > 1) {
            $cluster = $this->disambiguatedCluster($clusters, $curated?->region_prefix);

            if ($cluster === null) {
                // Same city name in separated regions with no exact curated
                // prefix pin: never merge, never guess.
                return ['status' => self::STATUS_AMBIGUOUS, 'summary' => null];
            }
        } else {
            $cluster = $clusters->first();
        }

        if ($cluster === null) {
            return ['status' => self::STATUS_NOT_FOUND, 'summary' => null];
        }

        $firstLocation = $cluster->first();

        if ($firstLocation === null) {
            return ['status' => self::STATUS_NOT_FOUND, 'summary' => null];
        }

        $locationIds = $cluster->map(fn (Location $location): string => $location->id);

        $summary = new CitySummary(
            slug: $slug,
            city: (string) $this->mostFrequent($cluster->map(fn (Location $location): ?string => $location->city)),
            country: $this->mostFrequent($cluster->map(fn (Location $location): ?string => $location->country)),
            regionPrefix: substr((string) $firstLocation->geohash_4, 0, self::CLUSTER_GEOHASH_PREFIX_LENGTH),
            geohashTiles: $this->nonEmptyStrings($cluster->map(fn (Location $location): ?string => $location->geohash_4)),
            locationIds: $locationIds->values()->all(),
            upcomingGamesCount: $this->countUpcomingGames($locationIds),
            upcomingCampaignsCount: $this->countUpcomingCampaigns($locationIds),
            upcomingEventsCount: $this->countUpcomingEvents($locationIds),
            verifiedVenuesCount: $this->countVerifiedVenues($locationIds),
            featured: $curated?->featured === true,
            intro: $curated instanceof City ? $curated->getTranslations('intro') : [],
        );

        return ['status' => self::STATUS_OK, 'summary' => $summary->toArray()];
    }

    /**
     * The one cluster a curated region_prefix pins an ambiguous
     * (multi-region) city to: the cluster whose 3-char geohash prefix
     * EXACTLY equals the stored prefix (62-04). A wrong or missing prefix
     * returns null — the city stays ambiguous rather than guessed.
     *
     * @param  Collection<string, Collection<int, Location>>  $clusters
     * @return Collection<int, Location>|null
     */
    private function disambiguatedCluster(Collection $clusters, ?string $regionPrefix): ?Collection
    {
        if ($regionPrefix === null) {
            return null;
        }

        $pinned = $clusters->get($regionPrefix);

        return $pinned instanceof Collection ? $pinned : null;
    }

    /**
     * Every distinct city slug derivable from geocoded locations — the
     * candidate universe for qualifyingCities() and the invalidation
     * universe for forgetAll(). Str::slug cannot run in SQL, so distinct
     * city values are pulled and normalized in PHP (62-04 extraction of
     * the iteration previously inline in qualifyingCities).
     *
     * @return Collection<int, string>
     */
    private function knownCitySlugs(): Collection
    {
        return Location::query()
            ->whereNotNull('city')
            ->whereNotNull('geohash_4')
            ->distinct()
            ->pluck('city')
            ->filter(fn ($city): bool => is_string($city))
            ->map(fn (string $city): string => Str::slug($city))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * All geocoded locations whose city column normalizes to the slug.
     * Str::slug cannot run in SQL, so distinct city values are pulled and
     * matched in PHP, then the matching (exact) values are re-queried.
     *
     * @param  string  $slug  Pre-normalized (already Str::slug'd).
     * @return Collection<int, Location>
     */
    private function locationsForSlug(string $slug): Collection
    {
        $matchingCities = Location::query()
            ->whereNotNull('city')
            ->whereNotNull('geohash_4')
            ->distinct()
            ->pluck('city')
            ->filter(fn ($city): bool => is_string($city) && Str::slug($city) === $slug)
            ->values();

        if ($matchingCities->isEmpty()) {
            return collect();
        }

        return Location::query()
            ->whereIn('city', $matchingCities->all())
            ->whereNotNull('geohash_4')
            ->get(['id', 'city', 'country', 'geohash_4']);
    }

    /**
     * Future scheduled public games at cluster locations within the window —
     * the same visibility/status/future semantics as discovery's
     * buildGamesQuery (public + scheduled + future), applied to the city
     * cluster instead of a proximity radius.
     *
     * @param  Collection<int, string>  $locationIds
     */
    private function countUpcomingGames(Collection $locationIds): int
    {
        [$from, $to] = $this->upcomingWindow();

        return Game::query()
            ->whereIn('location_id', $locationIds)
            ->where('visibility', Visibility::Public->value)
            ->where('status', GameStatus::Scheduled->value)
            ->whereBetween('date_time', [$from, $to])
            ->count();
    }

    /**
     * Active public campaigns with at least one future scheduled session at
     * a cluster location — campaign city membership flows through session
     * locations (the same shape discovery's campaign proximity subquery
     * uses), so a campaign based outside the city with sessions inside it
     * still counts, and one with no upcoming in-city sessions does not.
     *
     * @param  Collection<int, string>  $locationIds
     */
    private function countUpcomingCampaigns(Collection $locationIds): int
    {
        [$from, $to] = $this->upcomingWindow();

        return Campaign::query()
            ->where('visibility', Visibility::Public->value)
            ->where('status', CampaignStatus::Active->value)
            ->whereHas('sessions', fn ($query) => $query
                ->whereIn('location_id', $locationIds)
                ->where('status', GameStatus::Scheduled->value)
                ->whereBetween('date_time', [$from, $to]))
            ->count();
    }

    /**
     * Public events starting within the window at cluster locations — the
     * same is_public + public-status set the public event listing uses
     * (published, registration_open, registration_closed, in_progress).
     *
     * @param  Collection<int, string>  $locationIds
     */
    private function countUpcomingEvents(Collection $locationIds): int
    {
        [$from, $to] = $this->upcomingWindow();

        return Event::query()
            ->whereIn('location_id', $locationIds)
            ->where('is_public', true)
            ->whereIn('status', [
                EventStatus::Published->value,
                EventStatus::RegistrationOpen->value,
                EventStatus::RegistrationClosed->value,
                EventStatus::InProgress->value,
            ])
            ->whereBetween('start_date', [$from, $to])
            ->count();
    }

    /**
     * Verified-venue count reusing Location::scopePublicVenuePage semantics
     * (verified commercial OR admin-managed commercial). The slug guard
     * matches the scope's own docblock rule: only slug-bearing venues can
     * be linked from a hub, so unslottable rows must not inflate the count.
     *
     * @param  Collection<int, string>  $locationIds
     */
    private function countVerifiedVenues(Collection $locationIds): int
    {
        return Location::query()
            ->whereIn('id', $locationIds)
            ->publicVenuePage()
            ->whereNotNull('slug')
            ->count();
    }

    // ── Section list queries (T03) ────────────────────────────────────────

    /**
     * Upcoming public games list for the sessions section — the same query
     * shape as countUpcomingGames plus discovery's eager loads (owner,
     * gameSystems, campaign, linkedLocation, approved participant count) so
     * the existing game-card partial renders hub items unchanged. Not
     * scoped by game-system type: boardgame and ttrpg sessions (both
     * discovery forks) surface together.
     *
     * @param  array<int, string>  $locationIds
     * @return Collection<int, Game>
     */
    private function upcomingGamesFor(array $locationIds, Carbon $from, Carbon $to): Collection
    {
        return Game::query()
            ->whereIn('location_id', $locationIds)
            ->where('visibility', Visibility::Public->value)
            ->where('status', GameStatus::Scheduled->value)
            ->whereBetween('date_time', [$from, $to])
            ->with(['owner', 'gameSystems', 'campaign', 'linkedLocation'])
            ->withCount(['participants as participants_count' => fn ($query) => $query
                ->where('status', ParticipantStatus::Approved->value)])
            ->withCount(['participants as waitlisted_count' => fn ($query) => $query
                ->where('status', ParticipantStatus::Waitlisted->value)])
            ->withCount(['participants as benched_count' => fn ($query) => $query
                ->where('status', ParticipantStatus::Benched->value)])
            ->orderBy('date_time')
            ->limit(self::SECTION_LIMIT)
            ->get()
            ->each(fn (Game $game) => $this->tagHubItem($game, 'game', $game->date_time));
    }

    /**
     * Active public campaigns list for the sessions section — the same
     * whereHas shape as countUpcomingCampaigns plus discovery's
     * buildCampaignsQuery eager loads (constrained next-session relation,
     * session/participant counts) so campaign-card renders unchanged. The
     * sort key falls back to created_at when the constrained next-session
     * relation hydrates empty (edge: sessions slipped past its now() bound
     * between the whereHas and hydration).
     *
     * @param  array<int, string>  $locationIds
     * @return Collection<int, Campaign>
     */
    private function upcomingCampaignsFor(array $locationIds, Carbon $from, Carbon $to): Collection
    {
        return Campaign::query()
            ->where('visibility', Visibility::Public->value)
            ->where('status', CampaignStatus::Active->value)
            ->whereHas('sessions', fn ($query) => $query
                ->whereIn('location_id', $locationIds)
                ->where('status', GameStatus::Scheduled->value)
                ->whereBetween('date_time', [$from, $to]))
            ->with(['owner', 'gameSystems'])
            ->with(['sessions' => fn ($query) => $query
                ->where('status', GameStatus::Scheduled->value)
                ->where('date_time', '>', now())
                ->orderBy('date_time')
                ->limit(1)])
            ->withCount('sessions')
            ->withCount('participants')
            ->withCount(['participants as waitlisted_count' => fn ($query) => $query
                ->where('status', ParticipantStatus::Waitlisted->value)])
            ->withCount(['participants as benched_count' => fn ($query) => $query
                ->where('status', ParticipantStatus::Benched->value)])
            ->orderByDesc('created_at')
            ->limit(self::SECTION_LIMIT)
            ->get()
            ->each(function (Campaign $campaign): void {
                $nextSession = $campaign->sessions->first();

                $this->tagHubItem(
                    $campaign,
                    'campaign',
                    $nextSession instanceof Game ? $nextSession->date_time : $campaign->created_at,
                );
            });
    }

    /**
     * Public upcoming events list for the sessions section — the same
     * public-visibility semantics as countUpcomingEvents. The standalone
     * x-event-card component needs no relation eager loads.
     *
     * @param  array<int, string>  $locationIds
     * @return Collection<int, Event>
     */
    private function upcomingEventsFor(array $locationIds, Carbon $from, Carbon $to): Collection
    {
        return Event::query()
            ->whereIn('location_id', $locationIds)
            ->where('is_public', true)
            ->whereIn('status', [
                EventStatus::Published->value,
                EventStatus::RegistrationOpen->value,
                EventStatus::RegistrationClosed->value,
                EventStatus::InProgress->value,
            ])
            ->whereBetween('start_date', [$from, $to])
            ->orderBy('start_date')
            ->limit(self::SECTION_LIMIT)
            ->get()
            ->each(fn (Event $event) => $this->tagHubItem($event, 'event', $event->start_date));
    }

    /**
     * Tag a hub section item with its render type and chronological sort
     * key. Runtime attributes (not columns) — the same pattern discovery
     * uses for discoverable_type on merged results, consumed by the
     * sessions partial to pick the right card per item.
     */
    private function tagHubItem(Game|Campaign|Event $item, string $type, ?Carbon $sortAt): void
    {
        $item->hub_item_type = $type;
        $item->hub_sort_at = $sortAt ?? now();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function upcomingWindow(): array
    {
        $days = $this->configInt('cityhubs.upcoming_window_days', 30);

        return [now(), now()->addDays($days)];
    }

    /**
     * Most frequent non-empty value (canonical display form), null when all
     * values are empty.
     *
     * @param  Collection<int, string|null>  $values
     */
    private function mostFrequent(Collection $values): ?string
    {
        $top = $values
            ->filter(fn ($value): bool => filled($value))
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        return is_string($top) ? $top : null;
    }

    /**
     * Non-empty distinct string values in encounter order (geohash tiles).
     *
     * @param  Collection<int, string|null>  $values
     * @return array<int, string>
     */
    private function nonEmptyStrings(Collection $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * Integer config value keeping the historic (int) coercion semantics
     * for numeric strings (env-provided values), defaulting when unset or
     * non-numeric.
     */
    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
