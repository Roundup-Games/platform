<?php

use App\Models\Location;
use App\Services\GeocodingService;
use App\Services\LocationDriftService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seed the four canonical drift fixtures used across tests.
 *
 * The fixtures are deliberately independent (distinct names/place_ids) so each
 * detection path flags exactly its intended row(s) and no cross-talk occurs.
 *
 * @return array<string, Location>
 */
function seedLocationDriftFixtures(): array
{
    // (1) Duplicate by place_id — shared place_id, distinct names, ~500km apart.
    //     The distance is irrelevant: place_id is the match signal.
    $placeTarget = Location::factory()->create([
        'name' => 'PlaceAlpha Target',
        'place_id' => 'ChIJ_drift_place_001',
        'latitude' => '52.5200',
        'longitude' => '13.4050',
    ]);
    $placeDupe = Location::factory()->create([
        'name' => 'PlaceAlpha Clone',
        'place_id' => 'ChIJ_drift_place_001',
        'latitude' => '48.1372',
        'longitude' => '11.5756',
    ]);

    // (2) Duplicate by normalized name + <200m — shared name, distinct place_ids.
    $nameTarget = Location::factory()->create([
        'name' => 'The Dragon Inn',
        'place_id' => 'ChIJ_drift_name_002',
        'latitude' => '52.5200',
        'longitude' => '13.4050',
    ]);
    $nameDupe = Location::factory()->create([
        'name' => 'The Dragon Inn',
        'place_id' => 'ChIJ_drift_name_003',
        'latitude' => '52.5200',
        'longitude' => '13.4064', // ~95m east of nameTarget → well under 200m
    ]);

    // (3) Stale geocode — sentinel (0.0, 0.0).
    $sentinel = Location::factory()->create([
        'name' => 'Sentinel Zero',
        'place_id' => 'ChIJ_drift_sentinel_004',
        'latitude' => '0',
        'longitude' => '0',
    ]);

    // (4) Clean row — valid coords, unique name + place_id.
    $clean = Location::factory()->create([
        'name' => 'Pristine Venue',
        'place_id' => 'ChIJ_drift_clean_005',
        'latitude' => '47.3769',
        'longitude' => '8.5417',
    ]);

    return compact('placeTarget', 'placeDupe', 'nameTarget', 'nameDupe', 'sentinel', 'clean');
}

// ── Core detection + non-destructive invariant ───────────────────────

