<?php

namespace App\Services;

use App\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Non-destructive location-drift detector (D087).
 *
 * Flags two classes of data-quality drift by writing ONLY the dedicated
 * drift_* flag columns on the locations table:
 *
 *   - NEAR_DUPLICATES  → drift_status = 'duplicate'
 *       • same non-null place_id (every row except the lowest-id in the group)
 *       • same normalized name AND haversine distance < 200m
 *   - STALE_GEOCODE    → drift_status = 'stale_geocode'
 *       • null latitude/longitude
 *       • sentinel (0.0, 0.0) coordinates
 *       • geohash_4 that mismatches a fresh Geohash::tilePrefix() (trigger-bypass)
 *       • (opt-in --refresh-geocode) re-geocoded point moved > 500m
 *
 * ⚠️  NON-DESTRUCTIVE INVARIANT (D087): this class NEVER merges, deletes, or
 * mutates any column other than drift_status / drift_detected_at / drift_metadata.
 * It imports no merge service and no delete call (verifiable via grep). An
 * admin acts on flagged rows via the EXISTING manual merge action in
 * LocationResource.
 *
 * MEM717: drift_metadata carries only ids, match signals, distances, and
 * machine reasons — NEVER an address, lat/lng, postal code, or geohash.
 *
 * Flag writes use DB::table() (not Eloquent save) so the PostgreSQL
 * `locations_geohash_4_trigger` (BEFORE UPDATE OF latitude, longitude) never
 * fires and no model events run — the write is surgical and side-effect-free.
 */
class LocationDriftService
{
    /** Haversine threshold (km) below which same-named rows are near-duplicates. */
    private const DUPLICATE_DISTANCE_KM = 0.2;

    /** Skip normalized-name groups larger than this to bound pairwise cost. */
    private const MAX_NAME_GROUP_SIZE = 50;

    /** Haversine threshold (km) above which a re-geocoded point has "drifted". */
    private const GEOCODE_DRIFT_KM = 0.5;

    /** Nominatim usage policy: max 1 req/sec. Throttle between geocode calls. */
    private const GEOCODE_THROTTLE_MICROSECONDS = 1_000_000;

    public function __construct(
        private readonly GeocodingService $geocoding,
        private readonly int $geocodeThrottleMicroseconds = self::GEOCODE_THROTTLE_MICROSECONDS,
    ) {}

    /**
     * Run all drift checks and return a per-check report (DataAudit shape).
     *
     * @param  bool  $dryRun  When true, detect and report but write nothing.
     * @param  int|null  $limit  Bounds the row-by-row scans (stale-geocode
     *                           candidates + geocode refresh). Grouping queries (place_id / name)
     *                           always run complete because partial grouping would miss duplicates.
     *                           Cost-basis-aware by design: it caps rows *inspected* in default
     *                           mode and expensive *geocode API calls* under $refreshGeocode — the
     *                           two counters are intentionally not aligned.
     * @param  bool  $refreshGeocode  Opt-in expensive mode: re-geocode rows via
     *                                Nominatim (1 req/sec) and flag moves > 500m.
     * @return array<int, array{check: string, label: string, count: int, severity: string, detail: string, sample_ids: array<int, string>}>
     */
    public function runChecks(bool $dryRun = false, ?int $limit = null, bool $refreshGeocode = false): array
    {
        // Track detections across checks so duplicate (the stronger signal)
        // takes precedence over stale_geocode for a row flagged by both.
        /** @var array<string, bool> $flagged */
        $flagged = [];

        if (! $dryRun) {
            $this->resetFlags();
        }

        return [
            $this->checkNearDuplicates($dryRun, $flagged),
            $this->checkStaleGeocode($dryRun, $flagged, $limit, $refreshGeocode),
        ];
    }

    // ── Near duplicates ───────────────────────────────────────────────

