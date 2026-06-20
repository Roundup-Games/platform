<?php

namespace App\Livewire\Venues;

use App\Enums\VenueType;
use App\Models\Game;
use App\Models\Location;
use App\Services\GeocodingService;
use App\Services\ProximityQuery;
use App\Traits\EscapesLikeWildcards;
use App\Traits\HasGuestLocation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * Public venue directory at /{locale}/venues.
 *
 * The browse/index surface for verified commercial venues — the piece M053
 * (venue pages, reviews, stewardship) deliberately left unbuilt. Individual
 * venue pages existed but had no entry point; this directory is that entry
 * point, geared towards *local discovery* (proximity-sorted, filterable by
 * type and activity) and linked from the footer under the GM Directory.
 *
 * Design notes:
 *
 *  - **Safety is inherited, not re-implemented.** The query sources
 *    exclusively from {@see Location::scopePublicVenuePage()} (the single
 *    authority for "which locations get a public page"), and every card's
 *    address + distance renders through the existing <x-location-display>
 *    and <x-distance-display> components — the sole address/distance surfaces.
 *    Verified commercial venues disclose exact address + precise distance;
 *    managed-but-unverified ones graduate down exactly as they do on the
 *    venue-detail page. No raw address attribute is ever read here.
 *
 *  - **Proximity.** Guest location is acquired through {@see HasGuestLocation}
 *    (cached on mount for returning visitors, "Use my location" prompt, or a
 *    manual city geocode). "Nearest first" sorts in SQL via the shared
 *    {@see ProximityQuery::haversineSelectExpression()} so pagination is
 *    correct; other sorts compute the per-card distance in PHP for the chip.
 *
 *  - **Pagination.** Load-more (12 → +12) mirroring the GM Directory, so a
 *    thin catalog is never paginated into lonely single-card pages.
 *
 *  - **Acquisition.** A thin catalog is normalised through propose/claim CTAs
 *    in the empty state and page footer, closing the M053/S04 stewardship loop.
 */
#[Layout('components.public-layout')]
class VenueDirectory extends Component
{
    use EscapesLikeWildcards;
    use HasGuestLocation;
    use WithPagination;

    // ── URL-synced filters ──────────────────────────────

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?string $venue_type = null;

    #[Url]
    public ?int $min_rating = null;

    #[Url]
    public bool $managed_only = false;

    #[Url]
    public bool $has_upcoming = false;

    #[Url(as: 'sort')]
    public string $sortBy = 'nearest';

    // ── Load-more (display count) ───────────────────────

    public int $displayCount = 12;

    // ── Manual city search ──────────────────────────────

    public ?string $cityQuery = null;

    /**
     * Pull a cached location for returning visitors without prompting. New
     * visitors use the "Use my location" button or a manual city search.
     */
    public function mount(): void
    {
        $this->requestGuestLocation();
    }

    // ── Updating hooks (reset paging on filter change) ──

    public function updatingSearch(): void
    {
        $this->displayCount = 12;
    }

    public function updatingVenueType(): void
    {
        $this->displayCount = 12;
    }

    public function updatingMinRating(): void
    {
        $this->displayCount = 12;
    }

    public function updatingManagedOnly(): void
    {
        $this->displayCount = 12;
    }

    public function updatingHasUpcoming(): void
    {
        $this->displayCount = 12;
    }

    public function updatingSortBy(): void
    {
        $this->displayCount = 12;
    }

    // ── Actions ─────────────────────────────────────────

    public function toggleVenueType(string $value): void
    {
        $this->venue_type = $this->venue_type === $value ? null : $value;
        $this->displayCount = 12;
    }

    public function clearFilters(): void
    {
        // reset() restores each property to its declared default (sortBy →
        // 'nearest', displayCount → 12), keeping this in one place rather than
        // hand-resetting two fields after the reset() call.
        $this->reset(['search', 'venue_type', 'min_rating', 'managed_only', 'has_upcoming', 'sortBy', 'displayCount']);
    }