it('flags the canonical drift fixtures non-destructively', function () {
    $f = seedLocationDriftFixtures();

    // Snapshot every seeded row's source data BEFORE the sweep.
    $before = Location::orderBy('id')->get()->keyBy('id')->map(fn (Location $l) => [
        'name' => $l->name,
        'place_id' => $l->place_id,
        'latitude' => $l->latitude,
        'longitude' => $l->longitude,
    ]);

    $reports = app(LocationDriftService::class)->runChecks();

    // ── Report shape (DataAudit convention) ──
    expect($reports)->toHaveCount(2);
    foreach ($reports as $report) {
        expect($report)->toHaveKeys(['check', 'label', 'count', 'severity', 'detail', 'sample_ids']);
        expect($report['sample_ids'])->toBeArray();
    }
    expect(collect($reports)->keyBy('check'))->toHaveKeys(['near_duplicates', 'stale_geocode']);

    // ── (1) place_id duplicate: the higher-id row is flagged, pointing at the lower ──
    $placeIds = DB::table('locations')
        ->where('place_id', 'ChIJ_drift_place_001')
        ->orderBy('id')
        ->pluck('id')
        ->values();
    [$placeLower, $placeHigher] = [$placeIds[0], $placeIds[1]];

    $flaggedPlace = Location::find($placeHigher);
    expect($flaggedPlace->drift_status)->toBe('duplicate')
        ->and($flaggedPlace->drift_metadata['matched_on'])->toBe('place_id')
        ->and($flaggedPlace->drift_metadata['candidate_target_id'])->toBe($placeLower)
        ->and($flaggedPlace->drift_detected_at)->not->toBeNull();
    expect(Location::find($placeLower)->drift_status)->toBe('clean');

    // ── (2) name+distance duplicate: higher-id row flagged, distance < 200m ──
    $nameIds = DB::table('locations')
        ->where('name', 'The Dragon Inn')
        ->orderBy('id')
        ->pluck('id')
        ->values();
    [$nameLower, $nameHigher] = [$nameIds[0], $nameIds[1]];

    $flaggedName = Location::find($nameHigher);
    expect($flaggedName->drift_status)->toBe('duplicate')
        ->and($flaggedName->drift_metadata['matched_on'])->toBe('name+distance')
        ->and($flaggedName->drift_metadata['candidate_target_id'])->toBe($nameLower)
        ->and($flaggedName->drift_metadata['distance_m'])->toBeLessThan(200)
        ->and($flaggedName->drift_metadata['distance_m'])->toBeGreaterThan(0);
    expect(Location::find($nameLower)->drift_status)->toBe('clean');

    // ── (3) stale geocode sentinel ──
    $flaggedSentinel = $f['sentinel']->fresh();
    expect($flaggedSentinel->drift_status)->toBe('stale_geocode')
        ->and($flaggedSentinel->drift_metadata['reason'])->toBe('sentinel_zero_zero');

    // ── (4) clean row stays clean ──
    expect($f['clean']->fresh()->drift_status)->toBe('clean');

    // ── NON-DESTRUCTIVE: every source row intact, none removed ──
    expect(Location::count())->toBe(6); // all six fixtures still queryable
    Location::orderBy('id')->get()->each(function (Location $l) use ($before) {
        $orig = $before[$l->id];
        expect($l->name)->toBe($orig['name'])
            ->and($l->place_id)->toBe($orig['place_id'])
            ->and($l->latitude)->toBe($orig['latitude'])
            ->and($l->longitude)->toBe($orig['longitude']);
    });
});

// ── Dry-run writes nothing ───────────────────────────────────────────

it('writes no flags in dry-run mode', function () {
    seedLocationDriftFixtures();

    app(LocationDriftService::class)->runChecks(dryRun: true);

    // Nothing was persisted: every row still at the migration default.
    expect(Location::where('drift_status', '!=', 'clean')->exists())->toBeFalse();
    expect(Location::whereNotNull('drift_detected_at')->exists())->toBeFalse();
    expect(Location::whereNotNull('drift_metadata')->exists())->toBeFalse();

    // …but the reports still describe what WOULD be flagged.
    $reports = collect(app(LocationDriftService::class)->runChecks(dryRun: true))->keyBy('check');
    expect($reports['near_duplicates']['count'])->toBe(2)
        ->and($reports['stale_geocode']['count'])->toBe(1);
});

// ── Negative / boundary detections ──────────────────────────────────

it('flags rows with missing coordinates as stale geocode', function () {
    $loc = Location::factory()->create([
        'name' => 'No Coords',
        'place_id' => 'ChIJ_neg_missing_010',
        'latitude' => null,
        'longitude' => null,
    ]);

    app(LocationDriftService::class)->runChecks();

    expect($loc->fresh()->drift_status)->toBe('stale_geocode')
        ->and($loc->fresh()->drift_metadata['reason'])->toBe('missing_coordinates');
});

it('detects a geohash mismatch (trigger-bypass row)', function () {
    $loc = Location::factory()->create([
        'name' => 'Mismatched Geohash',
        'place_id' => 'ChIJ_neg_mismatch_011',
        'latitude' => '50.9352',
        'longitude' => '6.9530',
    ]);

    // Corrupt geohash_4 via a raw write that bypasses both the Eloquent saving
    // hook and the BEFORE UPDATE OF latitude,longitude trigger.
    DB::table('locations')->where('id', $loc->id)->update(['geohash_4' => 'zzzz']);

    app(LocationDriftService::class)->runChecks();

    expect($loc->fresh()->drift_status)->toBe('stale_geocode')
        ->and($loc->fresh()->drift_metadata['reason'])->toBe('geohash_mismatch');
});