    /**
     * Detect near-duplicates by place_id, then by normalized-name + <200m.
     *
     * @param  array<string, bool>  $flagged  Populated with flagged location ids.
     * @return array{check: string, label: string, count: int, severity: string, detail: string, sample_ids: array<int, string>}
     */
    private function checkNearDuplicates(bool $dryRun, array &$flagged): array
    {
        /** @var array<string, bool> $flagged */
        $byPlaceId = $this->flagDuplicatesByPlaceId($dryRun, $flagged);
        $byName = $this->flagDuplicatesByNameAndDistance($dryRun, $flagged);

        $total = count($byPlaceId) + count($byName);
        $sampleIds = array_slice(array_merge($byPlaceId, $byName), 0, 5);

        return $this->report(
            'near_duplicates',
            'Near-Duplicate Locations',
            $total,
            'by_place_id: '.count($byPlaceId).', by_name+distance: '.count($byName),
            $sampleIds,
        );
    }

    /**
     * Group by non-null place_id; flag every row except the lowest-id.
     *
     * @param  array<string, bool>  $flagged
     * @return array<int, string> Flagged location ids.
     */
    private function flagDuplicatesByPlaceId(bool $dryRun, array &$flagged): array
    {
        $flaggedIds = [];

        $groups = DB::table('locations')
            ->whereNotNull('place_id')
            ->select('place_id', DB::raw('count(*) as cnt'))
            ->groupBy('place_id')
            ->havingRaw('count(*) > 1')
            ->pluck('place_id');

        foreach ($groups as $placeId) {
            $rows = DB::table('locations')
                ->where('place_id', $placeId)
                ->orderBy('id')
                ->pluck('id');

            if ($rows->count() < 2) {
                continue;
            }

            $first = $rows->first();
            // pluck('id') is typed mixed, so narrow explicitly. The count() >= 2
            // guard above makes a non-scalar unreachable — fail loud rather than
            // fabricate an empty id, which would log phantom drift rows (a
            // data-quality detector must never emit made-up location ids).
            if (! is_scalar($first)) {
                throw new \LogicException('Location id missing from pluck("id") result.');
            }
            $targetId = (string) $first;

            foreach ($rows->skip(1) as $id) {
                if (! is_scalar($id)) {
                    throw new \LogicException('Location id missing from pluck("id") result.');
                }
                $id = (string) $id;
                if (isset($flagged[$id])) {
                    continue;
                }
                $this->flag($id, 'duplicate', [
                    'candidate_target_id' => $targetId,
                    'matched_on' => 'place_id',
                ], $dryRun, 'place_id');
                $flagged[$id] = true;
                $flaggedIds[] = $id;
            }
        }

        return $flaggedIds;
    }

    /**
     * Group by lower(trim(name)); within each group flag higher-id rows whose
     * haversine distance to a lower-id row is < 200m. Groups > 50 rows skipped.
     *
     * @param  array<string, bool>  $flagged
     * @return array<int, string> Flagged location ids.
     */
    private function flagDuplicatesByNameAndDistance(bool $dryRun, array &$flagged): array
    {
        $flaggedIds = [];

        $groups = DB::table('locations')
            ->whereNotNull('name')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw('lower(trim(name)) as norm_name, count(*) as cnt')
            ->groupBy('norm_name')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($groups as $group) {
            if ($group->cnt > self::MAX_NAME_GROUP_SIZE) {
                continue; // bound pairwise cost
            }

            /** @var Collection<int, Location> $rows ordered lowest-id first */
            $rows = Location::whereRaw('lower(trim(name)) = ?', [$group->norm_name])
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->orderBy('id')
                ->get();

            foreach ($rows as $k => $candidate) {
                if ($k === 0) {
                    continue; // lowest-id in the group is never a duplicate target-source
                }
                if (isset($flagged[$candidate->id])) {
                    continue;
                }

                // Scan lower-id rows ascending; first within-threshold match is
                // the lowest-id member of this candidate's matched set.
                for ($m = 0; $m < $k; $m++) {
                    $other = $rows[$m];
                    if (! $other instanceof Location) {
                        continue;
                    }
                    $distKm = $candidate->distanceTo((float) $other->latitude, (float) $other->longitude);
                    if ($distKm < self::DUPLICATE_DISTANCE_KM) {
                        $this->flag((string) $candidate->id, 'duplicate', [
                            'candidate_target_id' => (string) $other->id,
                            'matched_on' => 'name+distance',
                            'distance_m' => (int) round($distKm * 1000),
                        ], $dryRun, 'name+distance');
                        $flagged[(string) $candidate->id] = true;
                        $flaggedIds[] = (string) $candidate->id;
                        break;
                    }
                }
            }
        }

        return $flaggedIds;
    }

