<?php

namespace Tests\Unit;

use App\Services\Geohash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GeohashTest extends TestCase
{
    // ── Encoding ───────────────────────────────────────

    #[Test]
    public function it_encodes_known_coordinates()
    {
        // Verified against multiple geohash implementations
        $hash = Geohash::encode(52.5163, 13.3777, 5);
        $this->assertEquals('u33db', $hash);
    }

    #[Test]
    public function it_encodes_with_default_precision()
    {
        $hash = Geohash::encode(52.5163, 13.3777);
        $this->assertEquals(12, strlen($hash));
    }

    #[Test]
    public function it_encodes_with_custom_precision()
    {
        $hash = Geohash::encode(52.5163, 13.3777, 8);
        $this->assertEquals(8, strlen($hash));
    }

    #[Test]
    public function it_encodes_zero_zero()
    {
        // 0,0 → s7zzzzzzzzzz (first char 's' covers the Gulf of Guinea)
        $hash = Geohash::encode(0, 0, 5);
        $this->assertEquals('s0000', $hash);
    }

    #[Test]
    public function it_encodes_negative_coordinates()
    {
        // Sydney: -33.8688, 151.2093
        $hash = Geohash::encode(-33.8688, 151.2093, 5);
        $this->assertEquals('r3gx2', $hash);
    }

    #[Test]
    public function it_encodes_poles()
    {
        // North pole: 90, 0
        $hash = Geohash::encode(90, 0, 5);
        $this->assertNotEmpty($hash);
        $this->assertEquals(5, strlen($hash));

        // South pole: -90, 0
        $hash = Geohash::encode(-90, 0, 5);
        $this->assertNotEmpty($hash);
        $this->assertEquals(5, strlen($hash));
    }

    #[Test]
    public function it_encodes_international_date_line()
    {
        // Coordinates near 180° longitude
        $hash = Geohash::encode(0, 179.9, 5);
        $this->assertNotEmpty($hash);

        $hash = Geohash::encode(0, -179.9, 5);
        $this->assertNotEmpty($hash);
    }

    #[Test]
    #[DataProvider('europeanCityProvider')]
    public function it_produces_consistent_prefixes_for_nearby_points(float $lat1, float $lng1, float $lat2, float $lng2, int $sharedPrefix)
    {
        $hash1 = Geohash::encode($lat1, $lng1, 8);
        $hash2 = Geohash::encode($lat2, $lng2, 8);

        $shared = 0;
        for ($i = 0; $i < min(strlen($hash1), strlen($hash2)); $i++) {
            if ($hash1[$i] === $hash2[$i]) {
                $shared++;
            } else {
                break;
            }
        }

        $this->assertGreaterThanOrEqual($sharedPrefix, $shared,
            "Points ({$lat1},{$lng1}) and ({$lat2},{$lng2}) should share at least {$sharedPrefix} geohash characters. Got: {$hash1} vs {$hash2}");
    }

    public static function europeanCityProvider(): array
    {
        return [
            'Berlin central vs nearby' => [52.5200, 13.4050, 52.5100, 13.3900, 4],
            'Munich vs Berlin' => [48.1351, 11.5820, 52.5200, 13.4050, 1],
            'Vienna vs Munich' => [48.2082, 16.3738, 48.1351, 11.5820, 2],
        ];
    }

    // ── Tile Prefix ────────────────────────────────────

    #[Test]
    public function tile_prefix_returns_requested_length()
    {
        $prefix = Geohash::tilePrefix(52.5163, 13.3777, 5);
        $this->assertEquals(5, strlen($prefix));
    }

    #[Test]
    public function tile_prefix_is_start_of_full_hash()
    {
        $fullHash = Geohash::encode(52.5163, 13.3777, 12);
        $prefix = Geohash::tilePrefix(52.5163, 13.3777, 5);

        $this->assertEquals(substr($fullHash, 0, 5), $prefix);
    }

    #[Test]
    public function nearby_points_share_tile_prefix()
    {
        // Two points within 500m should share a 5-char prefix
        $prefix1 = Geohash::tilePrefix(52.5163, 13.3777, 5);
        $prefix2 = Geohash::tilePrefix(52.5170, 13.3780, 5);

        $this->assertEquals($prefix1, $prefix2);
    }

    // ── Prefix Bounds ──────────────────────────────────

    #[Test]
    public function prefix_bounds_contains_original_point()
    {
        $lat = 52.5163;
        $lng = 13.3777;
        $prefix = Geohash::tilePrefix($lat, $lng, 5);
        $bounds = Geohash::prefixBounds($prefix);

        $this->assertGreaterThanOrEqual($bounds['minLat'], $lat);
        $this->assertLessThanOrEqual($bounds['maxLat'], $lat);
        $this->assertGreaterThanOrEqual($bounds['minLng'], $lng);
        $this->assertLessThanOrEqual($bounds['maxLng'], $lng);
    }

    #[Test]
    public function prefix_bounds_grows_with_shorter_prefix()
    {
        $bounds4 = Geohash::prefixBounds(Geohash::tilePrefix(52.5163, 13.3777, 4));
        $bounds5 = Geohash::prefixBounds(Geohash::tilePrefix(52.5163, 13.3777, 5));

        $span4 = ($bounds4['maxLat'] - $bounds4['minLat']);
        $span5 = ($bounds5['maxLat'] - $bounds5['minLat']);

        $this->assertGreaterThan($span5, $span4, 'Shorter prefix should have larger bounds');
    }
}
