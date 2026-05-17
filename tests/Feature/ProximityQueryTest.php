<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Services\ProximityQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProximityQueryTest extends TestCase
{
    use DatabaseTransactions;
    private ProximityQuery $proximity;

    // Berlin Alexanderplatz
    private float $centerLat = 52.5219;
    private float $centerLng = 13.4117;

    protected function setUp(): void
    {
        parent::setUp();
        $this->proximity = new ProximityQuery;
        Cache::flush();
    }

    // ── Bounding Box ───────────────────────────────────

    // smoke: bounding box contains center
    #[Group('smoke')]
    #[Test]
    public function bounding_box_contains_center_point()
    {
        $box = $this->proximity->boundingBox($this->centerLat, $this->centerLng, 10);

        $this->assertGreaterThanOrEqual($box['minLat'], $this->centerLat);
        $this->assertLessThanOrEqual($box['maxLat'], $this->centerLat);
        $this->assertGreaterThanOrEqual($box['minLng'], $this->centerLng);
        $this->assertLessThanOrEqual($box['maxLng'], $this->centerLng);
    }

    #[Test]
    public function bounding_box_scales_with_radius()
    {
        $box10 = $this->proximity->boundingBox(52.0, 13.0, 10);
        $box50 = $this->proximity->boundingBox(52.0, 13.0, 50);

        $latSpan10 = $box10['maxLat'] - $box10['minLat'];
        $latSpan50 = $box50['maxLat'] - $box50['minLat'];

        $this->assertGreaterThan($latSpan10, $latSpan50);
    }

    #[Test]
    public function bounding_box_clamps_to_poles()
    {
        // North pole + small radius should not exceed 90
        $box = $this->proximity->boundingBox(89.9, 0, 200);
        $this->assertEquals(90, $box['maxLat']);

        // South pole + small radius should not go below -90
        $box = $this->proximity->boundingBox(-89.9, 0, 200);
        $this->assertEquals(-90, $box['minLat']);
    }

    #[Test]
    public function bounding_box_longitude_wider_at_equator()
    {
        // At equator, longitude span ≈ radius span
        $boxEquator = $this->proximity->boundingBox(0, 0, 100);
        $latSpan = $boxEquator['maxLat'] - $boxEquator['minLat'];
        $lngSpan = $boxEquator['maxLng'] - $boxEquator['minLng'];

        // Longitude span should be roughly equal to lat span at equator
        $this->assertEqualsWithDelta($latSpan, $lngSpan, 0.1);
    }

    // ── Haversine Distance ─────────────────────────────

    #[Test]
    public function haversine_same_point_is_zero()
    {
        $distance = ProximityQuery::haversineDistance(52.5219, 13.4117, 52.5219, 13.4117);
        $this->assertEquals(0.0, $distance);
    }

    #[Test]
    public function haversine_known_distance()
    {
        // Berlin to Munich: ~504 km (haversine)
        $distance = ProximityQuery::haversineDistance(52.5200, 13.4050, 48.1351, 11.5820);
        $this->assertEqualsWithDelta(504, $distance, 5); // ±5km tolerance
    }

    #[Test]
    public function haversine_is_symmetric()
    {
        $d1 = ProximityQuery::haversineDistance(52.5, 13.4, 48.1, 11.6);
        $d2 = ProximityQuery::haversineDistance(48.1, 11.6, 52.5, 13.4);
        $this->assertEquals($d1, $d2);
    }

    // ── Nearby Query ───────────────────────────────────

    #[Test]
    public function nearby_returns_games_within_radius()
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->nearby($this->centerLat, $this->centerLng, 5, 'game');

        $this->assertCount(1, $results);
        $this->assertLessThan(1, $results->first()->distance_km);
        $this->assertInstanceOf(Game::class, $results->first()->entity);
    }

    #[Test]
    public function nearby_excludes_games_beyond_radius()
    {
        // Location 100km away
        $farLocation = Location::factory()->create([
            'latitude' => 53.0,
            'longitude' => 13.0,
        ]);

        Game::factory()->create([
            'location_id' => $farLocation->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->nearby($this->centerLat, $this->centerLng, 5, 'game');

        $this->assertCount(0, $results);
    }

    #[Test]
    public function nearby_returns_results_sorted_by_distance()
    {
        $nearLocation = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $farLocation = Location::factory()->create([
            'latitude' => 52.5400,
            'longitude' => 13.4400,
        ]);

        Game::factory()->create([
            'location_id' => $farLocation->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);
        Game::factory()->create([
            'location_id' => $nearLocation->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->nearby($this->centerLat, $this->centerLng, 50, 'game');

        $this->assertCount(2, $results);
        $this->assertLessThanOrEqual(
            $results[1]->distance_km,
            $results[0]->distance_km,
            'Results should be sorted by distance ascending'
        );
    }

    #[Test]
    public function nearby_filters_by_scheduled_status_by_default()
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        // Completed game should be excluded
        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'completed',
            'visibility' => 'public',
        ]);
        // Scheduled game should be included
        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->nearby($this->centerLat, $this->centerLng, 5, 'game');

        $this->assertCount(1, $results);
        $this->assertEquals(\App\Enums\GameStatus::Scheduled, $results->first()->entity->status);
    }

    #[Test]
    public function nearby_skips_locations_without_coordinates()
    {
        $locationNoCoords = Location::factory()->create([
            'latitude' => null,
            'longitude' => null,
            'address' => 'Some Street 1',
        ]);

        Game::factory()->create([
            'location_id' => $locationNoCoords->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->nearby($this->centerLat, $this->centerLng, 50, 'game');

        $this->assertCount(0, $results);
    }

    #[Test]
    public function nearby_returns_events()
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        Event::factory()->create([
            'location_id' => $location->id,
        ]);

        $results = $this->proximity->nearby($this->centerLat, $this->centerLng, 5, 'event');

        $this->assertCount(1, $results);
        $this->assertInstanceOf(Event::class, $results->first()->entity);
    }

    #[Test]
    public function nearby_returns_empty_for_unknown_entity_type()
    {
        $results = $this->proximity->nearby($this->centerLat, $this->centerLng, 50, 'unknown');

        $this->assertCount(0, $results);
    }

    #[Test]
    public function nearby_respects_limit_option()
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        // Create 5 games at the same location
        Game::factory()->count(5)->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->nearby($this->centerLat, $this->centerLng, 5, 'game', ['limit' => 2]);

        $this->assertCount(2, $results);
    }

    #[Test]
    public function nearby_does_not_log_fast_queries_as_slow()
    {
        Log::spy();

        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        // Fast query — should NOT trigger slow query log
        $this->proximity->nearby($this->centerLat, $this->centerLng, 5, 'game');

        // Assert no info-level log was written (debug logs from SeoCacheService are fine)
        Log::shouldNotHaveReceived('info');
    }

    // ── Hubs Query ─────────────────────────────────────

    #[Test]
    public function hubs_returns_locations_with_session_counts()
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        Game::factory()->count(3)->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 5);

        $this->assertCount(1, $results);
        $this->assertEquals(3, $results->first()->active_sessions_count);
        $this->assertInstanceOf(Location::class, $results->first()->location);
    }

    #[Test]
    public function hubs_only_counts_scheduled_games()
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'completed',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 5);

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results->first()->active_sessions_count);
    }

    #[Test]
    public function hubs_caches_results_by_geohash()
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        // First call — cache miss, query DB
        $results1 = $this->proximity->hubs($this->centerLat, $this->centerLng, 5);
        $this->assertCount(1, $results1);

        // Delete the location — cached result should still return it
        $location->delete();

        $results2 = $this->proximity->hubs($this->centerLat, $this->centerLng, 5);
        $this->assertCount(1, $results2);
    }

    #[Test]
    public function hubs_returns_empty_for_area_with_no_locations()
    {
        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 5);

        $this->assertCount(0, $results);
    }

    #[Test]
    public function hubs_excludes_locations_beyond_radius()
    {
        Location::factory()->create([
            'latitude' => 48.1351,  // Munich — ~500km from Berlin
            'longitude' => 11.5820,
        ]);

        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 10);

        $this->assertCount(0, $results);
    }
}