it('does not flag same-named rows farther than 200m apart', function () {
    Location::factory()->create([
        'name' => 'Far Pair',
        'place_id' => 'ChIJ_neg_far_012',
        'latitude' => '52.5200',
        'longitude' => '13.4050',
    ]);
    Location::factory()->create([
        'name' => 'Far Pair',
        'place_id' => 'ChIJ_neg_far_013',
        'latitude' => '52.5300', // ~1.1km north → beyond the 200m threshold
        'longitude' => '13.4050',
    ]);

    app(LocationDriftService::class)->runChecks();

    expect(Location::where('name', 'Far Pair')->where('drift_status', '!=', 'clean')->exists())->toBeFalse();
});

it('skips normalized-name groups larger than 50 rows to bound cost', function () {
    // 51 same-named rows at the identical point — would all be duplicates if the
    // pairwise check ran. The >50 cap skips the group entirely (load protection).
    Location::factory()->count(51)->create([
        'name' => 'Mega Group Venue',
        'latitude' => '50.0000',
        'longitude' => '10.0000',
        // place_id left to the factory default → unique per row, so place_id check won't group them
    ]);

    app(LocationDriftService::class)->runChecks();

    expect(Location::where('name', 'Mega Group Venue')->where('drift_status', 'duplicate')->count())->toBe(0);
});

// ── Opt-in geocode refresh (mocked — no live API) ───────────────────

it('flags geocode drift over 500m when refresh is enabled', function () {
    $loc = Location::factory()->create([
        'name' => 'Drifty Point',
        'place_id' => 'ChIJ_refresh_drift_020',
        'address' => 'Test Street 1',
        'city' => 'Berlin',
        'postal_code' => '10115',
        'latitude' => '52.5200',
        'longitude' => '13.4050',
    ]);

    // Geocoder returns a point ~556m north (>500m threshold).
    $geocoder = Mockery::mock(GeocodingService::class);
    $geocoder->shouldReceive('geocode')->andReturn([
        'lat' => 52.5250,
        'lng' => 13.4050,
        'display_name' => 'Somewhere Else',
        'place_id' => 'refresh_020',
        'raw' => [],
    ]);

    $service = new LocationDriftService($geocoder, geocodeThrottleMicroseconds: 0);
    $service->runChecks(dryRun: false, limit: null, refreshGeocode: true);

    $fresh = $loc->fresh();
    expect($fresh->drift_status)->toBe('stale_geocode')
        ->and($fresh->drift_metadata['reason'])->toBe('geocode_drift')
        ->and($fresh->drift_metadata['distance_m'])->toBeGreaterThan(500);
});

it('does not flag when the geocoder returns no result', function () {
    $loc = Location::factory()->create([
        'name' => 'Unresolvable',
        'place_id' => 'ChIJ_refresh_null_021',
        'address' => 'Nowhere',
        'city' => 'Void',
        'postal_code' => '00000',
        'latitude' => '52.5200',
        'longitude' => '13.4050',
    ]);

    $geocoder = Mockery::mock(GeocodingService::class);
    $geocoder->shouldReceive('geocode')->andReturn(null);

    $service = new LocationDriftService($geocoder, geocodeThrottleMicroseconds: 0);
    $service->runChecks(dryRun: false, limit: null, refreshGeocode: true);

    expect($loc->fresh()->drift_status)->toBe('clean');
});

// ── Observability: per-row flag log ─────────────────────────────────

it('logs each drift flag write at info with location_id and reason', function () {
    seedLocationDriftFixtures();

    $flagged = [];
    Log::shouldReceive('info')->andReturnUsing(function (string $message, array $context) use (&$flagged) {
        if ($message === 'location_drift.flagged') {
            $flagged[] = $context;
        }
    })->atLeast()->once();

    app(LocationDriftService::class)->runChecks();

    // Three rows flagged across the fixtures → three audit log entries.
    expect($flagged)->toHaveCount(3);
    foreach ($flagged as $entry) {
        expect($entry)->toHaveKeys(['location_id', 'drift_status', 'reason']);
    }
});
