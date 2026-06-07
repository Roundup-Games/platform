<?php

namespace Tests\Feature\Models;

use App\Enums\VenueType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LocationVenueTest extends TestCase
{
    use DatabaseTransactions;

    // ── scopeVerified ──────────────────────────────────

    public function test_scope_verified_returns_only_verified_locations(): void
    {
        $verified = Location::factory()->verifiedVenue()->create(['place_id' => 'verified_1']);
        $unverified = Location::factory()->create(['is_verified' => false, 'place_id' => 'unverified_1']);

        $results = Location::verified()->get();

        $this->assertTrue($results->contains($verified));
        $this->assertFalse($results->contains($unverified));
    }

    // ── scopeByVenueType ───────────────────────────────

    public function test_scope_by_venue_type_filters_correctly(): void
    {
        $cafe = Location::factory()->verifiedVenue()->create([
            'venue_type' => VenueType::Cafe,
            'place_id' => 'cafe_1',
        ]);
        $flgs = Location::factory()->verifiedVenue()->create([
            'venue_type' => VenueType::Flgs,
            'place_id' => 'flgs_1',
        ]);

        $results = Location::byVenueType(VenueType::Cafe->value)->get();

        $this->assertTrue($results->contains($cafe));
        $this->assertFalse($results->contains($flgs));
    }

    // ── isVenue() ──────────────────────────────────────

    public function test_is_venue_returns_true_for_verified(): void
    {
        $location = Location::factory()->verifiedVenue()->create(['place_id' => 'venue_yes']);

        $this->assertTrue($location->isVenue());
    }

    public function test_is_venue_returns_false_for_non_verified(): void
    {
        $location = Location::factory()->create(['is_verified' => false, 'place_id' => 'venue_no']);

        $this->assertFalse($location->isVenue());
    }

    // ── managedBy relationship ─────────────────────────

    public function test_managed_by_relationship_works(): void
    {
        $manager = User::factory()->create();
        $location = Location::factory()->create([
            'managed_by' => $manager->id,
            'place_id' => 'managed_1',
        ]);

        $this->assertInstanceOf(User::class, $location->managedBy);
        $this->assertEquals($manager->id, $location->managedBy->id);
    }

    public function test_managed_by_returns_null_when_not_set(): void
    {
        $location = Location::factory()->create([
            'managed_by' => null,
            'place_id' => 'unmanaged_1',
        ]);

        $this->assertNull($location->managedBy);
    }

    // ── Factory verifiedVenue state ────────────────────

    public function test_factory_verified_venue_state_produces_valid_venue(): void
    {
        $venue = Location::factory()->verifiedVenue()->create(['place_id' => 'factory_venue']);

        $this->assertTrue($venue->is_verified);
        $this->assertInstanceOf(VenueType::class, $venue->venue_type);
        $this->assertContains($venue->venue_type, VenueType::cases());
    }

    // ── Regression: geohash_4 still auto-computes ──────

    public function test_geohash_4_still_auto_computes_with_venue_columns(): void
    {
        $venue = Location::factory()->verifiedVenue()->create([
            'place_id' => 'geohash_regression',
            'latitude' => 52.52,
            'longitude' => 13.40,
        ]);

        $fresh = $venue->fresh();

        $this->assertNotNull($fresh->geohash_4);
        $this->assertEquals(4, strlen($fresh->geohash_4));
    }
}
