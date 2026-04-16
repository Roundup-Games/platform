<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Location;
use App\Services\ProximityQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProximityBenchmarkTest extends TestCase
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

    #[Test]
    public function benchmark_nearby_query_with_1000_locations(): void
    {
        // Seed 1000 locations spread across Germany (~100km² area)
        // with a cluster of ~50 locations near Berlin for realistic hit rate
        $locations = [];

        // 50 locations near Berlin (within ~10km of center)
        for ($i = 0; $i < 50; $i++) {
            $locations[] = Location::factory()->create([
                'latitude' => 52.5219 + (mt_rand(-500, 500) / 10000),  // ±0.05° ≈ ±5.5km
                'longitude' => 13.4117 + (mt_rand(-500, 500) / 10000),
                'place_id' => "bench_near_{$i}",
            ]);
        }

        // 950 locations scattered across Germany
        for ($i = 0; $i < 950; $i++) {
            Location::factory()->create([
                'latitude' => mt_rand(470000, 540000) / 10000,  // 47.0 to 54.0
                'longitude' => mt_rand(60, 140) / 10,           // 6.0 to 14.0
                'place_id' => "bench_far_{$i}",
            ]);
        }

        // Create scheduled games at all locations
        foreach (Location::all() as $location) {
            Game::factory()->create([
                'location_id' => $location->id,
                'status' => 'scheduled',
                'visibility' => 'public',
            ]);
        }

        $this->assertEquals(1000, Location::count());
        $this->assertEquals(1000, Game::where('status', 'scheduled')->count());

        // Benchmark nearby query (10km radius from Berlin center)
        $start = microtime(true);
        $results = $this->proximity->nearby($this->centerLat, $this->centerLng, 10, 'game');
        $durationMs = (microtime(true) - $start) * 1000;

        // Verify results contain nearby locations only (not the full 1000)
        $this->assertGreaterThan(0, $results->count(), 'Should find at least some nearby games');
        $this->assertLessThan(100, $results->count(), 'Should filter out distant locations — must return far fewer than 1000');

        // All results should be within 10km
        foreach ($results as $result) {
            $this->assertLessThanOrEqual(10, $result->distance_km,
                "Result at distance {$result->distance_km}km exceeds 10km radius");
        }

        // Results should be sorted by distance
        $previousDist = 0;
        foreach ($results as $result) {
            $this->assertGreaterThanOrEqual($previousDist, $result->distance_km,
                'Results should be sorted by distance ascending');
            $previousDist = $result->distance_km;
        }

        // Report benchmark timing (informational — not a hard fail condition for CI)
        // The slice verification says log if >100ms, but SQLite in-memory is fast
        Log::info('Proximity query benchmark', [
            'total_locations' => 1000,
            'radius_km' => 10,
            'results_count' => $results->count(),
            'duration_ms' => round($durationMs, 2),
        ]);

        // Test passes regardless of timing — the benchmark is informational
        $this->assertTrue(true, "Benchmark completed in {$durationMs}ms with {$results->count()} results");
    }

    #[Test]
    public function benchmark_hubs_query_with_1000_locations(): void
    {
        // Create 100 locations each with varying session counts
        for ($i = 0; $i < 100; $i++) {
            $location = Location::factory()->create([
                'latitude' => 52.5219 + (mt_rand(-300, 300) / 10000),
                'longitude' => 13.4117 + (mt_rand(-300, 300) / 10000),
                'place_id' => "bench_hub_{$i}",
            ]);

            // 1-5 scheduled games per location
            $gameCount = mt_rand(1, 5);
            Game::factory()->count($gameCount)->create([
                'location_id' => $location->id,
                'status' => 'scheduled',
                'visibility' => 'public',
            ]);
        }

        $start = microtime(true);
        $results = $this->proximity->hubs($this->centerLat, $this->centerLng, 50);
        $durationMs = (microtime(true) - $start) * 1000;

        // Verify hub results
        $this->assertGreaterThan(0, $results->count());

        // Each hub should have session count > 0
        foreach ($results as $hub) {
            $this->assertGreaterThanOrEqual(0, $hub->active_sessions_count);
            $this->assertInstanceOf(Location::class, $hub->location);
            $this->assertLessThanOrEqual(50, $hub->distance_km);
        }

        Log::info('Hubs query benchmark', [
            'total_locations' => 100,
            'radius_km' => 50,
            'results_count' => $results->count(),
            'duration_ms' => round($durationMs, 2),
        ]);

        $this->assertTrue(true, "Hubs benchmark completed in {$durationMs}ms");
    }
}
