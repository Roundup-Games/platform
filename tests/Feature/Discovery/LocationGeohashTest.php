<?php

namespace Tests\Feature\Discovery;

use App\Models\Location;
use App\Services\Geohash;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocationGeohashTest extends TestCase
{
    use DatabaseTransactions;

    // ── Auto-compute on save ───────────────────────────

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

    // ── Backfill command ───────────────────────────────

    #[Test]
    public function backfill_command_sets_geohash_4_for_existing_locations()
    {
        // Create locations directly in DB bypassing the saving event
        // to simulate locations that existed before the column was added
        $loc1 = Location::withoutEvents(function () {
            return Location::create([
                'id' => (string) Str::orderedUuid(),
                'name' => 'Berlin',
                'city' => 'Berlin',
                'latitude' => 52.5163,
                'longitude' => 13.3777,
            ]);
        });

        $loc2 = Location::withoutEvents(function () {
            return Location::create([
                'id' => (string) Str::orderedUuid(),
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
                'id' => (string) Str::orderedUuid(),
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
}
