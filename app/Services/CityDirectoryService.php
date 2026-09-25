<?php

namespace App\Services;

use App\Dto\CitySummary;
use App\Enums\CampaignStatus;
use App\Enums\EventStatus;
use App\Enums\GameStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
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
