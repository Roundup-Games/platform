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
}