    public function loadMore(): void
    {
        $this->displayCount += 12;
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->venue_type !== null
            || $this->min_rating !== null
            || $this->managed_only
            || $this->has_upcoming;
    }

    /**
     * Prompt the browser for geolocation (user-initiated "Use my location").
     */
    public function locateMe(): void
    {
        $this->js(<<<'JS'
            if (window.GuestLocation) {
                window.GuestLocation.locateAndDispatch('venue-directory');
            }
        JS);
    }

    /**
     * Geocode a manually-entered city and adopt it as the guest location.
     */
    public function searchCity(): void
    {
        $this->validate([
            'cityQuery' => 'required|string|min:2|max:200',
        ]);

        try {
            $result = app(GeocodingService::class)->geocode((string) $this->cityQuery);

            if (! empty($result)) {
                $this->guestLat = (float) $result['lat'];
                $this->guestLng = (float) $result['lng'];
                $this->guestLocationSource = 'manual';
                $this->cityQuery = null;
                $this->displayCount = 12;

                // Persist to localStorage so the location survives navigation,
                // matching NearbySessions — returning visits reuse it without
                // re-prompting.
                $lat = $this->guestLat;
                $lng = $this->guestLng;
                $this->js(<<<JS
                    if (window.GuestLocation) {
                        window.GuestLocation.setGuestLocation({$lat}, {$lng}, 'manual');
                    }
                JS);
            } else {
                $this->addError('cityQuery', __('location.error_city_not_found'));
            }
        } catch (\Throwable) {
            $this->addError('cityQuery', __('location.error_geocoding_failed'));
        }
    }

    public function clearLocation(): void
    {
        $this->clearGuestLocation();
        $this->displayCount = 12;
    }

    // ── Render ──────────────────────────────────────────

    public function render(): View
    {
        seo(new SEOData(
            title: __('venue.seo_directory_title'),
            description: __('venue.seo_directory_description'),
        ));

        $hasLocation = $this->hasGuestLocation();

        $query = Location::whereNotNull('slug')->publicVenuePage();

        $this->applyFilters($query);

        // Always carry the upcoming-sessions count: the card's liveness signal
        // and the "Most active" sort both read it, and a single correlated
        // subquery is cheap relative to the rest of the page.
        $query->withCount([
            'games as upcoming_sessions_count' => function (Builder $q): void {
                // The closure receives the related Game builder; Larastan cannot
                // infer that from the withCount() context, so we narrow it.
                /** @var Builder<Game> $gq */
                $gq = $q;
                $gq->public()->scheduled()->where('date_time', '>', now());
            },
        ]);

        $effectiveSort = $this->effectiveSort();
        $sortedNearest = $effectiveSort === 'nearest';

        match ($effectiveSort) {
            'nearest' => $this->applyNearestSort($query),
            'active' => $query->orderByDesc('upcoming_sessions_count')
                ->orderByRaw('COALESCE(average_rating, 0) DESC'),
            'rating' => $query->orderByRaw('COALESCE(average_rating, 0) DESC')
                ->orderByDesc('review_count'),
            'newest' => $query->orderByDesc('created_at'),
            default => $query->orderBy('name'),
        };

        $results = $query->paginate($this->displayCount);

        // Attach a per-card distance for the chip. Nearest sort already carries
        // distance_km from the SQL select; every other sort computes it in PHP
        // from the guest location. The chip renders through <x-distance-display>
        // (the disclosure authority), so this value is a raw input to display,
        // never rendered directly.
        if ($hasLocation && ! $sortedNearest && $this->guestLat !== null && $this->guestLng !== null) {
            $lat = $this->guestLat;
            $lng = $this->guestLng;
            foreach ($results->getCollection() as $venue) {
                // Skip venues without coordinates: casting null to float yields
                // 0.0, which would compute a bogus distance to (0, 0). Leaving
                // distance_km unset keeps the chip hidden (the blade guards on
                // isset($venue->distance_km)) rather than showing a wrong value.
                if ($venue->latitude === null || $venue->longitude === null) {
                    continue;
                }
                $venue->distance_km = ProximityQuery::haversineDistance(
                    (float) $venue->latitude,
                    (float) $venue->longitude,
                    $lat,
                    $lng,
                );
            }
        }

        return view('livewire.venues.venue-directory', [
            'results' => $results,
            'venueTypes' => VenueType::COMMERCIAL_TYPES,
            'hasLocation' => $hasLocation,
            'effectiveSort' => $effectiveSort,
        ]);
    }

