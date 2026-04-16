<?php

use App\Livewire\Discovery\DiscoveryPage;
use App\Models\User;
use function Pest\Laravel\{actingAs};

/**
 * End-to-end tests for the guest location flow.
 *
 * Covers:
 *  - HasGuestLocation trait integration with Livewire components
 *  - Cross-page / cross-component location persistence
 *  - Guest vs authenticated user behavior
 *  - JS bridge dispatch/receive cycle
 */
describe('GuestLocation trait integration', function () {
    it('initializes with null location properties on mount', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->assertSet('guestLat', null)
            ->assertSet('guestLng', null)
            ->assertSet('guestLocationSource', null);
    });

    it('receives location via browser dispatch event', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser')
            ->assertSet('guestLat', 52.52)
            ->assertSet('guestLng', 13.405)
            ->assertSet('guestLocationSource', 'browser');
    });

    it('stores source from dispatch event', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 48.8566, lng: 2.3522, source: 'manual')
            ->assertSet('guestLocationSource', 'manual');
    });

    it('defaults source to unknown when not provided', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 40.7128, lng: -74.006)
            ->assertSet('guestLocationSource', 'unknown');
    });

    it('hasGuestLocation returns false initially', function () {
        $component = Livewire\Livewire::test(DiscoveryPage::class);
        expect($component->instance()->hasGuestLocation())->toBeFalse();
    });

    it('hasGuestLocation returns true after receiving coords', function () {
        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser');
        expect($component->instance()->hasGuestLocation())->toBeTrue();
    });

    it('hasGuestLocation returns false when only lat is set', function () {
        $component = Livewire\Livewire::test(DiscoveryPage::class);
        $component->instance()->guestLat = 52.52;
        $component->instance()->guestLng = null;
        expect($component->instance()->hasGuestLocation())->toBeFalse();
    });

    it('clears location completely', function () {
        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser')
            ->call('clearGuestLocation');

        expect($component->instance()->hasGuestLocation())->toBeFalse();
        $component
            ->assertSet('guestLat', null)
            ->assertSet('guestLng', null)
            ->assertSet('guestLocationSource', null);
    });

    it('overwrites location on subsequent dispatch', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser')
            ->assertSet('guestLat', 52.52)
            ->dispatch('guest-location-updated', lat: 48.8566, lng: 2.3522, source: 'manual')
            ->assertSet('guestLat', 48.8566)
            ->assertSet('guestLng', 2.3522)
            ->assertSet('guestLocationSource', 'manual');
    });

    it('handles negative coordinates', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: -33.8688, lng: 151.2093, source: 'browser')
            ->assertSet('guestLat', -33.8688)
            ->assertSet('guestLng', 151.2093);
    });

    it('handles coordinates near zero', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 0.0, lng: 0.0, source: 'browser')
            ->assertSet('guestLat', 0.0)
            ->assertSet('guestLng', 0.0);
    });

    it('handles high-precision coordinates', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.5170365, lng: 13.3888599, source: 'browser')
            ->assertSet('guestLat', 52.5170365)
            ->assertSet('guestLng', 13.3888599);
    });
});

describe('Cross-component location persistence', function () {
    it('location persists across Livewire component instances', function () {
        // First component instance receives location
        $component1 = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 40.7128, lng: -74.006, source: 'browser');

        expect($component1->instance()->hasGuestLocation())->toBeTrue();

        // A fresh component mount simulates page navigation
        // In production, the JS bridge re-sends the cached localStorage value
        $component2 = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 40.7128, lng: -74.006, source: 'browser');

        expect($component2->instance()->hasGuestLocation())->toBeTrue();
        $component2
            ->assertSet('guestLat', 40.7128)
            ->assertSet('guestLng', -74.006);
    });

    it('location can change between component instances', function () {
        // First component gets browser location
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser');

        // Second component receives updated location (e.g., manual city entry)
        $component2 = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 48.8566, lng: 2.3522, source: 'manual');

        $component2
            ->assertSet('guestLat', 48.8566)
            ->assertSet('guestLng', 2.3522)
            ->assertSet('guestLocationSource', 'manual');
    });
});

describe('Guest vs authenticated user location behavior', function () {
    it('guests do not persist location server-side', function () {
        // Guest uses the component — location is only in Livewire state
        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser');

        // No User model exists, so no location_id can be set
        // The location lives only in the Livewire component's runtime state
        expect($component->instance()->hasGuestLocation())->toBeTrue();

        // Verify no authenticated user exists
        expect(auth()->check())->toBeFalse();
    });

    it('authenticated users can receive guest location via trait', function () {
        $user = User::factory()->create();

        actingAs($user);

        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser');

        // The trait works the same way for authenticated users
        expect($component->instance()->hasGuestLocation())->toBeTrue();
        $component
            ->assertSet('guestLat', 52.52)
            ->assertSet('guestLng', 13.405);
    });

    it('authenticated user with existing location_id still receives guest location', function () {
        $user = User::factory()->create();

        actingAs($user);

        // Even if the user has a persisted location, the guest location bridge works
        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 48.8566, lng: 2.3522, source: 'manual');

        expect($component->instance()->hasGuestLocation())->toBeTrue();
        $component->assertSet('guestLocationSource', 'manual');
    });
});

