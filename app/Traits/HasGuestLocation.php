<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
 *
 * Defence-in-depth (MEDIUM-4, M053/S1/T07): every accepted guest-coordinate
 * update passes through a per-session/IP rate limiter (see
 * tooManyGuestLocationUpdates()). Combined with the 5km distance grid-snap
 * (T03), this caps brute-force trilateration yield at ≥5km of uncertainty
 * regardless of how many spoofed vantage points an attacker slow-rolls under
 * the limit. The limiter applies to the discovery/proximity surfaces that
 * surface OTHER people's private locations by distance; self-location pickers
 * override onGuestLocationUpdated for their own UX and are not attack surface.
 */
trait HasGuestLocation
{
    /** Per-session/IP guest coordinate update cap (updates). */
    protected const GUEST_LOCATION_RATE_LIMIT_MAX = 10;

    /** Per-session/IP guest coordinate update window (seconds). */
    protected const GUEST_LOCATION_RATE_LIMIT_DECAY = 60;

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
     * with {lat, lng, source} detail. Throttled per session/IP as MEDIUM-4
     * defence-in-depth against coordinate brute-forcing; when throttled the
     * last accepted coordinates are silently retained (no visible error, so an
     * attacker gets no feedback) and the hit is logged at info level.
     */
    #[On('guest-location-updated')]
    public function onGuestLocationUpdated(float $lat, float $lng, string $source = 'unknown'): void
    {
        if ($this->tooManyGuestLocationUpdates($source)) {
            return;
        }

        $this->guestLat = $lat;
        $this->guestLng = $lng;
        $this->guestLocationSource = $source;
    }

    /**
     * Per-session/IP rate-limit key for guest coordinate updates.
     *
     * Scoped to the session ID AND client IP so the limit follows an attacker
     * across component instances within one session while not colliding with
     * unrelated users sharing a key.
     */
    protected function guestLocationRateLimitKey(): string
    {
        $ip = request()->ip() ?? 'cli';

        return 'guest-location-update:'.session()->getId().':'.$ip;
    }

    /**
     * Enforce the per-session/IP guest-coordinate rate limit (MEDIUM-4).
     *
     * Returns true when the caller must ABORT the update — silently retain the
     * last accepted coordinates. On a hit, logs at info level with a sha256 IP
     * hash (never the raw IP) so brute-force triangulation attempts are
     * observable in monitoring without storing PII. The 5km grid-snap (T03) is
     * the primary defence; this limiter adds request-level braking so an
     * attacker cannot speed brute-force trilateration beyond the cap.
     */
    protected function tooManyGuestLocationUpdates(string $source): bool
    {
        $key = $this->guestLocationRateLimitKey();

        if (RateLimiter::tooManyAttempts($key, self::GUEST_LOCATION_RATE_LIMIT_MAX)) {
            Log::info('guest_location.rate_limited', [
                'ip_hash' => hash('sha256', request()->ip() ?? 'cli'),
                'source' => $source,
            ]);

            return true;
        }

        RateLimiter::hit($key, self::GUEST_LOCATION_RATE_LIMIT_DECAY);

        return false;
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
