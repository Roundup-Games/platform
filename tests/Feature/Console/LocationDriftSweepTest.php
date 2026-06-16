<?php

use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seed a known duplicate (by place_id) and a stale-geocode row.
 *
 * Kept inline (rather than reusing the shared seedLocationDriftFixtures()
 * helper from LocationDriftServiceTest) because that helper is a top-level
 * function; declaring it twice would fatal on "cannot redeclare function".
 *
 * @return array{target: Location, dupe: Location, stale: Location, clean: Location}
 */
function seedDriftSweepFixtures(): array
{
    // Duplicate by place_id — shared place_id, distinct names, ~500km apart.
    $target = Location::factory()->create([
        'name' => 'Sweep Target',
        'place_id' => 'ChIJ_sweep_place_001',
        'latitude' => '52.5200',
        'longitude' => '13.4050',
    ]);
    $dupe = Location::factory()->create([
        'name' => 'Sweep Clone',
        'place_id' => 'ChIJ_sweep_place_001',
        'latitude' => '48.1372',
        'longitude' => '11.5756',
    ]);

    // Stale geocode — sentinel (0.0, 0.0).
    $stale = Location::factory()->create([
        'name' => 'Sweep Sentinel',
        'place_id' => 'ChIJ_sweep_sentinel_002',
        'latitude' => '0',
        'longitude' => '0',
    ]);

    // Clean row — valid coords, unique name + place_id.
    $clean = Location::factory()->create([
        'name' => 'Sweep Pristine Venue',
        'place_id' => 'ChIJ_sweep_clean_003',
        'latitude' => '47.3769',
        'longitude' => '8.5417',
    ]);

    return compact('target', 'dupe', 'stale', 'clean');
}

describe('locations:drift-sweep command', function () {
    it('sets drift flags non-destructively without deleting source rows', function () {
        $f = seedDriftSweepFixtures();

        $this->artisan('locations:drift-sweep')
            ->assertSuccessful()
            ->expectsOutputToContain('Near-Duplicate Locations');

        // NON-DESTRUCTIVE: every seeded row is still present and queryable.
        expect(Location::whereKey($f['target']->id)->exists())->toBeTrue();
        expect(Location::whereKey($f['dupe']->id)->exists())->toBeTrue();
        expect(Location::whereKey($f['stale']->id)->exists())->toBeTrue();
        expect(Location::whereKey($f['clean']->id)->exists())->toBeTrue();

        // The duplicate row is flagged 'duplicate'; the stale row 'stale_geocode'.
        $dupeFresh = Location::find($f['dupe']->id);
        expect($dupeFresh->drift_status)->toBe('duplicate');
        expect($dupeFresh->drift_detected_at)->not->toBeNull();

        $staleFresh = Location::find($f['stale']->id);
        expect($staleFresh->drift_status)->toBe('stale_geocode');

        // The clean row is untouched; the lowest-id duplicate target stays clean.
        expect(Location::find($f['clean']->id)->drift_status)->toBe('clean');
        expect(Location::find($f['target']->id)->drift_status)->toBe('clean');

        // MEM717: drift_metadata never embeds an address or lat/lng.
        $dupeMeta = $dupeFresh->drift_metadata;
        expect($dupeMeta)->toBeArray();
        $serialized = json_encode($dupeMeta);
        expect($serialized)->not->toContain($f['dupe']->address);
    });

    it('leaves flags untouched under --dry-run', function () {
        $f = seedDriftSweepFixtures();

        // Pre-seed a stale flag on the clean row to prove dry-run writes nothing.
        DB::table('locations')->where('id', $f['clean']->id)->update([
            'drift_status' => 'duplicate',
            'drift_detected_at' => now(),
            'drift_metadata' => ['matched_on' => 'place_id'],
        ]);

        $this->artisan('locations:drift-sweep --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Dry-run complete');

        // The clean row's pre-existing flag is NOT reset under dry-run —
        // runChecks() skips both the reset AND the flag writes.
        $cleanFresh = Location::find($f['clean']->id);
        expect($cleanFresh->drift_status)->toBe('duplicate');

        // The duplicate + stale rows are NOT flagged under dry-run.
        expect(Location::find($f['dupe']->id)->drift_status)->toBe('clean');
        expect(Location::find($f['stale']->id)->drift_status)->toBe('clean');
    });

    it('resets previously-flagged rows back to clean when drift resolves', function () {
        $f = seedDriftSweepFixtures();

        // Pre-flag the clean row as stale; a real sweep should clear it.
        DB::table('locations')->where('id', $f['clean']->id)->update([
            'drift_status' => 'stale_geocode',
            'drift_detected_at' => now()->subHour(),
            'drift_metadata' => ['reason' => 'sentinel_zero_zero'],
        ]);

        $this->artisan('locations:drift-sweep')
            ->assertSuccessful();

        $cleanFresh = Location::find($f['clean']->id);
        expect($cleanFresh->drift_status)->toBe('clean');
        expect($cleanFresh->drift_detected_at)->toBeNull();
        expect($cleanFresh->drift_metadata)->toBeNull();
    });

    it('respects the --limit option and still completes successfully', function () {
        $f = seedDriftSweepFixtures();

        $this->artisan('locations:drift-sweep --limit=2')
            ->assertSuccessful();

        // Duplicate grouping queries always run complete (limit only bounds the
        // row-by-row stale scan), so the duplicate is still flagged.
        expect(Location::find($f['dupe']->id)->drift_status)->toBe('duplicate');
    });

    it('logs structured sweep started and completed fields', function () {
        $f = seedDriftSweepFixtures();

        $log = Log::spy();

        $this->artisan('locations:drift-sweep')
            ->assertSuccessful();

        // Verify started log with expected context keys.
        // array_key_exists for 'limit' because it is legitimately null when
        // no --limit is passed (isset() returns false for null values).
        $log->shouldHaveReceived('info', function ($message, $context) {
            return $message === 'locations_drift_sweep.started'
                && array_key_exists('dry_run', $context)
                && array_key_exists('limit', $context)
                && array_key_exists('refresh_geocode', $context);
        });

        // Verify completed log with structured fields.
        $log->shouldHaveReceived('info', function ($message, $context) {
            return $message === 'locations_drift_sweep.completed'
                && isset($context['counts'])
                && isset($context['duration_ms'])
                && isset($context['dry_run']);
        });
    });

    it('reports zero drift on an empty locations table', function () {
        $this->artisan('locations:drift-sweep')
            ->assertSuccessful()
            ->expectsOutputToContain('No location drift detected.');
    });
})->group('smoke');
