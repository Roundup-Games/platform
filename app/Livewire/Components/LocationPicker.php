<?php

namespace App\Livewire\Components;

use App\Models\Location;
use App\Services\GeocodingService;
use App\Traits\HasGuestLocation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Reusable location picker component.
 *
 * Provides city/address fields, "Find My Location" browser geolocation,
 * confirmation UX ("We think you're in {city}"), and geocoding resolution.
 *
 * Events emitted:
 * - `location-selected`: { locationId: int, city: string, address: string|null }
 * - `location-removed`: (no payload)
 *
 * Usage:
 *   <livewire:location-picker :location-id="$user->location_id" />
 *   <livewire:location-picker :location-id="null" wire:location-selected="onLocationPicked" />
 */
class LocationPicker extends Component
{
    use HasGuestLocation;

    // Validation rules for city field
    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'city' => ['required', 'string', 'max:255'],
        ];
    }

    // Input: existing location to pre-fill from
    #[Locked]
    public ?string $locationId = null;

    /** Mode: 'profile' (city-focused, casual) or 'session' (precise address for findability) */
    #[Locked]
    public string $mode = 'profile';

    // Optional hint text shown above the location picker (overrides default)
    public ?string $hint = null;

    // Internal state
    public string $city = '';

    public string $address = '';

    public ?float $lat = null;

    public ?float $lng = null;

    public string $locationSource = 'manual'; // 'manual' or 'localStorage'

    public bool $locationConfirmed = false;

    public bool $editing = false;

    public function mount(?string $locationId = null, string $mode = 'profile'): void
    {
        $this->locationId = $locationId;
        $this->mode = in_array($mode, ['profile', 'session']) ? $mode : 'profile';

        if ($locationId) {
            $location = Location::find($locationId);
            if ($location) {
                $this->city = $location->city ?? '';
                $this->address = $location->address ?? '';
                $this->lat = (float) $location->latitude;
                $this->lng = (float) $location->longitude;
                $this->locationConfirmed = true;
            }
        }
    }

    /**
     * Handle the guest location arriving from localStorage via HasGuestLocation.
     */
    public function onGuestLocationUpdated(float $lat, float $lng, string $source = 'unknown'): void
    {
        $this->guestLat = $lat;
        $this->guestLng = $lng;

        if ($this->city === '' && ! $this->locationConfirmed) {
            $this->lat = $lat;
            $this->lng = $lng;
            $this->locationSource = 'localStorage';

            try {
                $geocodingService = app(GeocodingService::class);
                $result = $geocodingService->reverseGeocode($lat, $lng);

                if ($result && isset($result['address']) && is_array($result['address'])) {
                    $addr = $result['address'];
                    $this->city = is_string($addr['city'] ?? null) ? $addr['city']
                        : (is_string($addr['town'] ?? null) ? $addr['town']
                        : (is_string($addr['village'] ?? null) ? $addr['village']
                        : (is_string($addr['municipality'] ?? null) ? $addr['municipality'] : '')));
                    $this->fillSessionAddressFromGeocode($addr);
                }
            } catch (\Throwable $e) {
                Log::warning('Reverse geocoding failed in LocationPicker', [
                    'lat' => $lat, 'lng' => $lng, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * User confirms the detected/entered location.
     */
    public function confirmLocation(): void
    {
        $this->validateOnly('city');

        if ($this->lat !== null && $this->lng !== null) {
            $this->locationConfirmed = true;
            $this->editing = false;
            $this->resolveAndEmit();

            return;
        }

        $this->geocodeAndConfirm();
    }

    /**
     * "Find My Location" — geocode typed text or trigger browser geolocation.
     */
    public function findMyLocation(): void
    {
        $query = trim($this->city.($this->address ? ', '.$this->address : ''));

        if ($query === '') {
            $this->js(<<<'JS'
                if (window.GuestLocation) {
                    window.GuestLocation.requestBrowserLocation().then(result => {
                        $wire.call('handleBrowserLocation', result.lat, result.lng);
                    }).catch(err => {
                        $wire.call('addGeolocationError');
                    });
                }
            JS);

            return;
        }

        $this->geocodeAndConfirm();
    }

    /**
     * Receive browser geolocation coordinates from JS bridge.
     * Reverse-geocodes to find the closest city and optionally fills the address.
     */
    public function handleBrowserLocation(float $lat, float $lng): void
    {
        $this->lat = $lat;
        $this->lng = $lng;
        $this->locationSource = 'localStorage';

        try {
            $geocodingService = app(GeocodingService::class);
            $result = $geocodingService->reverseGeocode($lat, $lng);

            if ($result && isset($result['address']) && is_array($result['address'])) {
                $addr = $result['address'];
                $this->city = is_string($addr['city'] ?? null) ? $addr['city']
                    : (is_string($addr['town'] ?? null) ? $addr['town']
                    : (is_string($addr['village'] ?? null) ? $addr['village']
                    : (is_string($addr['municipality'] ?? null) ? $addr['municipality'] : '')));
                $this->fillSessionAddressFromGeocode($addr);
            }
        } catch (\Throwable $e) {
            Log::warning('Reverse geocoding failed in LocationPicker findMyLocation', [
                'lat' => $lat, 'lng' => $lng, 'error' => $e->getMessage(),
            ]);
        }

        if ($this->city === '') {
            $this->addError('city', __('location.error_could_not_detect_city'));
        }
    }

    /**
     * Error when browser geolocation was denied or unavailable.
     */
    public function addGeolocationError(): void
    {
        $this->addError('city', __('location.error_location_permission_denied'));
    }

    /**
     * Start editing / changing the location.
     */
    public function startEditing(): void
    {
        $this->editing = true;
        $this->locationConfirmed = false;
    }

    /**
     * Cancel editing and restore previous confirmed state.
     */
    public function cancelEditing(): void
    {
        $this->editing = false;
        if ($this->locationId) {
            $this->locationConfirmed = true;
        }
    }

    /**
     * Remove the location entirely.
     */
    public function removeLocation(): void
    {
        $this->locationId = null;
        $this->editing = false;
        $this->locationConfirmed = false;
        $this->city = '';
        $this->address = '';
        $this->lat = null;
        $this->lng = null;

        $this->dispatch('location-removed');
    }

    // ── Internals ────────────────────────────────────

    private function geocodeAndConfirm(): void
    {
        $query = trim($this->city.($this->address ? ', '.$this->address : ''));

        try {
            $geocodingService = app(GeocodingService::class);
            $result = $geocodingService->geocode($query);

            if ($result) {
                $this->lat = $result['lat'];
                $this->lng = $result['lng'];
                $this->locationConfirmed = true;
                $this->editing = false;
                $this->locationSource = 'manual';
                $this->resolveAndEmit();
            } else {
                $this->addError('city', __('location.error_could_not_find_this_location_city'));
            }
        } catch (\Throwable $e) {
            Log::error('Geocoding failed in LocationPicker', [
                'city' => $this->city, 'error' => $e->getMessage(),
            ]);
            $this->addError('city', __('location.error_location_lookup_failed'));
        }
    }

    /**
     * Resolve or create a Location record, set locationId, and emit event.
     */
    private function resolveAndEmit(): void
    {
        if ($this->lat === null || $this->lng === null) {
            return;
        }

        $geocodingService = app(GeocodingService::class);
        $query = trim($this->city.($this->address ? ', '.$this->address : ''));
        $geocodeResult = $geocodingService->geocode($query);

        $placeId = null;
        $country = null;
        $postalCode = null;
        $resolvedCity = $this->city;
        $resolvedAddress = $this->address ?: null;

        if ($geocodeResult) {
            $placeId = $geocodeResult['place_id'];
            $raw = $geocodeResult['raw'];
            $addr = is_array($raw['address'] ?? null) ? $raw['address'] : [];
            $country = strtoupper(is_string($addr['country_code'] ?? null) ? $addr['country_code'] : '') ?: null;
            $postalCode = is_string($addr['postcode'] ?? null) ? $addr['postcode'] : null;
            $resolvedCity = is_string($addr['city'] ?? null) ? $addr['city']
                : (is_string($addr['town'] ?? null) ? $addr['town']
                : (is_string($addr['village'] ?? null) ? $addr['village']
                : (is_string($addr['municipality'] ?? null) ? $addr['municipality'] : $this->city)));
            if (! $resolvedAddress) {
                $resolvedAddress = is_string($addr['road'] ?? null) ? $addr['road'] : null;
            }
        }

        // Try existing location by place_id
        if ($placeId) {
            $existing = Location::where('place_id', $placeId)->first();
            if ($existing) {
                $this->locationId = $existing->id;
                $this->city = $existing->city ?? $this->city;
                $this->dispatch('location-selected', locationId: $existing->id, city: $this->city, address: $resolvedAddress);

                return;
            }
        }

        // Create new location
        $location = Location::create([
            'name' => $resolvedCity,
            'address' => $resolvedAddress,
            'city' => $resolvedCity,
            'country' => $country,
            'postal_code' => $postalCode,
            'latitude' => $this->lat,
            'longitude' => $this->lng,
            'place_id' => $placeId,
            'source' => 'profile',
        ]);

        $this->locationId = $location->id;
        $this->dispatch('location-selected', locationId: $location->id, city: $resolvedCity, address: $resolvedAddress);
    }

    public function render(): View
    {
        return view('livewire.components.location-picker');
    }

    /**
     * Auto-fill the address field from reverse-geocode data in session mode.
     * Only fills if the address is currently empty to avoid overwriting user input.
     *
     * @param  array<string, mixed>  $addr
     */
    private function fillSessionAddressFromGeocode(array $addr): void
    {
        if ($this->mode === 'session' && empty($this->address)) {
            $road = is_string($addr['road'] ?? null) ? $addr['road'] : null;
            $houseNumber = is_string($addr['house_number'] ?? null) ? $addr['house_number'] : null;
            if ($road) {
                $this->address = trim(($houseNumber ? $houseNumber.' ' : '').$road);
            }
        }
    }
}
