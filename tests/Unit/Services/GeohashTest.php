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
        $this->assertGreaterThanOrEqual($bounds->minLat, 52.52);
        $this->assertLessThanOrEqual($bounds->maxLat, 52.52);
        $this->assertGreaterThanOrEqual($bounds->minLng, 13.405);
        $this->assertLessThanOrEqual($bounds->maxLng, 13.405);
    }

    // 11. prefixBounds with invalid char — partial decode still returns valid bounds
    public function test_prefix_bounds_with_invalid_char_returns_valid_bounds(): void
    {
        $bounds = Geohash::prefixBounds('u3@');
        $this->assertTrue(property_exists($bounds, 'minLat'));
        $this->assertTrue(property_exists($bounds, 'maxLat'));
        $this->assertTrue(property_exists($bounds, 'minLng'));
        $this->assertTrue(property_exists($bounds, 'maxLng'));
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

    // 13. Known coordinate encoding — verified against multiple implementations
    public function test_encodes_berlin_to_exact_hash(): void
    {
        $hash = Geohash::encode(52.5163, 13.3777, 5);
        $this->assertEquals('u33db', $hash);
    }

    // 14. Zero-zero encoding
    public function test_encodes_zero_zero(): void
    {
        $hash = Geohash::encode(0, 0, 5);
        $this->assertEquals('s0000', $hash);
    }

    // 15. Negative coordinates — Sydney
    public function test_encodes_negative_coordinates(): void
    {
        $hash = Geohash::encode(-33.8688, 151.2093, 5);
        $this->assertEquals('r3gx2', $hash);
    }

    // 16. European city proximity — nearby points share longer prefixes
    #[DataProvider('europeanCityProvider')]
    public function test_nearby_cities_share_geohash_prefixes(float $lat1, float $lng1, float $lat2, float $lng2, int $sharedPrefix): void
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

    // 17. Tile prefix is start of full hash
    public function test_tile_prefix_is_start_of_full_hash(): void
    {
        $fullHash = Geohash::encode(52.5163, 13.3777, 12);
        $prefix = Geohash::tilePrefix(52.5163, 13.3777, 5);
        $this->assertEquals(substr($fullHash, 0, 5), $prefix);
    }

    // 18. Prefix bounds grows with shorter prefix
    public function test_prefix_bounds_grows_with_shorter_prefix(): void
    {
        $bounds4 = Geohash::prefixBounds(Geohash::tilePrefix(52.5163, 13.3777, 4));
        $bounds5 = Geohash::prefixBounds(Geohash::tilePrefix(52.5163, 13.3777, 5));

        $span4 = ($bounds4->maxLat - $bounds4->minLat);
        $span5 = ($bounds5->maxLat - $bounds5->minLat);

        $this->assertGreaterThan($span5, $span4, 'Shorter prefix should have larger bounds');
    }
}
