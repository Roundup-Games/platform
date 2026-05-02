<?php

namespace Tests\Feature\People;

use App\Models\Location;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\Geohash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class NearbyPeopleTest extends TestCase
{
    use RefreshDatabase;

    // Berlin coordinates (Mitte area)
    private const LAT = 52.5163;
    private const LNG = 13.3777;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
        Cache::flush();
    }

    // ── Helpers ──────────────────────────────────────

    private function createUserWithLocation(float $lat, float $lng, array $overrides = []): User
    {
        $location = Location::factory()->create([
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        return User::factory()->create(array_merge([
            'location_id' => $location->id,
            'profile_complete' => true,
            'is_disabled' => false,
        ], $overrides));
    }

    private function createUserWithLinkedLocation(float $lat, float $lng, array $overrides = []): User
    {
        return $this->createUserWithLocation($lat, $lng, $overrides);
    }

    // ── Nearby tab rendering ────────────────────────

    public function test_nearby_tab_is_accessible(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'nearby')
            ->assertSet('activeTab', 'nearby');
    }

    public function test_nearby_tab_shows_no_location_state_when_user_has_no_location(): void
    {
        // User with no location_id and no guest location
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'nearby')
            ->assertSet('nearbyUsers.noLocation', true);
    }

    public function test_nearby_tab_shows_results_when_user_has_linked_location(): void
    {
        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);
        $this->user->update(['location_id' => $location->id]);

        // Create a nearby candidate
        $nearby = $this->createUserWithLinkedLocation(self::LAT + 0.001, self::LNG, ['name' => 'Nearby Player']);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'nearby');

        // Access the computed property and verify
        $component = Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class);
        $component->set('activeTab', 'nearby');

        $nearbyUsers = $component->get('nearbyUsers');
        $this->assertFalse($nearbyUsers['noLocation']);
        $this->assertEquals('ok', $nearbyUsers['status']);
        $this->assertEquals(1, $nearbyUsers['results']->total());
    }

    public function test_nearby_tab_uses_linked_location_over_guest_location(): void
    {
        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);
        $this->user->update(['location_id' => $location->id]);

        // Create candidate near the linked location
        $nearbyLinked = $this->createUserWithLinkedLocation(self::LAT + 0.001, self::LNG, ['name' => 'Near Linked']);

        // Create candidate near a different (guest) location — Munich
        $nearbyGuest = $this->createUserWithLinkedLocation(48.1351 + 0.001, 11.5820, ['name' => 'Near Guest']);

        $component = Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class);

        // Set guest location to Munich
        $component->call('onGuestLocationUpdated', 48.1351, 11.5820, 'test');
        $component->set('activeTab', 'nearby');

        $nearbyUsers = $component->get('nearbyUsers');
        $ids = collect($nearbyUsers['results']->items())->pluck('user_id');

        // Should find the user near linked location (Berlin), not near guest location (Munich)
        $this->assertTrue($ids->contains($nearbyLinked->id), 'Should find candidate near linked location');
        $this->assertFalse($ids->contains($nearbyGuest->id), 'Should not find candidate near guest location');
    }

    public function test_nearby_tab_falls_back_to_guest_location(): void
    {
        // User without a linked location
        $this->assertNull($this->user->location_id);

        // Create a candidate near the guest location
        $nearby = $this->createUserWithLinkedLocation(48.1351 + 0.001, 11.5820, ['name' => 'Guest Nearby']);

        $component = Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class);

        // Set guest location to Munich
        $component->call('onGuestLocationUpdated', 48.1351, 11.5820, 'test');
        $component->set('activeTab', 'nearby');

        $nearbyUsers = $component->get('nearbyUsers');
        $this->assertFalse($nearbyUsers['noLocation']);
        $this->assertEquals('ok', $nearbyUsers['status']);
        $ids = collect($nearbyUsers['results']->items())->pluck('user_id');
        $this->assertTrue($ids->contains($nearby->id));
    }

    // ── followFromNearby action ──────────────────────

    public function test_follow_from_nearby_creates_follow_relationship(): void
    {
        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);
        $this->user->update(['location_id' => $location->id]);

        $nearby = $this->createUserWithLinkedLocation(self::LAT + 0.001, self::LNG, ['name' => 'Follow Target']);

        $this->assertFalse($this->user->isFollowing($nearby));

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'nearby')
            ->call('followFromNearby', $nearby->id)
            ->assertSee(__('common.flash_now_following', ['name' => 'Follow Target']));

        $this->assertTrue($this->user->fresh()->isFollowing($nearby));
    }

    public function test_follow_from_nearby_removes_user_from_results(): void
    {
        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);
        $this->user->update(['location_id' => $location->id]);

        $nearby = $this->createUserWithLinkedLocation(self::LAT + 0.001, self::LNG, ['name' => 'Will Disappear']);

        $component = Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class);
        $component->set('activeTab', 'nearby');

        // Verify candidate appears initially
        $beforeFollow = $component->get('nearbyUsers');
        $this->assertEquals(1, $beforeFollow['results']->total());

        // Follow the candidate
        $component->call('followFromNearby', $nearby->id);

        // After follow, the nearby results should be refreshed (followed users excluded)
        $afterFollow = $component->get('nearbyUsers');
        $this->assertEquals(0, $afterFollow['results']->total());
    }

    public function test_follow_from_nearby_self_is_noop(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'nearby')
            ->call('followFromNearby', $this->user->id);

        $this->assertEquals(0, UserRelationship::count());
    }

    public function test_follow_from_nearby_nonexistent_user_is_noop(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'nearby')
            ->call('followFromNearby', \Illuminate\Support\Str::uuid()->toString());

        $this->assertEquals(0, UserRelationship::count());
    }

    // ── nearbyCount ──────────────────────────────────

    public function test_nearby_count_reflects_total_candidates(): void
    {
        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);
        $this->user->update(['location_id' => $location->id]);

        // Create 3 nearby candidates
        for ($i = 1; $i <= 3; $i++) {
            $this->createUserWithLinkedLocation(self::LAT + (0.001 * $i), self::LNG);
        }

        $component = Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class);
        $component->set('activeTab', 'nearby');

        $component->assertSet('nearbyCount', 3);
    }

    public function test_nearby_count_is_zero_when_no_location(): void
    {
        $this->assertNull($this->user->location_id);

        $component = Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class);
        $component->set('activeTab', 'nearby');

        $component->assertSet('nearbyCount', 0);
    }
}
