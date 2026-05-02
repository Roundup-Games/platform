<?php

namespace Tests\Feature\Discovery;

use App\Models\Location;
use App\Services\Geohash;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocationGeohashTest extends TestCase
{
    use DatabaseTransactions;

    // ── Auto-compute on save ───────────────────────────

    #[Test]
    public function geohash_4_is_auto_computed_when_location_is_created()
    {
        $location = Location::create([
            'name' => 'Berlin Test',
            'city' => 'Berlin',
            'latitude' => 52.5163,
            'longitude' => 13.3777,
        ]);

        $expected = Geohash::tilePrefix(52.5163, 13.3777, 4);
        $this->assertEquals($expected, $location->geohash_4);
        $this->assertEquals(4, strlen($location->geohash_4));
    }

    #[Test]
    public function geohash_4_is_updated_when_coordinates_change()
    {
        $location = Location::create([
            'name' => 'Berlin',
            'city' => 'Berlin',
            'latitude' => 52.5163,
            'longitude' => 13.3777,
        ]);

        $originalHash = $location->geohash_4;

        // Move to Munich
        $location->update([
            'latitude' => 48.1351,
            'longitude' => 11.5820,
        ]);

        $location->refresh();
        $munichHash = Geohash::tilePrefix(48.1351, 11.5820, 4);

        $this->assertNotEquals($originalHash, $location->geohash_4);
        $this->assertEquals($munichHash, $location->geohash_4);
    }

    #[Test]
    public function geohash_4_is_null_when_coordinates_are_missing()
    {
        // Create without lat/lng — the migration makes the column nullable
        // but the model's fillable includes it, so we can test the guard
        $location = new Location([
            'name' => 'No Coords',
            'city' => 'Nowhere',
        ]);
        $location->latitude = null;
        $location->longitude = null;
        $location->save();

        $this->assertNull($location->geohash_4);
    }

    #[Test]
    public function nearby_locations_share_geohash_4()
    {
        // Two points within 500m in Berlin
        $loc1 = Location::create([
            'name' => 'Point A',
            'city' => 'Berlin',
            'latitude' => 52.5163,
            'longitude' => 13.3777,
        ]);

        $loc2 = Location::create([
            'name' => 'Point B',
            'city' => 'Berlin',
            'latitude' => 52.5170,
            'longitude' => 13.3780,
        ]);

        // Nearby points should share the same 4-char geohash tile
        $this->assertEquals($loc1->geohash_4, $loc2->geohash_4);
    }

    #[Test]
    public function distant_locations_have_different_geohash_4()
    {
        $berlin = Location::create([
            'name' => 'Berlin',
            'city' => 'Berlin',
            'latitude' => 52.5163,
            'longitude' => 13.3777,
        ]);

        $munich = Location::create([
            'name' => 'Munich',
            'city' => 'Munich',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
        ]);

        $this->assertNotEquals($berlin->geohash_4, $munich->geohash_4);
    }

    // ── Backfill command ───────────────────────────────

    #[Test]
    public function backfill_command_sets_geohash_4_for_existing_locations()
    {
        // Create locations directly in DB bypassing the saving event
        // to simulate locations that existed before the column was added
        $loc1 = Location::withoutEvents(function () {
            return Location::create([
                'id' => (string) \Illuminate\Support\Str::orderedUuid(),
                'name' => 'Berlin',
                'city' => 'Berlin',
                'latitude' => 52.5163,
                'longitude' => 13.3777,
            ]);
        });

        $loc2 = Location::withoutEvents(function () {
            return Location::create([
                'id' => (string) \Illuminate\Support\Str::orderedUuid(),
                'name' => 'Munich',
                'city' => 'Munich',
                'latitude' => 48.1351,
                'longitude' => 11.5820,
            ]);
        });

        // Manually clear geohash_4 to simulate pre-migration state
        Location::whereKey([$loc1->id, $loc2->id])->update(['geohash_4' => null]);

        // Run the backfill command
        $this->artisan('location:add-geohash')
            ->expectsOutputToContain('Backfilling geohash_4')
            ->assertSuccessful();

        // Refresh and verify
        $loc1->refresh();
        $loc2->refresh();

        $this->assertEquals(Geohash::tilePrefix(52.5163, 13.3777, 4), $loc1->geohash_4);
        $this->assertEquals(Geohash::tilePrefix(48.1351, 11.5820, 4), $loc2->geohash_4);
    }

    #[Test]
    public function backfill_command_reports_all_done_when_no_locations_need_update()
    {
        // No locations in DB — should report nothing to do
        $this->artisan('location:add-geohash')
            ->expectsOutputToContain('All locations already have geohash_4 set')
            ->assertSuccessful();
    }

    #[Test]
    public function backfill_command_skips_locations_without_coordinates()
    {
        Location::withoutEvents(function () {
            Location::create([
                'id' => (string) \Illuminate\Support\Str::orderedUuid(),
                'name' => 'No Coords',
                'city' => 'Nowhere',
                'latitude' => null,
                'longitude' => null,
            ]);
        });

        $this->artisan('location:add-geohash')
            ->expectsOutputToContain('All locations already have geohash_4 set')
            ->assertSuccessful();
    }

    // ── Database index verification ────────────────────

    #[Test]
    public function geohash_4_column_exists_in_database()
    {
        $location = Location::create([
            'name' => 'Test',
            'city' => 'Test',
            'latitude' => 52.5163,
            'longitude' => 13.3777,
        ]);

        $this->assertTrue(
            collect(\Schema::getColumnListing('locations'))->contains('geohash_4'),
            'geohash_4 column should exist on locations table'
        );
    }

    #[Test]
    public function geohash_4_can_be_queried_by_index()
    {
        // Create several locations in Berlin (same tile)
        Location::factory()->count(5)->create([
            'latitude' => 52.5163,
            'longitude' => 13.3777,
        ]);

        $hash = Geohash::tilePrefix(52.5163, 13.3777, 4);
        $results = Location::where('geohash_4', $hash)->get();

        $this->assertCount(5, $results);
    }
}