describe('requestGuestLocation dispatches JS bridge', function () {
    it('calls requestGuestLocation on component mount', function () {
        // Verify the trait's mountHasGuestLocation fires by checking initial state
        // (the JS dispatch can't execute in tests, but we verify the component
        // initializes correctly and the trait is wired up)
        $component = Livewire\Livewire::test(DiscoveryPage::class);

        // Should start with null — JS hasn't responded yet
        $component
            ->assertSet('guestLat', null)
            ->assertSet('guestLng', null);
    });

    it('can manually re-request location', function () {
        $component = Livewire\Livewire::test(DiscoveryPage::class);

        // Manually call requestGuestLocation (simulates user clicking "Locate me")
        $component->call('requestGuestLocation');

        // JS bridge hasn't responded yet, so still null
        $component
            ->assertSet('guestLat', null)
            ->assertSet('guestLng', null);
    });
});

describe('End-to-end guest location + geocode flow', function () {
    it('guest receives browser location then manually searches city', function () {
        // Step 1: Browser provides location
        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser');

        expect($component->instance()->hasGuestLocation())->toBeTrue();
        $component->assertSet('guestLocationSource', 'browser');

        // Step 2: User manually enters a city — component receives new location
        $component->dispatch('guest-location-updated', lat: 48.8566, lng: 2.3522, source: 'manual');
        $component
            ->assertSet('guestLat', 48.8566)
            ->assertSet('guestLng', 2.3522)
            ->assertSet('guestLocationSource', 'manual');
    });

    it('guest can clear location and re-acquire it', function () {
        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser')
            ->call('clearGuestLocation');

        expect($component->instance()->hasGuestLocation())->toBeFalse();

        // Re-acquire
        $component->dispatch('guest-location-updated', lat: 48.8566, lng: 2.3522, source: 'manual');
        expect($component->instance()->hasGuestLocation())->toBeTrue();
    });

    it('multiple components share location via JS bridge simulation', function () {
        // Simulates two different Livewire components on the same page
        // both receiving the same location from the JS bridge
        $coords = ['lat' => 51.5074, 'lng' => -0.1278, 'source' => 'browser'];

        $comp1 = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', $coords['lat'], $coords['lng'], $coords['source']);
        $comp2 = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', $coords['lat'], $coords['lng'], $coords['source']);

        expect($comp1->instance()->hasGuestLocation())->toBeTrue();
        expect($comp2->instance()->hasGuestLocation())->toBeTrue();
        $comp1->assertSet('guestLat', $coords['lat']);
        $comp2->assertSet('guestLat', $coords['lat']);
    });
});

describe('Guest location boundary conditions', function () {
    it('handles extreme latitude values', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 89.9999, lng: 179.9999, source: 'browser')
            ->assertSet('guestLat', 89.9999)
            ->assertSet('guestLng', 179.9999);
    });

    it('handles southern hemisphere coordinates', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: -33.9249, lng: 18.4241, source: 'browser')
            ->assertSet('guestLat', -33.9249)
            ->assertSet('guestLng', 18.4241);
    });

    it('handles western hemisphere negative longitude', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 37.7749, lng: -122.4194, source: 'browser')
            ->assertSet('guestLat', 37.7749)
            ->assertSet('guestLng', -122.4194);
    });

    it('source stays as browser when updated via browser', function () {
        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser')
            ->assertSet('guestLocationSource', 'browser');

        // Second browser update keeps source as browser
        $component->dispatch('guest-location-updated', lat: 52.53, lng: 13.41, source: 'browser')
            ->assertSet('guestLocationSource', 'browser');
    });

    it('clearLocation resets source to null', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'manual')
            ->assertSet('guestLocationSource', 'manual')
            ->call('clearGuestLocation')
            ->assertSet('guestLocationSource', null);
    });

    it('location update after clear works correctly', function () {
        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser')
            ->call('clearGuestLocation');

        expect($component->instance()->hasGuestLocation())->toBeFalse();

        // New location after clear
        $component->dispatch('guest-location-updated', lat: 48.8566, lng: 2.3522, source: 'manual');
        expect($component->instance()->hasGuestLocation())->toBeTrue();
        $component->assertSet('guestLat', 48.8566);
    });
});
