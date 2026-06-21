<?php

namespace Tests\Unit\Services;

use App\Services\ProximityQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure haversine math tests for ProximityQuery.
 *
 * Renamed from ProximityQueryTest to disambiguate from
 * tests/Feature/Services/ProximityQueryTest, which exercises the
 * DB-query side of the same service.
 */
class ProximityMathTest extends TestCase
{
    private ProximityQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new ProximityQuery;
    }

    // ── Haversine distance: known city pairs (within 1% tolerance) ──────

    /**
     * Collapsed from five per-city-pair tests into a single dataset-driven
     * test. Each row is a known city pair with its expected great-circle
     * distance (verified against multiple references).
     */
    public static function knownCityPairProvider(): array
    {
        return [
            'Berlin → Munich' => [52.52,  13.405, 48.135, 11.582, 504,  5.04],
            'London → Paris' => [51.507, -0.128, 48.856, 2.352,  344,  3.44],
            'New York → Los Angeles' => [40.713, -74.006, 34.052, -118.244, 3944, 39.44],
            'Tokyo → Sydney' => [35.676, 139.650, -33.868, 151.209, 7826, 78.26],
            'São Paulo → Buenos Aires' => [-23.55, -46.63, -34.60, -58.38, 1660, 16.6],
        ];
    }

    #[DataProvider('knownCityPairProvider')]
    public function test_haversine_distance_for_known_city_pairs(float $lat1, float $lng1, float $lat2, float $lng2, float $expectedKm, float $toleranceKm): void
    {
        $distance = ProximityQuery::haversineDistance($lat1, $lng1, $lat2, $lng2);

        $this->assertEqualsWithDelta($expectedKm, $distance, $toleranceKm);
    }

    // ── Same point distance ─────────────────────────────────────────────

    public function test_same_point_returns_zero(): void
    {
        $distance = ProximityQuery::haversineDistance(52.52, 13.405, 52.52, 13.405);

        $this->assertSame(0.0, $distance);
    }

    // ── Antipodal points ────────────────────────────────────────────────

    public function test_antipodal_points(): void
    {
        // Antipodal: opposite sides of earth → ~20,015 km (half circumference)
        $distance = ProximityQuery::haversineDistance(0, 0, 0, 180);

        $this->assertEqualsWithDelta(20015, $distance, 200.15, 'Antipodal points should be ~20,015 km');
    }

    // ── Bounding box: contains origin point ─────────────────────────────

    public function test_bounding_box_contains_origin(): void
    {
        $box = $this->query->boundingBox(52.52, 13.405, 50);

        $this->assertGreaterThanOrEqual($box->minLat, 52.52, 'Origin lat >= minLat');
        $this->assertLessThanOrEqual($box->maxLat, 52.52, 'Origin lat <= maxLat');
        $this->assertGreaterThanOrEqual($box->minLng, 13.405, 'Origin lng >= minLng');
        $this->assertLessThanOrEqual($box->maxLng, 13.405, 'Origin lng <= maxLng');
    }

    // ── Bounding box: size scales with radius ───────────────────────────

    public function test_larger_radius_produces_larger_box(): void
    {
        $small = $this->query->boundingBox(52.52, 13.405, 10);
        $large = $this->query->boundingBox(52.52, 13.405, 100);

        $smallLatSpan = $small->maxLat - $small->minLat;
        $largeLatSpan = $large->maxLat - $large->minLat;

        $this->assertLessThan($largeLatSpan, $smallLatSpan, '10km box lat span < 100km box lat span');

        $smallLngSpan = $small->maxLng - $small->minLng;
        $largeLngSpan = $large->maxLng - $large->minLng;

        $this->assertLessThan($largeLngSpan, $smallLngSpan, '10km box lng span < 100km box lng span');
    }

    // ── Bounding box: high latitude has wider longitude span ────────────

    public function test_high_latitude_has_wider_longitude_span(): void
    {
        $equator = $this->query->boundingBox(0, 0, 100);
        $arctic = $this->query->boundingBox(85, 0, 100);

        $equatorLngSpan = $equator->maxLng - $equator->minLng;
        $arcticLngSpan = $arctic->maxLng - $arctic->minLng;

        $this->assertGreaterThan(
            $equatorLngSpan,
            $arcticLngSpan,
            'Longitude span at 85°N should be wider than at equator for same radius'
        );
    }

    // ── Bounding box: symmetrical at equator ────────────────────────────

    public function test_bounding_box_symmetrical_at_equator(): void
    {
        $box = $this->query->boundingBox(0, 0, 100);

        $latSpan = $box->maxLat - $box->minLat;
        $lngSpan = $box->maxLng - $box->minLng;

        // At the equator, cos(0)=1 so lat and lng offsets should be equal
        $this->assertEqualsWithDelta($latSpan, $lngSpan, 0.001, 'Lat/lng spans should be equal at equator');
    }
}
