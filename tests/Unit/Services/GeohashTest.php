<?php

namespace Tests\Unit\Services;

use App\Services\Geohash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GeohashTest extends TestCase
{
    // 1. Known coordinate encoding — Berlin
    public function test_berlin_encodes_to_known_prefix(): void
    {
        $hash = Geohash::encode(52.52, 13.405);
        $this->assertStringStartsWith('u33dc', $hash);
        $this->assertSame(12, strlen($hash));
    }

    // 2. tilePrefix precision
    public function test_tile_prefix_returns_requested_length(): void
    {
        $this->assertSame(4, strlen(Geohash::tilePrefix(52.52, 13.405, 4)));
        $this->assertSame(5, strlen(Geohash::tilePrefix(52.52, 13.405, 5)));
    }

    // 3. Determinism
    public function test_encode_is_deterministic(): void
    {
        $results = array_map(
            fn () => Geohash::encode(52.52, 13.405),
            range(1, 10),
        );
        $this->assertCount(1, array_unique($results));
    }

    // 4 & 8. Edge: equator / prime meridian (0, 0)
    public function test_equator_prime_meridian_encodes(): void
    {
        $hash = Geohash::encode(0.0, 0.0);
        $this->assertSame(12, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9b-hjkmnp-z]+$/', $hash);
    }

    // 5. Edge: north pole
    public function test_north_pole_encodes(): void
    {
        $hash = Geohash::encode(90.0, 0.0);
        $this->assertSame(12, strlen($hash));
    }

    // 6. Edge: south pole
    public function test_south_pole_encodes(): void
    {
        $hash = Geohash::encode(-90.0, 0.0);
        $this->assertSame(12, strlen($hash));
    }

    // 7. Edge: dateline
    public function test_dateline_both_longitudes_encode(): void
    {
        $east = Geohash::encode(0.0, 180.0);
        $west = Geohash::encode(0.0, -180.0);
        $this->assertSame(12, strlen($east));
        $this->assertSame(12, strlen($west));
    }

    // 9. Precision 1 vs 12
    public function test_different_precision_produces_different_lengths(): void
    {
        $short = Geohash::encode(52.52, 13.405, 1);
        $long = Geohash::encode(52.52, 13.405, 12);
        $this->assertSame(1, strlen($short));
        $this->assertSame(12, strlen($long));
        $this->assertNotSame($short, $long);
    }

    // 10. prefixBounds roundtrip — Berlin coords inside bounds
    public function test_prefix_bounds_contains_original_coordinates(): void
    {
        $bounds = Geohash::prefixBounds('u33d');
        $this->assertGreaterThanOrEqual($bounds['minLat'], 52.52);
        $this->assertLessThanOrEqual($bounds['maxLat'], 52.52);
        $this->assertGreaterThanOrEqual($bounds['minLng'], 13.405);
        $this->assertLessThanOrEqual($bounds['maxLng'], 13.405);
    }

    // 11. prefixBounds with invalid char — partial decode still returns valid bounds
    public function test_prefix_bounds_with_invalid_char_returns_valid_bounds(): void
    {
        $bounds = Geohash::prefixBounds('u3@');
        $this->assertArrayHasKey('minLat', $bounds);
        $this->assertArrayHasKey('maxLat', $bounds);
        $this->assertArrayHasKey('minLng', $bounds);
        $this->assertArrayHasKey('maxLng', $bounds);
        // Only the valid 'u3' portion is decoded
        $validBounds = Geohash::prefixBounds('u3');
        $this->assertEquals($validBounds, $bounds);
    }

    // 12. Adjacent tiles differ at precision 5
    public function test_adjacent_coordinates_produce_different_tile_prefixes(): void
    {
        $a = Geohash::tilePrefix(52.52, 13.405, 5);
        $b = Geohash::tilePrefix(52.60, 13.405, 5);
        $this->assertNotSame($a, $b);
    }
}
