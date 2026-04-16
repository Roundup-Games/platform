<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Proximity query builder with bounding box pre-filter and Haversine sort.
 *
 * Provides two main operations:
 * 1. nearby() — Find games or events near a point, sorted by distance.
 * 2. hubs() — Find locations with active session counts, cached by geohash tile.
 *
 * Architecture:
 *   - Bounding box pre-filter uses composite (lat, lng) B-tree index for fast row elimination.
 *   - Haversine formula applied via subquery for precise distance (SQLite/MySQL compatible).
 *   - Hub results cached per geohash tile prefix (default 15min TTL).
 */
class ProximityQuery
{
    /**
     * Earth radius in kilometers for Haversine calculations.
     */
    private const EARTH_RADIUS_KM = 6371;

    /**
     * Default geohash prefix length for hub caching (≈2.4km × 4.9km tiles).
     */
    private const DEFAULT_GEOHASH_PRECISION = 5;

    /**
     * Default cache TTL for hub results in seconds (15 minutes).
     */
    private const HUB_CACHE_TTL = 900;

    /**
     * Map of entity types to their model classes and location relationship names.
     */
    private const ENTITY_MAP = [
        'game' => [
            'model' => Game::class,
            'relationship' => 'linkedLocation',
            'status_scope' => 'scheduled',
        ],
        'event' => [
            'model' => Event::class,
            'relationship' => 'linkedLocation',
        ],
    ];

    /**
     * Build the Haversine distance SQL expression.
     *
     * Returns [sql, bindings] where sql contains parameter placeholders.
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

    /**
     * Find entities near a given point within a radius, sorted by distance.
     *
     * Uses a two-phase approach:
     * 1. Bounding box pre-filter using the composite (lat, lng) index.
     * 2. Haversine distance calculation via subquery, filtering by exact radius.
     *
     * Uses a subquery wrapper (not HAVING) for SQLite compatibility.
     *
     * @param  float  $lat  Center latitude
     * @param  float  $lng  Center longitude
     * @param  float  $radiusKm  Search radius in kilometers (default 50)
     * @param  string  $entityType  'game' or 'event'
     * @param  array  $options  Additional options: limit, status_filter, with
     * @return \Illuminate\Support\Collection Each item has: entity, location, distance_km
     */
    public function nearby(float $lat, float $lng, float $radiusKm = 50, string $entityType = 'game', array $options = []): \Illuminate\Support\Collection
    {
        $startTime = microtime(true);

        $config = self::ENTITY_MAP[$entityType] ?? null;
        if (! $config) {
            return collect();
        }

        $model = $config['model'];
        $relationship = $config['relationship'];
        $limit = $options['limit'] ?? 50;

        // Calculate bounding box from center point and radius
        $bounds = $this->boundingBox($lat, $lng, $radiusKm);

        $table = (new $model)->getTable();
        [$distSql, $distBindings] = $this->haversineSql('locations.latitude', 'locations.longitude', $lat, $lng);

        // Inner query: join with locations, compute distance, bounding box filter
        $innerQuery = DB::table("{$table}")
            ->select("{$table}.*")
            ->selectRaw("{$distSql} AS distance_km", $distBindings)
            ->join('locations', "{$table}.location_id", '=', 'locations.id')
            ->whereNotNull('locations.latitude')
            ->whereNotNull('locations.longitude')
            ->whereBetween('locations.latitude', [$bounds['minLat'], $bounds['maxLat']])
            ->whereBetween('locations.longitude', [$bounds['minLng'], $bounds['maxLng']]);

        // Apply status scope if defined for this entity type
        if (isset($config['status_scope']) && ($options['status_filter'] ?? true)) {
            $innerQuery->where("{$table}.status", $config['status_scope']);
        }

        // Outer query: filter by exact radius using WHERE (not HAVING) for SQLite compat
        $results = DB::table(DB::raw("({$innerQuery->toSql()}) AS proxied"))
            ->mergeBindings($innerQuery)
            ->where('distance_km', '<=', $radiusKm)
            ->orderBy('distance_km')
            ->limit($limit)
            ->get();

        // Log slow proximity queries (>100ms threshold per slice verification)
        $durationMs = (microtime(true) - $startTime) * 1000;
        if ($durationMs > 100) {
            Log::info('Proximity query slow', [
                'entity_type' => $entityType,
                'radius_km' => $radiusKm,
                'result_count' => $results->count(),
                'duration_ms' => round($durationMs, 2),
                'lat' => $lat,
                'lng' => $lng,
            ]);
        }

        // Hydrate models and attach computed distance
        $modelInstances = $model::hydrate($results->toArray());
        $distanceMap = $results->pluck('distance_km', 'id');

        return $modelInstances->map(function ($entity) use ($distanceMap, $relationship) {
            return (object) [
                'entity' => $entity,
                'location' => $entity->$relationship,
                'distance_km' => round((float) ($distanceMap[$entity->id] ?? 0), 2),
            ];
        });
    }

