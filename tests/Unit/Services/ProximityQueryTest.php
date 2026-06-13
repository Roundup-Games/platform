<?php

namespace Tests\Unit\Services;

use App\Services\ProximityQuery;
use PHPUnit\Framework\TestCase;

class ProximityQueryTest extends TestCase
{
    private ProximityQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new ProximityQuery;
    }

    // ── Haversine distance: known city pairs (within 1% tolerance) ──────

    public function test_berlin_to_munich(): void
    {
        $distance = ProximityQuery::haversineDistance(52.52, 13.405, 48.135, 11.582);

        $this->assertEqualsWithDelta(504, $distance, 5.04, 'Berlin→Munich should be ~504 km');
    }

    public function test_london_to_paris(): void
    {
        $distance = ProximityQuery::haversineDistance(51.507, -0.128, 48.856, 2.352);

        $this->assertEqualsWithDelta(344, $distance, 3.44, 'London→Paris should be ~344 km');
    }

    public function test_new_york_to_los_angeles(): void
    {
        $distance = ProximityQuery::haversineDistance(40.713, -74.006, 34.052, -118.244);

        $this->assertEqualsWithDelta(3944, $distance, 39.44, 'NYC→LA should be ~3944 km');
    }

    public function test_tokyo_to_sydney(): void
    {
        $distance = ProximityQuery::haversineDistance(35.676, 139.650, -33.868, 151.209);

        $this->assertEqualsWithDelta(7826, $distance, 78.26, 'Tokyo→Sydney should be ~7826 km');
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

    // ── Negative coordinates (southern hemisphere) ──────────────────────

    public function test_sao_paulo_to_buenos_aires(): void
    {
        $distance = ProximityQuery::haversineDistance(-23.55, -46.63, -34.60, -58.38);

        $this->assertEqualsWithDelta(1660, $distance, 16.6, 'São Paulo→Buenos Aires should be ~1660 km');
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
