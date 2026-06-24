<?php

namespace App\Services;

use App\Dto\BBox;
use App\Dto\ProximityResult;
use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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
 *   - Haversine formula applied via subquery for precise distance computation.
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
     * Thin delegator over {@see haversineSelectExpression()} so the Haversine
     * formula lives in exactly one place in this class.
     *
     * @return list{string, list<int|float>}
     */
    private function haversineSql(string $latCol, string $lngCol, float $centerLat, float $centerLng): array
    {
        return self::haversineSelectExpression($latCol, $lngCol, $centerLat, $centerLng);
    }

    /**
     * The canonical Haversine distance SQL fragment for select-side distance.
     *
     * Returns [sql, bindings] where sql contains four parameter placeholders,
     * suitable for `selectRaw("locations.*, {$sql} AS distance_km", $bindings)`.
     *
     * This is the single source for the Haversine expression across the
     * codebase. Proximity queries (this class) and proximity-ordered public
     * listings (e.g. the venue directory) resolve distance through here rather
     * than each re-declaring the formula. VenueSearchService delegates here too.
     *
     * @param  string  $latCol  Qualified latitude column, e.g. 'locations.latitude'.
     * @param  string  $lngCol  Qualified longitude column, e.g. 'locations.longitude'.
     * @return list{string, list<int|float>}
     */
    public static function haversineSelectExpression(string $latCol, string $lngCol, float $centerLat, float $centerLng): array
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
     * Uses a subquery wrapper (not HAVING) for clean distance filtering.
     *
     * @param  float  $lat  Center latitude
     * @param  float  $lng  Center longitude
     * @param  float  $radiusKm  Search radius in kilometers (default 50)
     * @param  string  $entityType  'game' or 'event'
     * @param  array{limit?: int, status_filter?: bool, visibility?: string|list<string>, with?: list<string>}  $options  Additional options: limit, status_filter, with
     * @param  array<string, mixed>  $options
     */
    public function nearby(float $lat, float $lng, float $radiusKm = 50, string $entityType = 'game', array $options = []): Collection // @phpstan-ignore missingType.generics
    {
        $startTime = microtime(true);

        $config = self::ENTITY_MAP[$entityType] ?? null;
        if (! $config) {
            return collect();
        }

        $model = $config['model'];
        $relationship = $config['relationship'];
        $limit = is_int($options['limit'] ?? null) ? $options['limit'] : 50;

        // Calculate bounding box from center point and radius
        $bounds = $this->boundingBox($lat, $lng, $radiusKm);

        $table = (new $model)->getTable();
        /** @var literal-string $distSql */
        [$distSql, $distBindings] = $this->haversineSql('locations.latitude', 'locations.longitude', $lat, $lng);

        // Inner query: join with locations, compute distance, bounding box filter
        $innerQuery = DB::table("{$table}")
            ->select("{$table}.*")
            ->selectRaw("{$distSql} AS distance_km", $distBindings)
            ->join('locations', "{$table}.location_id", '=', 'locations.id')
            ->whereNotNull('locations.latitude')
            ->whereNotNull('locations.longitude')
            ->whereBetween('locations.latitude', [$bounds->minLat, $bounds->maxLat])
            ->whereBetween('locations.longitude', [$bounds->minLng, $bounds->maxLng]);

        // Apply status scope if defined for this entity type
        if (isset($config['status_scope']) && ($options['status_filter'] ?? true)) {
            $innerQuery->where("{$table}.status", $config['status_scope']);
        }

        // Apply visibility filter at SQL level when specified
        if (isset($options['visibility'])) {
            $visibilities = is_array($options['visibility']) ? $options['visibility'] : [$options['visibility']];
            $innerQuery->whereIn("{$table}.visibility", $visibilities);
        }

        // Outer query: filter by exact radius using WHERE (not HAVING)
        /** @var literal-string $subSql */
        $subSql = "({$innerQuery->toSql()}) AS proxied";
        $results = DB::table(DB::raw($subSql))
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
            $distanceRaw = $distanceMap[$entity->getKey()] ?? 0;
            $distance = is_numeric($distanceRaw) ? (float) $distanceRaw : 0;
            $location = $entity->$relationship;

            return new ProximityResult(
                entity: $entity,
                location: $location instanceof Location ? $location : new Location,
                distanceKm: round($distance, 2),
            );
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
     */
    public function hubs(float $lat, float $lng, float $radiusKm = 10): Collection // @phpstan-ignore missingType.generics
    {
        $tilePrefix = Geohash::tilePrefix($lat, $lng, self::DEFAULT_GEOHASH_PRECISION);
        $cacheKey = "proximity:hubs:{$tilePrefix}:{$radiusKm}km";

        $startTime = microtime(true);

        $results = Cache::remember($cacheKey, self::HUB_CACHE_TTL, function () use ($lat, $lng, $radiusKm) {
            $bounds = $this->boundingBox($lat, $lng, $radiusKm);
            /** @var literal-string $distSql */
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
                ->whereBetween('locations.latitude', [$bounds->minLat, $bounds->maxLat])
                ->whereBetween('locations.longitude', [$bounds->minLng, $bounds->maxLng]);

            // Outer query: filter by exact radius
            /** @var literal-string $subSql */
            $subSql = "({$innerQuery->toSql()}) AS proxied";

            return DB::table(DB::raw($subSql))
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
        /** @var list<array<string, mixed>> $hydratable */
        $hydratable = $results->map(fn (mixed $r) => (array) $r)->map(fn (array $r) => Arr::except($r, ['distance_km', 'active_sessions_count']))->toArray();
        $locations = Location::hydrate($hydratable);
        $distanceMap = $results->pluck('distance_km', 'id');
        $sessionMap = $results->pluck('active_sessions_count', 'id');

        return $locations->map(function (Location $location) use ($distanceMap, $sessionMap) {
            $sessionsRaw = $sessionMap[$location->id] ?? 0;
            $distanceRaw = $distanceMap[$location->id] ?? 0;
            $sessions = is_numeric($sessionsRaw) ? (int) $sessionsRaw : 0;
            $distance = is_numeric($distanceRaw) ? (float) $distanceRaw : 0;

            return (object) [
                'location' => $location,
                'active_sessions_count' => $sessions,
                'distance_km' => round($distance, 2),
            ];
        });
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
     */
    /**
     * Group nearby game results by campaign, keeping each campaign's NEAREST session.
     *
     * A campaign spreads its sessions across Game rows that may sit at several
     * locations; discovery and the nearby-sessions widget both want the closest
     * session per campaign. Centralised here — the owner of {@see ProximityResult}
     * — so the two former inline copies (DiscoveryQueryService and NearbySessions)
     * cannot drift apart again. Both copies misused sortBy() with a two-argument
     * comparator (Laravel's sortBy takes a single-argument key extractor): the
     * second arg was unbound, the comparator evaluated to 0 for every item, and a
     * stable sort preserved insertion order — so each campaign resolved to an
     * ARBITRARY session instead of the nearest, surfacing a wrong distance to
     * users (and, in DiscoveryQueryService, the property_exists() sibling silently
     * dropped every campaign).
     *
     * Campaign-session detection uses getAttribute() (Eloquent columns live in
     * $attributes, not as real properties); standalone games (null campaign_id)
     * are excluded.
     *
     * @param  Collection<int, mixed>  $gameResults  ProximityResult<Game> items from {@see nearby()}
     * @return Collection<string, ProximityResult<Game>> Each campaign_id mapped to its nearest session
     */
    public function nearestSessionByCampaign(Collection $gameResults): Collection
    {
        // Collection::filter() cannot narrow TValue through the predicate, so the
        // narrowed type is asserted once for the loop below.
        /** @var Collection<int, ProximityResult<Game>> $campaignSessions */
        $campaignSessions = $gameResults->filter(fn (mixed $r): bool => $r instanceof ProximityResult
            && $r->entity instanceof Game
            && $r->entity->getAttribute('campaign_id') !== null);

        // Keep each campaign's nearest (lowest-distance) session. A plain loop is
        // both PHPStan-clean (avoids the mixed-narrowing friction of the
        // groupBy/sortBy chain) and O(n) — no per-group sort — for what is a
        // minimum-distance reduction.
        /** @var array<string, ProximityResult<Game>> $nearest */
        $nearest = [];
        foreach ($campaignSessions as $result) {
            $campaignId = $result->entity->getAttribute('campaign_id');
            if (! is_string($campaignId)) {
                continue;
            }
            $current = $nearest[$campaignId] ?? null;
            if ($current === null || $result->distanceKm < $current->distanceKm) {
                $nearest[$campaignId] = $result;
            }
        }

        return new Collection($nearest);
    }

    public function boundingBox(float $lat, float $lng, float $radiusKm): BBox
    {
        $angularRadius = $radiusKm / self::EARTH_RADIUS_KM;

        $minLat = $lat - rad2deg($angularRadius);
        $maxLat = $lat + rad2deg($angularRadius);

        // Longitude offset shrinks at higher latitudes
        $latRad = deg2rad($lat);
        $lngOffset = rad2deg($angularRadius / cos($latRad));

        $minLng = $lng - $lngOffset;
        $maxLng = $lng + $lngOffset;

        return new BBox(
            minLat: max(-90, $minLat),
            maxLat: min(90, $maxLat),
            minLng: $minLng,
            maxLng: $maxLng,
        );
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
