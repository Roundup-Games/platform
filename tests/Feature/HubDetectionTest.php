<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Services\ProximityQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HubDetectionTest extends TestCase
{
    use RefreshDatabase;

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

    // ── Hub Threshold: 3+ sessions ─────────────────────

    #[Test]
    public function hub_with_three_scheduled_games_is_detected(): void
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
            'name' => 'Board Game Café Berlin',
        ]);

        // Exactly 3 scheduled games at this location
        Game::factory()->count(3)->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 10);

        $this->assertCount(1, $results);
        $hub = $results->first();
        $this->assertEquals(3, $hub->active_sessions_count);
        $this->assertEquals($location->id, $hub->location->id);
        $this->assertEquals('Board Game Café Berlin', $hub->location->name);
    }

    #[Test]
    public function hub_with_many_sessions_counts_correctly(): void
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        // 7 scheduled games at this location
        Game::factory()->count(7)->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 10);

        $this->assertCount(1, $results);
        $this->assertEquals(7, $results->first()->active_sessions_count);
    }

    #[Test]
    public function hub_with_two_scheduled_games_still_appears(): void
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        // Only 2 scheduled games — hub detection shows all locations, counting is downstream
        Game::factory()->count(2)->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 10);

        $this->assertCount(1, $results);
        $this->assertEquals(2, $results->first()->active_sessions_count);
    }

    #[Test]
    public function hub_with_zero_scheduled_games_appears_with_zero_count(): void
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        // Only completed games — no active sessions
        Game::factory()->count(3)->create([
            'location_id' => $location->id,
            'status' => 'completed',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 10);

        $this->assertCount(1, $results);
        $this->assertEquals(0, $results->first()->active_sessions_count);
    }

    // ── Multiple Hubs ──────────────────────────────────

    #[Test]
    public function multiple_hubs_returned_sorted_by_distance(): void
    {
        // Near hub: ~0.5km from center
        $nearHub = Location::factory()->create([
            'latitude' => 52.5250,
            'longitude' => 13.4140,
            'name' => 'Near Hub',
        ]);

        // Far hub: ~3km from center
        $farHub = Location::factory()->create([
            'latitude' => 52.5500,
            'longitude' => 13.4300,
            'name' => 'Far Hub',
        ]);

        Game::factory()->count(3)->create([
            'location_id' => $nearHub->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        Game::factory()->count(5)->create([
            'location_id' => $farHub->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 10);

        $this->assertCount(2, $results);
        // First result should be the closer hub
        $this->assertEquals($nearHub->id, $results[0]->location->id);
        $this->assertEquals(3, $results[0]->active_sessions_count);
        $this->assertEquals($farHub->id, $results[1]->location->id);
        $this->assertEquals(5, $results[1]->active_sessions_count);
        // Verify distance ordering
        $this->assertLessThan($results[1]->distance_km, $results[0]->distance_km);
    }

    #[Test]
    public function hubs_with_mixed_game_statuses_count_only_scheduled(): void
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        // Mix of statuses
        Game::factory()->create(['location_id' => $location->id, 'status' => 'scheduled', 'visibility' => 'public']);
        Game::factory()->create(['location_id' => $location->id, 'status' => 'completed', 'visibility' => 'public']);
        Game::factory()->create(['location_id' => $location->id, 'status' => 'scheduled', 'visibility' => 'public']);
        Game::factory()->create(['location_id' => $location->id, 'status' => 'canceled', 'visibility' => 'public']);
        Game::factory()->create(['location_id' => $location->id, 'status' => 'scheduled', 'visibility' => 'public']);

        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 10);

        $this->assertCount(1, $results);
        // Only 3 scheduled games should count
        $this->assertEquals(3, $results->first()->active_sessions_count);
    }

    // ── Hub Distance Accuracy ──────────────────────────

    #[Test]
    public function hub_distance_is_accurate_within_10km(): void
    {
        // Create a hub exactly 5km south of center
        // ~0.045 degrees latitude ≈ 5km
        $location = Location::factory()->create([
            'latitude' => $this->centerLat - 0.045,
            'longitude' => $this->centerLng,
        ]);

        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 10);

        $this->assertCount(1, $results);
        // Distance should be approximately 5km (±0.5km tolerance)
        $this->assertEqualsWithDelta(5.0, $results->first()->distance_km, 0.5);
    }

    #[Test]
    public function hub_at_exactly_radius_boundary_excluded_by_precision(): void
    {
        // Create a hub slightly beyond 10km radius
        // ~0.091 degrees latitude ≈ 10.1km
        $location = Location::factory()->create([
            'latitude' => $this->centerLat - 0.092,
            'longitude' => $this->centerLng,
        ]);

        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 10);

        $this->assertCount(0, $results);
    }

    // ── Hub Caching ────────────────────────────────────

    #[Test]
    public function hub_results_include_geohash_cache_key_components(): void
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

        // First call populates cache
        $results1 = $this->proximity->hubs($this->centerLat, $this->centerLng, 5);
        $this->assertCount(1, $results1);

        // Add more games — cached result should still show old count
        Game::factory()->count(2)->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $results2 = $this->proximity->hubs($this->centerLat, $this->centerLng, 5);
        // Cache hit — should still show 3, not 5
        $this->assertEquals(3, $results2->first()->active_sessions_count);
    }

    // ── Events at Hubs ─────────────────────────────────

    #[Test]
    public function nearby_events_work_at_hub_location(): void
    {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
            'name' => 'Convention Center',
        ]);

        // 3 scheduled games (hub activity)
        Game::factory()->count(3)->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        // 2 events at same location
        Event::factory()->count(2)->create([
            'location_id' => $location->id,
        ]);

        // Hubs shows game sessions
        $hubResults = $this->proximity->hubs($this->centerLat, $this->centerLng, 10);
        $this->assertCount(1, $hubResults);
        $this->assertEquals(3, $hubResults->first()->active_sessions_count);

        // Nearby events also works
        $eventResults = $this->proximity->nearby($this->centerLat, $this->centerLng, 10, 'event');
        $this->assertCount(2, $eventResults);
    }

    // ── Known Coordinate Verification ──────────────────

    #[Test]
    public function berlin_mitte_to_munich_center_distance_accurate(): void
    {
        // Berlin Mitte: 52.5219, 13.4117
        // Munich center: 48.1351, 11.5820
        $distance = ProximityQuery::haversineDistance(52.5219, 13.4117, 48.1351, 11.5820);

        // Known distance: ~504 km
        $this->assertEqualsWithDelta(504, $distance, 5);
    }

    #[Test]
    public function berlin_mitte_short_range_proximity_works(): void
    {
        // Brandenburg Gate: 52.5163, 13.3777 (~3.5km from Alexanderplatz)
        $nearLocation = Location::factory()->create([
            'latitude' => 52.5163,
            'longitude' => 13.3777,
            'name' => 'Brandenburg Gate',
        ]);

        // Potsdam: 52.3906, 13.0648 (~26km from Alexanderplatz)
        $farLocation = Location::factory()->create([
            'latitude' => 52.3906,
            'longitude' => 13.0648,
            'name' => 'Potsdam',
        ]);

        Game::factory()->create([
            'location_id' => $nearLocation->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);
        Game::factory()->create([
            'location_id' => $farLocation->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        // 10km radius should include Brandenburg Gate but not Potsdam
        $results = $this->proximity->nearby(52.5219, 13.4117, 10, 'game');

        $this->assertCount(1, $results);
        $this->assertEquals($nearLocation->id, $results->first()->location->id);

        // 50km radius should include both
        $results50 = $this->proximity->nearby(52.5219, 13.4117, 50, 'game');
        $this->assertCount(2, $results50);
    }

    #[Test]
    public function munich_center_proximity_excludes_distant_locations(): void
    {
        // Munich Marienplatz: 48.1374, 11.5753
        $munichLocation = Location::factory()->create([
            'latitude' => 48.1374,
            'longitude' => 11.5753,
            'name' => 'Munich Center',
        ]);

        // Vienna: 48.2082, 16.3738 (~355km from Munich)
        $viennaLocation = Location::factory()->create([
            'latitude' => 48.2082,
            'longitude' => 16.3738,
            'name' => 'Vienna',
        ]);

        Game::factory()->create([
            'location_id' => $munichLocation->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);
        Game::factory()->create([
            'location_id' => $viennaLocation->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        // 10km radius from Munich center
        $results = $this->proximity->nearby(48.1374, 11.5753, 10, 'game');

        $this->assertCount(1, $results);
        $this->assertEquals($munichLocation->id, $results->first()->location->id);
        $this->assertLessThan(10, $results->first()->distance_km);
    }
}
