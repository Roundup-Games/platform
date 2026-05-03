<?php

namespace Tests\Models;

use App\Models\Game;
use App\Models\Location;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class LocationModelTest extends TestCase
{
    use SetsUpLocale;

    // ── Deduplication (non-trivial upsert logic) ──────────────

    public function test_find_or_create_by_place_id_creates_new(): void
    {
        $location = Location::findOrCreateByPlaceId('ChIJ_new_place', [
            'name' => 'New Place',
            'latitude' => 52.52,
            'longitude' => 13.40,
            'city' => 'Berlin',
            'country' => 'DEU',
        ]);

        $this->assertDatabaseHas('locations', ['place_id' => 'ChIJ_new_place']);
        $this->assertEquals('New Place', $location->name);
        $this->assertTrue($location->wasRecentlyCreated);
    }

    public function test_find_or_create_by_place_id_returns_existing(): void
    {
        $existing = Location::factory()->create([
            'place_id' => 'ChIJ_existing',
            'name' => 'Existing Place',
        ]);

        $found = Location::findOrCreateByPlaceId('ChIJ_existing', [
            'name' => 'Should Not Override',
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $this->assertEquals($existing->id, $found->id);
        $this->assertEquals('Existing Place', $found->name);
        $this->assertFalse($found->wasRecentlyCreated);
    }

    // ── Bounding Box Scope (query logic) ─────────────────────

    public function test_within_bounds_scope_returns_matching_locations(): void
    {
        $inside = Location::factory()->create([
            'latitude' => 52.52,
            'longitude' => 13.40,
            'place_id' => 'inside_1',
        ]);
        $outside = Location::factory()->create([
            'latitude' => 48.21,
            'longitude' => 16.37,
            'place_id' => 'outside_1',
        ]);

        $results = Location::withinBounds(52.43, 52.61, 13.26, 13.54)->get();

        $this->assertTrue($results->contains($inside));
        $this->assertFalse($results->contains($outside));
    }

    // ── Haversine Distance (mathematical correctness) ─────────

    public function test_distance_to_returns_approximate_km(): void
    {
        $location = Location::factory()->create([
            'latitude' => 52.5200,
            'longitude' => 13.4050,
        ]);

        // Berlin to Munich is approximately 505 km
        $distance = $location->distanceTo(48.1351, 11.5820);

        $this->assertGreaterThan(490, $distance);
        $this->assertLessThan(520, $distance);
    }

    // ── Full Address Formatting ───────────────────────────────

    public function test_full_address_formats_correctly(): void
    {
        $location = Location::factory()->create([
            'address' => 'Musterstraße 42',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country' => 'DEU',
        ]);

        $this->assertEquals('Musterstraße 42, 10115 Berlin, DEU', $location->fullAddress());
    }

    public function test_full_address_handles_missing_parts(): void
    {
        $location = Location::factory()->create([
            'address' => null,
            'postal_code' => null,
            'city' => 'Berlin',
            'country' => 'DEU',
        ]);

        $this->assertEquals('Berlin, DEU', $location->fullAddress());
    }

    // ── FK Nullification on Delete (data integrity) ───────────

    public function test_location_deleted_nullifies_game_fk(): void
    {
        $location = Location::factory()->create();
        $game = Game::factory()->create(['location_id' => $location->id]);

        $location->delete();

        $this->assertNull($game->fresh()->location_id);
    }
}
