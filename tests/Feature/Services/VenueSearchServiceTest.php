<?php

namespace Tests\Feature\Services;

use App\Enums\VenueType;
use App\Models\Location;
use App\Services\VenueSearchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('venue')]
class VenueSearchServiceTest extends TestCase
{
    use DatabaseTransactions;

    private VenueSearchService $service;

    // Berlin Alexanderplatz
    private float $centerLat = 52.5219;

    private float $centerLng = 13.4117;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VenueSearchService;
    }

    #[Test]
    public function search_returns_only_verified_venues(): void
    {
        Location::factory()->create([
            'name' => 'Unverified Place',
            'city' => 'Berlin',
            'is_verified' => false,
            'latitude' => 52.52,
            'longitude' => 13.41,
        ]);

        $verified = Location::factory()->create([
            'name' => 'Verified Cafe',
            'city' => 'Berlin',
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
            'latitude' => 52.52,
            'longitude' => 13.41,
        ]);

        $results = $this->service->search(lat: $this->centerLat, lng: $this->centerLng);

        $this->assertCount(1, $results);
        $this->assertEquals($verified->id, $results->first()->id);
    }

    #[Test]
    public function search_orders_by_proximity(): void
    {
        $far = Location::factory()->create([
            'name' => 'Far Venue',
            'city' => 'Berlin',
            'is_verified' => true,
            'latitude' => 52.55,
            'longitude' => 13.45,
        ]);

        $near = Location::factory()->create([
            'name' => 'Near Venue',
            'city' => 'Berlin',
            'is_verified' => true,
            'latitude' => 52.523,
            'longitude' => 13.413,
        ]);

        $results = $this->service->search(lat: $this->centerLat, lng: $this->centerLng);

        $this->assertCount(2, $results);
        $this->assertEquals($near->id, $results->first()->id);
        $this->assertEquals($far->id, $results->last()->id);
        $this->assertLessThan($results->last()->distanceKm, $results->first()->distanceKm);
    }

    #[Test]
    public function search_filters_by_text_query(): void
    {
        Location::factory()->create([
            'name' => 'Board Game Cafe',
            'city' => 'Berlin',
            'is_verified' => true,
            'latitude' => 52.52,
            'longitude' => 13.41,
        ]);

        Location::factory()->create([
            'name' => 'Dice Emporium',
            'city' => 'Berlin',
            'is_verified' => true,
            'latitude' => 52.52,
            'longitude' => 13.41,
        ]);

        $results = $this->service->search(
            lat: $this->centerLat,
            lng: $this->centerLng,
            query: 'Board',
        );

        $this->assertCount(1, $results);
        $this->assertEquals('Board Game Cafe', $results->first()->name);
    }

    #[Test]
    public function search_filters_by_city_in_query(): void
    {
        Location::factory()->create([
            'name' => 'Game Store',
            'city' => 'Berlin',
            'is_verified' => true,
            'latitude' => 52.52,
            'longitude' => 13.41,
        ]);

        Location::factory()->create([
            'name' => 'Game Store',
            'city' => 'Munich',
            'is_verified' => true,
            'latitude' => 48.14,
            'longitude' => 11.58,
        ]);

        $results = $this->service->search(
            lat: $this->centerLat,
            lng: $this->centerLng,
            query: 'Munich',
            radiusKm: 600,
        );

        $this->assertCount(1, $results);
        $this->assertEquals('Munich', $results->first()->city);
    }

    #[Test]
    public function search_without_coordinates_falls_back_to_alphabetical(): void
    {
        Location::factory()->create([
            'name' => 'Zebra Venue',
            'city' => 'Berlin',
            'is_verified' => true,
            'latitude' => 52.52,
            'longitude' => 13.41,
        ]);

        Location::factory()->create([
            'name' => 'Alpha Venue',
            'city' => 'Berlin',
            'is_verified' => true,
            'latitude' => 52.52,
            'longitude' => 13.41,
        ]);

        $results = $this->service->search(lat: null, lng: null);

        $this->assertCount(2, $results);
        $this->assertEquals('Alpha Venue', $results->first()->name);
        $this->assertNull($results->first()->distanceKm);
    }

    #[Test]
    public function search_filters_by_venue_type(): void
    {
        Location::factory()->create([
            'name' => 'Board Cafe',
            'city' => 'Berlin',
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
            'latitude' => 52.52,
            'longitude' => 13.41,
        ]);

        Location::factory()->create([
            'name' => 'Game Store',
            'city' => 'Berlin',
            'is_verified' => true,
            'venue_type' => VenueType::Flgs,
            'latitude' => 52.52,
            'longitude' => 13.41,
        ]);

        $results = $this->service->search(
            lat: $this->centerLat,
            lng: $this->centerLng,
            venueType: 'flgs',
        );

        $this->assertCount(1, $results);
        $this->assertEquals('Game Store', $results->first()->name);
    }

    #[Test]
    public function search_respects_radius_limit(): void
    {
        // Create a venue 100km+ away (Hamburg area)
        Location::factory()->create([
            'name' => 'Hamburg Venue',
            'city' => 'Hamburg',
            'is_verified' => true,
            'latitude' => 53.55,
            'longitude' => 9.99,
        ]);

        Location::factory()->create([
            'name' => 'Berlin Venue',
            'city' => 'Berlin',
            'is_verified' => true,
            'latitude' => 52.523,
            'longitude' => 13.413,
        ]);

        $results = $this->service->search(
            lat: $this->centerLat,
            lng: $this->centerLng,
            radiusKm: 10,
        );

        $this->assertCount(1, $results);
        $this->assertEquals('Berlin Venue', $results->first()->name);
    }

    #[Test]
    public function search_respects_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Location::factory()->create([
                'name' => "Venue {$i}",
                'city' => 'Berlin',
                'is_verified' => true,
                'latitude' => 52.52 + ($i * 0.001),
                'longitude' => 13.41 + ($i * 0.001),
            ]);
        }

        $results = $this->service->search(
            lat: $this->centerLat,
            lng: $this->centerLng,
            limit: 3,
        );

        $this->assertCount(3, $results);
    }

    #[Test]
    public function find_venue_returns_verified_location(): void
    {
        $venue = Location::factory()->create([
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
        ]);

        $found = $this->service->findVenue($venue->id);

        $this->assertNotNull($found);
        $this->assertEquals($venue->id, $found->id);
    }

    #[Test]
    public function find_venue_returns_null_for_unverified(): void
    {
        $location = Location::factory()->create(['is_verified' => false]);

        $found = $this->service->findVenue($location->id);

        $this->assertNull($found);
    }

    #[Test]
    public function find_venue_returns_null_for_nonexistent(): void
    {
        $found = $this->service->findVenue('00000000-0000-0000-0000-000000000000');

        $this->assertNull($found);
    }
}
