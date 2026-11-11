<?php

use App\Models\City;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

//
// NOTE on DDL + --parallel: this test runs REAL two-table DDL (dropping the
// locations.city_id FK + cities.curation_state, then the guarded up()/down())
// inside DatabaseTransactions on the SHARED parallel database. DDL needs
// AccessExclusiveLock while sibling workers hold AccessShareLock on the same
// tables (cities/locations are the most-read tables in the suite, e.g.
// CityHubCacheObserver reads from unrelated tests) — a lock-order deadlock
// that can kill either side as victim (SQLSTATE 40P01). The in-test retry
// (commit 2eca9899) only rescued THIS test when it was the victim; sibling
// victims kept dying, so the test moved here (2026-11-11) into the
// 'ddl-serial' group (see tests/Pest.php): it runs in the serial DDL tail
// after the parallel phase, never alongside sibling workers.
//
// The migration 2026_10_09_160000 duplicates CityRegistryService's cluster
// logic self-contained; this parity test calls the migration directly (the
// D170 UuidPolicyTest pattern — also used by TrueUtcInstantsMigrationTest).
//

it('provisions the registry and links every geocoded location from pre-migration rows', function () {
    // Savepoint-retry (attempts: 2) keeps the outer test transaction intact
    // if the DDL still ever deadlocks; a genuine regression fails both
    // attempts. NOTE: this restores the retry commit 2eca9899 intended — in
    // CityRegistryServiceTest the `2` had landed on it()'s call (extra args
    // are silently ignored), leaving DB::transaction at attempts=1.
    DB::transaction(function (): void {
        // Pre-migration rows via raw inserts — factories would fire the
        // Location observer and provision the registry immediately.
        $berlinA = (string) Str::uuid7();
        $berlinB = (string) Str::uuid7();
        $neustadtPrimary = (string) Str::uuid7();
        $neustadtSecondary = (string) Str::uuid7();
        DB::table('locations')->insert([
            ['id' => $berlinA, 'name' => 'A', 'city' => 'Berlin', 'country' => 'DEU', 'latitude' => 52.52, 'longitude' => 13.405, 'geohash_4' => 'u33d'],
            ['id' => $berlinB, 'name' => 'B', 'city' => 'Berlin ', 'country' => 'DEU', 'latitude' => 52.53, 'longitude' => 13.41, 'geohash_4' => 'u33d'],
            ['id' => $neustadtPrimary, 'name' => 'C', 'city' => 'Neustadt', 'country' => 'DEU', 'latitude' => 52.5300, 'longitude' => 13.4100, 'geohash_4' => 'u33d'],
            ['id' => $neustadtSecondary, 'name' => 'D', 'city' => 'Neustadt', 'country' => 'DEU', 'latitude' => 53.5511, 'longitude' => 9.9937, 'geohash_4' => 'u1x0'],
        ]);

        // Rewind to the pre-migration schema state the backfill expects.
        Location::query()->whereIn('id', [$berlinA, $berlinB, $neustadtPrimary, $neustadtSecondary])->update(['city_id' => null]);
        Schema::table('locations', fn ($table) => $table->dropConstrainedForeignId('city_id'));
        Schema::table('cities', fn ($table) => $table->dropColumn('curation_state'));
        City::query()->delete();

        $migration = require database_path('migrations/2026_10_09_160000_city_registry_entity_links.php');
        $migration->up();

        $berlin = City::query()->where('slug', 'berlin')->firstOrFail();
        // Backfill order: larger cluster keeps the bare slug — the u33
        // Neustadt (2 locations incl. the primary+secondary... 1 each —
        // u33 has the Neustadt primary; both regions have 1 Neustadt
        // location, so the lexicographically smaller u1x wins the tie.
        $bare = City::query()->where('slug', 'neustadt')->firstOrFail();
        $suffixed = City::query()->where('slug', 'neustadt-u33')->firstOrFail();

        // The dirty variant was trimmed and linked into one cluster; the
        // second-region same-name cluster got the suffixed slug.
        expect(Location::where('city', 'Berlin')->count())->toBe(2)
            ->and($berlin->locations()->count())->toBe(2)
            ->and($berlin->region_prefix)->toBe('u33')
            ->and($bare->region_prefix)->toBe('u1x')
            ->and($suffixed->region_prefix)->toBe('u33')
            ->and($suffixed->locations()->count())->toBe(1)
            ->and(Location::whereNull('city_id')->count())->toBe(0);

        $migration->down();

        expect(Schema::hasColumn('locations', 'city_id'))->toBeFalse()
            ->and(Schema::hasColumn('cities', 'curation_state'))->toBeFalse();
    }, 2);
});
