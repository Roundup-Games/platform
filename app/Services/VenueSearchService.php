<?php

namespace App\Services;

use App\Dto\VenueSearchResult;
use App\Enums\VenueType;
use App\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Search verified venues with proximity sorting.
 *
 * Provides venue search for the VenuePicker component:
 * - Proximity-ordered results using Haversine distance
 * - Text search by venue name and city
 * - Optional venue type filtering
 */
class VenueSearchService
{
    private const EARTH_RADIUS_KM = 6371;

    /**
     * Search verified venues near a point, optionally filtered by text query.
     *
     * @return Collection<int, VenueSearchResult>
     */
    public function search(
        ?float $lat = null,
        ?float $lng = null,
        ?string $query = null,
        ?string $venueType = null,
        float $radiusKm = 50,
        int $limit = 20,
    ): Collection {
        $qb = Location::query()
            ->where('is_verified', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        // Text search on name and city
        if ($query && trim($query) !== '') {
            $escaped = $this->escapeLikePattern($query);
            $qb->where(function ($q) use ($escaped) {
                $q->where('name', 'ILIKE', "%{$escaped}%")
                    ->orWhere('city', 'ILIKE', "%{$escaped}%")
                    ->orWhere('address', 'ILIKE', "%{$escaped}%");
            });
        }

        // Venue type filter
        if ($venueType && VenueType::tryFrom($venueType)) {
            $qb->where('venue_type', $venueType);
        }

        // No coordinates: alphabetical fallback
        if ($lat === null || $lng === null) {
            return $qb->orderBy('name')
                ->limit($limit)
                ->get()
                ->map(fn (Location $loc) => $this->formatResult($loc, null));
        }

        // Proximity ordering with Haversine (subquery pattern for PostgreSQL)
        $bounds = app(ProximityQuery::class)->boundingBox($lat, $lng, $radiusKm);
        /** @var literal-string $distSql */
        [$distSql, $distBindings] = $this->haversineSql(
            'locations.latitude', 'locations.longitude', $lat, $lng,
        );

        // Inner query: compute distance and filter by bounding box
        $innerQuery = DB::table('locations')
            ->selectRaw("locations.id, locations.name, locations.city, locations.address, locations.venue_type, {$distSql} AS distance_km", $distBindings)
            ->where('is_verified', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$bounds->minLat, $bounds->maxLat])
            ->whereBetween('longitude', [$bounds->minLng, $bounds->maxLng]);

        if ($query && trim($query) !== '') {
            $escaped = $this->escapeLikePattern($query);
            $innerQuery->where(function ($q) use ($escaped) {
                $q->where('name', 'ILIKE', "%{$escaped}%")
                    ->orWhere('city', 'ILIKE', "%{$escaped}%")
                    ->orWhere('address', 'ILIKE', "%{$escaped}%");
            });
        }

        if ($venueType && VenueType::tryFrom($venueType)) {
            $innerQuery->where('venue_type', $venueType);
        }

        // Outer query: filter by exact radius using the computed distance
        /** @var literal-string $subSql */
        $subSql = "({$innerQuery->toSql()}) AS proxied";

        return DB::table(DB::raw($subSql))
            ->mergeBindings($innerQuery)
            ->where('distance_km', '<=', $radiusKm)
            ->orderBy('distance_km')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => $this->resultFromRow($row));
    }

    /**
     * Get a single venue by ID (must be verified).
     */
    public function findVenue(string $id): ?Location
    {
        return Location::where('id', $id)
            ->where('is_verified', true)
            ->first();
    }

    /**
     * @return list{string, list<int|float>}
     */
    private function haversineSql(string $latCol, string $lngCol, float $centerLat, float $centerLng): array
    {
        $sql = "(? * 2 * ASIN(SQRT(
            POWER(SIN(RADIANS({$latCol} - ?) / 2), 2) +
            COS(RADIANS(?)) * COS(RADIANS({$latCol})) *
            POWER(SIN(RADIANS({$lngCol} - ?) / 2), 2)
        )))";

        return [$sql, [self::EARTH_RADIUS_KM, $centerLat, $centerLat, $centerLng]];
    }

    private function formatResult(Location $loc, ?float $distanceKm): VenueSearchResult
    {
        return new VenueSearchResult(
            id: $loc->id,
            name: $loc->name,
            city: $loc->city,
            address: $loc->address,
            venueType: $loc->venue_type?->value,
            distanceKm: $distanceKm,
        );
    }

    /**
     * Hydrate a VenueSearchResult from a raw proximity-query row.
     *
     * The row comes from a selectRaw() Haversine subquery, so its columns
     * are untyped (mixed). We narrow each field explicitly: id/name are
     * NOT NULL columns, the rest are nullable text plus the computed float.
     */
    private function resultFromRow(object $row): VenueSearchResult
    {
        /** @var object{id: mixed, name: mixed, city: mixed, address: mixed, venue_type: mixed, distance_km: mixed} $row */
        return new VenueSearchResult(
            id: is_string($row->id) ? $row->id : '',
            name: is_string($row->name) ? $row->name : '',
            city: is_string($row->city) ? $row->city : null,
            address: is_string($row->address) ? $row->address : null,
            venueType: is_string($row->venue_type) ? $row->venue_type : null,
            distanceKm: is_numeric($row->distance_km) ? round((float) $row->distance_km, 1) : null,
        );
    }

    /**
     * Escape special LIKE wildcard characters in user input.
     *
     * Prevents % and _ in user queries from being interpreted as SQL wildcards.
     */
    private function escapeLikePattern(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
