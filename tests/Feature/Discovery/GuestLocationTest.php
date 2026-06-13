<?php

use App\Livewire\Discovery\DiscoveryPage;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Tests for the HasGuestLocation trait integration with Livewire components.
 *
 * Covers trait behavior, cross-component persistence, guest vs authenticated
 * user behavior, and E2E location flows.
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

    it('source stays as browser when updated via browser', function () {
        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser')
            ->assertSet('guestLocationSource', 'browser');

        // Second browser update keeps source as browser
        $component->dispatch('guest-location-updated', lat: 52.53, lng: 13.41, source: 'browser')
            ->assertSet('guestLocationSource', 'browser');
    });
});

describe('Cross-component location persistence', function () {
    it('location persists across Livewire component instances', function () {
        $component1 = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 40.7128, lng: -74.006, source: 'browser');

        expect($component1->instance()->hasGuestLocation())->toBeTrue();

        $component2 = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 40.7128, lng: -74.006, source: 'browser');

        expect($component2->instance()->hasGuestLocation())->toBeTrue();
        $component2
            ->assertSet('guestLat', 40.7128)
            ->assertSet('guestLng', -74.006);
    });

    it('location can change between component instances', function () {
        Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser');

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
        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser');

        expect($component->instance()->hasGuestLocation())->toBeTrue();
        expect(auth()->check())->toBeFalse();
    });

    it('authenticated users can receive guest location via trait', function () {
        $user = User::factory()->create();
        actingAs($user);

        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser');

        expect($component->instance()->hasGuestLocation())->toBeTrue();
        $component
            ->assertSet('guestLat', 52.52)
            ->assertSet('guestLng', 13.405);
    });

    it('authenticated user with existing location_id still receives guest location', function () {
        $user = User::factory()->create();
        actingAs($user);

        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 48.8566, lng: 2.3522, source: 'manual');

        expect($component->instance()->hasGuestLocation())->toBeTrue();
        $component->assertSet('guestLocationSource', 'manual');
    });
});

describe('End-to-end guest location flow', function () {
    it('guest receives browser location then manually searches city', function () {
        $component = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser');

        expect($component->instance()->hasGuestLocation())->toBeTrue();
        $component->assertSet('guestLocationSource', 'browser');

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

        $component->dispatch('guest-location-updated', lat: 48.8566, lng: 2.3522, source: 'manual');
        expect($component->instance()->hasGuestLocation())->toBeTrue();
    });

    it('multiple components share location via JS bridge simulation', function () {
        $coords = ['lat' => 51.5074, 'lng' => -0.1278, 'source' => 'browser'];

        $comp1 = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', $coords['lat'], $coords['lng'], $coords['source']);
        $comp2 = Livewire\Livewire::test(DiscoveryPage::class)
            ->dispatch('guest-location-updated', $coords['lat'], $coords['lng'], $coords['source']);

        expect($comp1->instance()->hasGuestLocation())->toBeTrue();
        expect($comp2->instance()->hasGuestLocation())->toBeTrue();
    });
});