    // ── Query helpers ───────────────────────────────────

    /**
     * Apply the URL-synced filter set to the base public-venue-page query.
     *
     * Each filter is wrapped in its own closure so the OR at the heart of
     * {@see Location::scopePublicVenuePage()} keeps its precedence.
     *
     * @param  Builder<Location>  $query
     */
    private function applyFilters(Builder $query): void
    {
        if ($this->search !== '') {
            $escaped = $this->escapeLikeWildcards($this->search);
            $like = $this->likeOperator();
            $query->where(function (Builder $q) use ($escaped, $like) {
                $q->where('name', $like, "%{$escaped}%")
                    ->orWhere('city', $like, "%{$escaped}%")
                    // Only match the street-level address on verified venues.
                    // Managed-but-unverified venues also appear in this index;
                    // their exact address is graduated-down by disclosure, so
                    // searching it directly must not act as an existence oracle
                    // for a hidden address. Name and city stay searchable for all
                    // (name is the venue's public identity; city is coarse).
                    ->orWhere(function (Builder $aq) use ($escaped, $like): void {
                        $aq->where('is_verified', true)
                            ->where('address', $like, "%{$escaped}%");
                    });
            });
        }

        if ($this->venue_type !== null && VenueType::tryFrom($this->venue_type) !== null) {
            $query->where('venue_type', $this->venue_type);
        }

        if ($this->min_rating !== null && $this->min_rating > 0) {
            $query->where('average_rating', '>=', $this->min_rating);
        }

        if ($this->managed_only) {
            $query->whereNotNull('managed_by');
        }

        if ($this->has_upcoming) {
            $query->whereHas('games', function (Builder $q): void {
                /** @var Builder<Game> $gq */
                $gq = $q;
                $gq->public()->scheduled()->where('date_time', '>', now());
            });
        }
    }

    /**
     * Resolve the sort actually in effect: "nearest" requires a guest location,
     * so without one it degrades to "most active" (the next-most-useful default
     * for a directory whose value is liveness).
     */
    private function effectiveSort(): string
    {
        if ($this->sortBy === 'nearest' && ! $this->hasGuestLocation()) {
            return 'active';
        }

        return $this->sortBy;
    }

    /**
     * Add a Haversine distance column and order by it.
     *
     * Selects locations.* explicitly alongside the computed column so the
     * paginated models hydrate with both their attributes and distance_km.
     *
     * @param  Builder<Location>  $query
     */
    private function applyNearestSort(Builder $query): void
    {
        if ($this->guestLat === null || $this->guestLng === null) {
            // Defensive: only reached when effectiveSort() === 'nearest',
            // which already requires a guest location. Kept for type safety.
            return;
        }

        [$sql, $bindings] = ProximityQuery::haversineSelectExpression(
            'locations.latitude',
            'locations.longitude',
            $this->guestLat,
            $this->guestLng,
        );

        /** @var literal-string $rawSql */
        $rawSql = "{$sql} AS distance_km";

        // addSelect (not select): withCount() above registered the
        // upcoming_sessions_count subquery in the column projection. select()
        // resets the columns array and would drop that count, so the activity
        // signal would silently disappear on the nearest (default) sort.
        // addSelect() appends locations.* alongside the count and the distance.
        $query->addSelect('locations.*')
            ->selectRaw($rawSql, $bindings)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('distance_km');
    }
}