    /**
     * Find hub locations with active session counts, cached by geohash tile.
     *
     * Returns locations within the radius along with the count of active
     * game sessions at each location. Results are cached per geohash tile
     * prefix for 15 minutes.
     *
     * @param  float  $lat  Center latitude
     * @param  float  $lng  Center longitude
     * @param  float  $radiusKm  Search radius in kilometers (default 10)
     * @return \Illuminate\Support\Collection Each item: location, active_sessions_count, distance_km
     */
    public function hubs(float $lat, float $lng, float $radiusKm = 10): \Illuminate\Support\Collection
    {
        $tilePrefix = Geohash::tilePrefix($lat, $lng, self::DEFAULT_GEOHASH_PRECISION);
        $cacheKey = "proximity:hubs:{$tilePrefix}:{$radiusKm}km";

        $startTime = microtime(true);

        $results = Cache::remember($cacheKey, self::HUB_CACHE_TTL, function () use ($lat, $lng, $radiusKm) {
            $bounds = $this->boundingBox($lat, $lng, $radiusKm);
            [$distSql, $distBindings] = $this->haversineSql('locations.latitude', 'locations.longitude', $lat, $lng);

            // Inner query: compute distance and active session count
            $innerQuery = DB::table('locations')
                ->select('locations.*')
                ->selectRaw("{$distSql} AS distance_km", $distBindings)
                ->selectRaw('(
                    SELECT COUNT(*) FROM games
                    WHERE games.location_id = locations.id
                    AND games.status = ?
                ) AS active_sessions_count', ['scheduled'])
                ->whereNotNull('locations.latitude')
                ->whereNotNull('locations.longitude')
                ->whereBetween('locations.latitude', [$bounds['minLat'], $bounds['maxLat']])
                ->whereBetween('locations.longitude', [$bounds['minLng'], $bounds['maxLng']]);

            // Outer query: filter by exact radius
            return DB::table(DB::raw("({$innerQuery->toSql()}) AS proxied"))
                ->mergeBindings($innerQuery)
                ->where('distance_km', '<=', $radiusKm)
                ->orderBy('distance_km')
                ->get();
        });

        // Log cache hit/miss and timing for observability
        $durationMs = (microtime(true) - $startTime) * 1000;
        $fromCache = Cache::has($cacheKey);
        Log::debug('Proximity hubs query', [
            'geohash_prefix' => $tilePrefix,
            'radius_km' => $radiusKm,
            'cache_hit' => $fromCache,
            'result_count' => $results->count(),
            'duration_ms' => round($durationMs, 2),
        ]);

        // Hydrate Location models
        $locations = Location::hydrate($results->map(fn ($r) => collect($r)->except('distance_km', 'active_sessions_count')->toArray())->toArray());
        $distanceMap = $results->pluck('distance_km', 'id');
        $sessionMap = $results->pluck('active_sessions_count', 'id');

        return $locations->map(fn ($location) => (object) [
            'location' => $location,
            'active_sessions_count' => (int) ($sessionMap[$location->id] ?? 0),
            'distance_km' => round((float) ($distanceMap[$location->id] ?? 0), 2),
        ]);
    }

    /**
     * Calculate a bounding box around a center point for fast pre-filtering.
     *
     * Uses a spherical approximation to convert radius to lat/lng offsets.
     * The bounding box is intentionally slightly larger than the exact circle
     * to ensure all candidates are captured (Haversine filters to exact radius).
     *
     * @param  float  $lat  Center latitude
     * @param  float  $lng  Center longitude
     * @param  float  $radiusKm  Radius in kilometers
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    public function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $angularRadius = $radiusKm / self::EARTH_RADIUS_KM;

        $minLat = $lat - rad2deg($angularRadius);
        $maxLat = $lat + rad2deg($angularRadius);

        // Longitude offset shrinks at higher latitudes
        $latRad = deg2rad($lat);
        $lngOffset = rad2deg($angularRadius / cos($latRad));

        $minLng = $lng - $lngOffset;
        $maxLng = $lng + $lngOffset;

        return [
            'minLat' => max(-90, $minLat),
            'maxLat' => min(90, $maxLat),
            'minLng' => $minLng,
            'maxLng' => $maxLng,
        ];
    }

    /**
     * Calculate the Haversine distance between two points in kilometers.
     *
     * Public static helper for use outside the query builder context.
     *
     * @param  float  $lat1  Point 1 latitude
     * @param  float  $lng1  Point 1 longitude
     * @param  float  $lat2  Point 2 latitude
     * @param  float  $lng2  Point 2 longitude
     * @return float Distance in kilometers, rounded to 2 decimal places
     */
    public static function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) ** 2;

        $c = 2 * asin(sqrt($a));

        return round(self::EARTH_RADIUS_KM * $c, 2);
    }
}
