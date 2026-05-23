<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Services\ProximityQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HubDetectionTest extends TestCase
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

    // ── Hub Threshold ──────────────────────────────────

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
}