    // ── Stale geocode ─────────────────────────────────────────────────

    /**
     * Detect stale geocoding. Cheap mode (default) inspects stored columns;
     * expensive mode (refreshGeocode) re-geocodes limit-bounded rows.
     *
     * @param  array<string, bool>  $flagged  Rows already flagged take precedence.
     * @return array{check: string, label: string, count: int, severity: string, detail: string, sample_ids: array<int, string>}
     */
    private function checkStaleGeocode(bool $dryRun, array &$flagged, ?int $limit, bool $refreshGeocode): array
    {
        $flaggedIds = [];
        $byReason = [];

        // chunkById keeps this memory-bounded regardless of table size. The
        // scheduled sweep (routes/console.php) passes no --limit, so without
        // chunking the whole locations table would hydrate into one collection
        // on the daily cron — a latent OOM as the table grows. An optional
        // $limit caps how many rows are inspected (manual/operator use); null
        // means scan every row, still one chunk at a time. Returning false from
        // the callback halts chunkById once the limit is reached.
        $processed = 0;
        DB::table('locations')
            ->select(['id', 'latitude', 'longitude', 'geohash_4'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($dryRun, &$flagged, &$flaggedIds, &$byReason, $limit, &$processed) {
                foreach ($rows as $row) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }
                    $processed++;

                    $id = (string) $row->id;
                    if (isset($flagged[$id])) {
                        continue; // duplicate takes precedence
                    }

                    $reason = $this->staleGeocodeReason($row);
                    if ($reason !== null) {
                        $this->flag($id, 'stale_geocode', ['reason' => $reason], $dryRun, $reason);
                        $flagged[$id] = true;
                        $flaggedIds[] = $id;
                        $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
                    }
                }
            });

        $refreshed = 0;
        if ($refreshGeocode) {
            $refreshed = $this->flagGeocodeDrift($dryRun, $flagged, $limit, $flaggedIds, $byReason);
        }

        $total = count($flaggedIds);
        $detail = collect($byReason)->map(fn (int $c, string $r) => "{$r}: {$c}")->implode(', ');
        if ($refreshGeocode) {
            $detail .= ($detail ? '; ' : '')."geocode_refresh_run: {$refreshed}";
        }
        $detail = $detail ?: 'none';

