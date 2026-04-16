<?php

namespace Tests\Models;

use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationModelTest extends TestCase
{
    use RefreshDatabase;

    // ── Model Basics ───────────────────────────────────

    public function test_can_create_location_with_all_fields(): void
    {
        $location = Location::factory()->create([
            'name' => 'Test Venue',
            'description' => 'A great place to play',
            'address' => 'Musterstraße 42',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DEU',
            'latitude' => 52.5200,
            'longitude' => 13.4050,
            'place_id' => 'ChIJ_test123',
            'source' => 'google',
            'metadata' => ['phone' => '+49 30 123456'],
        ]);

        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
            'name' => 'Test Venue',
            'city' => 'Berlin',
            'country' => 'DEU',
            'place_id' => 'ChIJ_test123',
            'source' => 'google',
        ]);

        $this->assertEquals(52.5200, (float) $location->latitude);
        $this->assertEquals(13.4050, (float) $location->longitude);
        $this->assertEquals(['phone' => '+49 30 123456'], $location->metadata);
    }

    public function test_location_casts_latitude_and_longitude_as_decimal(): void
    {
        $location = Location::factory()->create([
            'latitude' => 48.2081743,
            'longitude' => 16.3738189,
        ]);

        $fresh = $location->fresh();
        $this->assertEquals('48.2081743', (string) $fresh->latitude);
        $this->assertEquals('16.3738189', (string) $fresh->longitude);
    }

    public function test_location_casts_metadata_as_array(): void
    {
        $location = Location::factory()->create([
            'metadata' => ['opening_hours' => '9-18', 'wheelchair' => true],
        ]);

        $this->assertIsArray($location->metadata);
        $this->assertEquals('9-18', $location->metadata['opening_hours']);
        $this->assertTrue($location->metadata['wheelchair']);
    }

    public function test_nullable_fields_default_to_null(): void
    {
        $location = Location::factory()->create([
            'name' => null,
            'description' => null,
            'address' => null,
            'postal_code' => null,
            'place_id' => null,
            'source' => null,
            'metadata' => null,
        ]);

        $fresh = $location->fresh();
        $this->assertNull($fresh->name);
        $this->assertNull($fresh->description);
        $this->assertNull($fresh->place_id);
        $this->assertNull($fresh->metadata);
    }

    // ── Relationships ──────────────────────────────────

    public function test_location_has_many_games(): void
    {
        $location = Location::factory()->create();
        $game = Game::factory()->create(['location_id' => $location->id]);

        $this->assertTrue($location->games->contains($game));
        $this->assertInstanceOf(Location::class, $game->linkedLocation);
        $this->assertEquals($location->id, $game->linkedLocation->id);
    }

    public function test_location_has_many_events(): void
    {
        $location = Location::factory()->create();
        $event = Event::factory()->create(['location_id' => $location->id]);

        $this->assertTrue($location->events->contains($event));
        $this->assertInstanceOf(Location::class, $event->linkedLocation);
        $this->assertEquals($location->id, $event->linkedLocation->id);
    }

    public function test_location_has_many_users(): void
    {
        $location = Location::factory()->create();
        $user = User::factory()->create(['location_id' => $location->id]);

        $this->assertTrue($location->users->contains($user));
        $this->assertInstanceOf(Location::class, $user->linkedLocation);
        $this->assertEquals($location->id, $user->linkedLocation->id);
    }

    public function test_game_location_is_nullable(): void
    {
        $game = Game::factory()->create(['location_id' => null]);

        $this->assertNull($game->linkedLocation);
    }

    public function test_event_location_is_nullable(): void
    {
        $event = Event::factory()->create(['location_id' => null]);

        $this->assertNull($event->linkedLocation);
    }

    public function test_user_location_is_nullable(): void
    {
        $user = User::factory()->create(['location_id' => null]);

        $this->assertNull($user->linkedLocation);
    }

    public function test_location_deleted_nullifies_game_fk(): void
    {
        $location = Location::factory()->create();
        $game = Game::factory()->create(['location_id' => $location->id]);

        $location->delete();

        $this->assertNull($game->fresh()->location_id);
    }

    public function test_location_deleted_nullifies_event_fk(): void
    {
        $location = Location::factory()->create();
        $event = Event::factory()->create(['location_id' => $location->id]);

        $location->delete();

        $this->assertNull($event->fresh()->location_id);
    }

    public function test_location_deleted_nullifies_user_fk(): void
    {
        $location = Location::factory()->create();
        $user = User::factory()->create(['location_id' => $location->id]);

        $location->delete();

        $this->assertNull($user->fresh()->location_id);
    }

    // ── Deduplication ──────────────────────────────────

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

    // ── Bounding Box Scope ─────────────────────────────

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

        // ~10km bounding box around Berlin (52.52, 13.40)
        $results = Location::withinBounds(52.43, 52.61, 13.26, 13.54)->get();

        $this->assertTrue($results->contains($inside));
        $this->assertFalse($results->contains($outside));
    }

    // ── Haversine Distance ─────────────────────────────

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

    // ── Full Address ───────────────────────────────────

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
}
