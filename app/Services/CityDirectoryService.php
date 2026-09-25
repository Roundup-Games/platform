<?php

namespace App\Services;

use App\Dto\CitySummary;
use App\Enums\CampaignStatus;
use App\Enums\EventStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use Illuminate\Database\Eloquent\Model;
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
 * and resolves to null until 62-04 curation disambiguates it.
 *
 * Qualification is OR-based: a city qualifies on either the upcoming public
 * activity threshold (config cityhubs.min_upcoming_sessions) or the verified
 * venue threshold (cityhubs.min_verified_venues). Non-qualifying cities get
 * a real 404 downstream — never a soft empty page.
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

    /**
     * Resolve a city slug to its cluster summary, or null when the slug is
     * unknown or ambiguous. The full resolution (cluster + activity counts)
     * is cached; negative results are cached too so 404 traffic for
     * non-qualifying cities does not re-run the queries per request.
     */
    public function resolveCity(string $slug): ?CitySummary
    {
        $resolution = $this->cachedResolution($slug);

        return $resolution['status'] === self::STATUS_OK
            ? CitySummary::fromArray($resolution['summary'])
            : null;
    }

    /**
     * Resolution status for guard-rejection logging: 'ok', 'not_found', or
     * 'ambiguous'. The third rejection reason, 'below_threshold', is derived
     * by the caller when status is 'ok' but isQualifying() is false — the
     * structured cityhub.rejected log composes all three (T04).
     */
    public function resolveStatus(string $slug): string
    {
        return $this->cachedResolution($slug)['status'];
    }

    /**
     * Does this city meet either qualification threshold? Either the
     * upcoming public activity count (games + campaigns + events) or the
     * verified-venue count suffices.
     */
    public function isQualifying(CitySummary $summary): bool
    {
        return $summary->upcomingActivityCount() >= (int) config('cityhubs.min_upcoming_sessions', 3)
            || $summary->verifiedVenuesCount >= (int) config('cityhubs.min_verified_venues', 2);
    }

    /**
     * All currently-qualifying city summaries, keyed by nothing in
     * particular — consumers (sitemap + cross-links, 62-03) iterate it.
     * Each city's resolution is cached individually, so a warm call is one
     * lookup per distinct city slug; the cold pass is the full computation.
     *
     * @return Collection<int, CitySummary>
     */
    public function qualifyingCities(): Collection
    {
        return Location::query()
            ->whereNotNull('city')
            ->whereNotNull('geohash_4')
            ->distinct()
            ->pluck('city')
            ->map(fn ($city) => Str::slug((string) $city))
            ->filter()
            ->unique()
            ->map(fn (string $slug) => $this->resolveCity($slug))
            ->filter(fn (?CitySummary $summary) => $summary !== null)
            ->filter(fn (CitySummary $summary) => $this->isQualifying($summary))
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

        return collect($candidates)
            ->filter(fn ($updatedAt) => $updatedAt !== null)
            ->max();
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
            now()->addSeconds((int) config('cityhubs.cache_ttl', 900)),
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
            return ['status' => self::STATUS_NOT_FOUND, 'summary' => null];
        }

        $clusters = $locations
            ->groupBy(fn (Location $location) => substr((string) $location->geohash_4, 0, self::CLUSTER_GEOHASH_PREFIX_LENGTH))
            ->filter(fn (Collection $cluster, string $prefix) => $prefix !== '');

        if ($clusters->count() > 1) {
            // Same city name in separated regions: never merge, never guess.
            // Treated as non-qualifying (404) until 62-04 curation picks one.
            return ['status' => self::STATUS_AMBIGUOUS, 'summary' => null];
        }

        $cluster = $clusters->first();
        $locationIds = $cluster->pluck('id');

        $summary = new CitySummary(
            slug: $slug,
            city: (string) $this->mostFrequent($cluster->pluck('city')),
            country: $this->mostFrequent($cluster->pluck('country')),
            regionPrefix: substr((string) $cluster->first()->geohash_4, 0, self::CLUSTER_GEOHASH_PREFIX_LENGTH),
            geohashTiles: $cluster->pluck('geohash_4')->filter()->unique()->values()->all(),
            locationIds: $locationIds->values()->all(),
            upcomingGamesCount: $this->countUpcomingGames($locationIds),
            upcomingCampaignsCount: $this->countUpcomingCampaigns($locationIds),
            upcomingEventsCount: $this->countUpcomingEvents($locationIds),
            verifiedVenuesCount: $this->countVerifiedVenues($locationIds),
        );

        return ['status' => self::STATUS_OK, 'summary' => $summary->toArray()];
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
            ->filter(fn ($city) => Str::slug((string) $city) === $slug)
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
            ->each(fn (Campaign $campaign) => $this->tagHubItem(
                $campaign,
                'campaign',
                $campaign->sessions->first()?->date_time ?? $campaign->created_at,
            ));
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
    private function tagHubItem(Model $item, string $type, ?Carbon $sortAt): void
    {
        $item->hub_item_type = $type;
        $item->hub_sort_at = $sortAt ?? now();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function upcomingWindow(): array
    {
        $days = (int) config('cityhubs.upcoming_window_days', 30);

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
        return $values
            ->filter(fn ($value) => filled($value))
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();
    }
}