        return $this->report(
            'stale_geocode',
            'Stale / Missing Geocoding',
            $total,
            $detail,
            array_slice($flaggedIds, 0, 5),
        );
    }

    /**
     * Classify a row's stale-geocode reason from stored columns (no API call).
     *
     * @param  \stdClass  $row  a row from DB::table('locations')->get()
     */
    private function staleGeocodeReason(\stdClass $row): ?string
    {
        $latRaw = $row->latitude ?? null;
        $lngRaw = $row->longitude ?? null;
        $lat = ($latRaw === null || ! is_numeric($latRaw)) ? null : (float) $latRaw;
        $lng = ($lngRaw === null || ! is_numeric($lngRaw)) ? null : (float) $lngRaw;

        if ($lat === null || $lng === null) {
            return 'missing_coordinates';
        }

        if ($lat === 0.0 && $lng === 0.0) {
            return 'sentinel_zero_zero';
        }

        // Trigger-bypass detection: a row whose stored geohash_4 disagrees
        // with a fresh computation was written outside Eloquent/trigger.
        $expected = Geohash::tilePrefix($lat, $lng, 4);
        if ($row->geohash_4 !== null && $row->geohash_4 !== $expected) {
            return 'geohash_mismatch';
        }

        return null;
    }

    /**
     * Expensive opt-in: re-geocode rows via Nominatim (1 req/sec) and flag
     * points that moved more than 500m. Bounded by $limit.
     *
     * @param  array<string, bool>  $flagged
     * @param  array<int, string>  $flaggedIds
     * @param  array<string, int>  $byReason
     * @return int Number of rows re-geocoded.
     */
    private function flagGeocodeDrift(bool $dryRun, array &$flagged, ?int $limit, array &$flaggedIds, array &$byReason): int
    {
        $processed = 0;

        // chunkById bounds memory on the opt-in refresh path (an operator can
        // run --refresh-geocode without --limit). The address columns are
        // selected so fullAddress() resolves a real query string — previously
        // only id/lat/lng were loaded and fullAddress() returned an empty
        // string, making the refresh path silently no-op on every row.
        Location::whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select(['id', 'latitude', 'longitude', 'address', 'postal_code', 'city'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($dryRun, &$flagged, &$flaggedIds, &$byReason, $limit, &$processed) {
                foreach ($rows as $location) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }

                    $id = (string) $location->id;
                    if (isset($flagged[$id])) {
                        continue;
                    }

                    $processed++;
                    $result = $this->geocoding->geocode($location->fullAddress());

                    // Respect Nominatim usage policy (max 1 req/sec).
                    if ($this->geocodeThrottleMicroseconds > 0) {
                        usleep($this->geocodeThrottleMicroseconds);
                    }

                    if ($result === null) {
                        continue; // geocoder returned nothing — not actionable as drift
                    }

                    $distKm = $location->distanceTo($result['lat'], $result['lng']);
                    if ($distKm > self::GEOCODE_DRIFT_KM) {
                        $reason = 'geocode_drift';
                        $this->flag($id, 'stale_geocode', [
                            'reason' => $reason,
                            'distance_m' => (int) round($distKm * 1000),
                        ], $dryRun, $reason);
                        $flagged[$id] = true;
                        $flaggedIds[] = $id;
                        $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
                    }
                }
            });

        return $processed;
    }

    // ── Flag write + reset ────────────────────────────────────────────

    /**
     * Clear all drift flags so the queue reflects the current sweep only.
     *
     * Writes only the three drift columns; touches no other column, so the
     * geohash trigger and model events do not fire.
     */
    private function resetFlags(): void
    {
        DB::table('locations')
            ->where('drift_status', '!=', 'clean')
            ->orWhereNull('drift_status')
            ->update([
                'drift_status' => 'clean',
                'drift_detected_at' => null,
                'drift_metadata' => null,
            ]);
    }

    /**
     * Write a non-destructive drift flag for one row (unless dry-run) and log it.
     *
     * @param  array<string, mixed>  $metadata  ids/signals only — never an address (MEM717).
     */
    private function flag(string $id, string $status, array $metadata, bool $dryRun, string $reason): void
    {
        if ($dryRun) {
            return;
        }

        DB::table('locations')->where('id', $id)->update([
            'drift_status' => $status,
            'drift_detected_at' => now(),
            'drift_metadata' => $metadata,
        ]);

        Log::info('location_drift.flagged', [
            'location_id' => $id,
            'drift_status' => $status,
            'reason' => $reason,
        ]);
    }

    // ── Reporting ─────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $sampleIds
     * @return array{check: string, label: string, count: int, severity: string, detail: string, sample_ids: array<int, string>}
     */
    private function report(string $check, string $label, int $count, string $detail, array $sampleIds): array
    {
        return [
            'check' => $check,
            'label' => $label,
            'count' => $count,
            'severity' => $count > 0 ? 'warning' : 'ok',
            'detail' => $detail,
            'sample_ids' => array_slice($sampleIds, 0, 5),
        ];
    }
}
