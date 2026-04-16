<?php

namespace App\Traits;

use Livewire\Attributes\On;

/**
 * Trait for Livewire components that need guest/user location.
 *
 * Flow:
 *   1. Component mounts → dispatches 'request-guest-location' browser event.
 *   2. JS (guest-location.js) receives event, reads localStorage, dispatches
 *      'guest-location-updated' CustomEvent with {lat, lng}.
 *   3. #[On('guest-location-updated')] handler receives coordinates in PHP.
 *
 * Usage:
 *   class MyComponent extends Component {
 *       use HasGuestLocation;
 *   }
 *
 * Access lat/lng via $this->guestLat / $this->guestLng after the JS bridge responds.
 */
trait HasGuestLocation
{
    public ?float $guestLat = null;

    public ?float $guestLng = null;

    public ?string $guestLocationSource = null;

    /**
     * Request location from the browser on component mount.
     *
     * Dispatches a browser event that the GuestLocation JS module listens for.
     * If the user already has a cached location in localStorage, the JS side
     * will immediately dispatch it back.
     */
    public function mountHasGuestLocation(): void
    {
        $this->requestGuestLocation();
    }

    /**
     * Receive guest location from the JS bridge.
     *
     * Triggered when the browser dispatches 'guest-location-updated' CustomEvent
     * with {lat, lng, source} detail.
     */
    #[On('guest-location-updated')]
    public function onGuestLocationUpdated(float $lat, float $lng, string $source = 'unknown'): void
    {
        $this->guestLat = $lat;
        $this->guestLng = $lng;
        $this->guestLocationSource = $source;
    }

    /**
     * Whether the component currently has a valid guest location.
     */
    public function hasGuestLocation(): bool
    {
        return $this->guestLat !== null && $this->guestLng !== null;
    }

    /**
     * Ask the browser to send cached location (if any) or prompt for geolocation.
     *
     * Can be called manually to re-request location (e.g., after user clicks "Locate me").
     */
    public function requestGuestLocation(): void
    {
        $this->js(<<<'JS'
            if (window.GuestLocation) {
                const loc = window.GuestLocation.getGuestLocation();
                if (loc) {
                    loc.then(result => {
                        if (result) {
                            window.dispatchEvent(new CustomEvent('guest-location-updated', {
                                detail: { lat: result.lat, lng: result.lng, source: result.source }
                            }));
                        }
                    });
                }
            }
        JS);
    }

    /**
     * Clear the current guest location.
     */
    public function clearGuestLocation(): void
    {
        $this->guestLat = null;
        $this->guestLng = null;
        $this->guestLocationSource = null;
    }
}
