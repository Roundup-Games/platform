<?php

namespace App\Livewire\Components;

use App\Models\Location;
use App\Services\GeocodingService;
use App\Services\VenueSearchService;
use App\Traits\HasGuestLocation;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Venue-first location picker for session forms (games, campaigns, events).
 *
 * Provides three selection modes:
 * 1. Venue search (default) — proximity-ordered verified venues
 * 2. Address search (fallback) — geocoding-based location resolution
 * 3. Location instructions — freeform text always available below
 *
 * Events emitted:
 * - `location-selected`: { locationId: string, city: string, address: string|null, isVenue: bool }
 * - `location-removed`: (no payload)
 */
class VenuePicker extends Component
{
    use HasGuestLocation;

    // ── Configuration ──────────────────────────────────

    #[Locked]
    public ?string $locationId = null;

    /** Whether to show the location_instructions field */
    #[Locked]
    public bool $showInstructions = true;

    // ── Venue Search State ─────────────────────────────

    public string $venueQuery = '';
    public array $venues = [];
    public bool $venueSearchPerformed = false;

    // ── Address Search State ───────────────────────────

    public string $city = '';
    public string $address = '';
    public ?float $lat = null;
    public ?float $lng = null;
    public bool $locationConfirmed = false;

    // ── Instructions ───────────────────────────────────

    public string $locationInstructions = '';

    // ── UI State ───────────────────────────────────────

    public string $mode = 'venue'; // 'venue' | 'address'
    public bool $editing = false;

    // Track if we already emitted the initial state
    protected bool $hasEmittedInitialState = false;

    // ── Lifecycle ──────────────────────────────────────

    public function mount(
        ?string $locationId = null,
        bool $showInstructions = true,
        string $locationInstructions = '',
    ): void {
        $this->locationId = $locationId;
        $this->showInstructions = $showInstructions;
        $this->locationInstructions = $locationInstructions;

        if ($locationId) {
            $location = Location::find($locationId);
            if ($location) {
                $this->city = $location->city ?? '';
                $this->address = $location->address ?? '';
                $this->lat = (float) $location->latitude;
                $this->lng = (float) $location->longitude;
                $this->locationConfirmed = true;
                $this->editing = false;
            }
        }

        // Emit initial instructions state to parent
        if ($this->locationInstructions !== '') {
            $this->dispatch('location-instructions-updated', instructions: $this->locationInstructions);
        }
    }

    // ── Rules ──────────────────────────────────────────

    protected function rules(): array
    {
        return [
            'city' => ['required', 'string', 'max:255'],
        ];
    }

    // ── Venue Search Actions ───────────────────────────

    public function searchVenues(): void
    {
        $this->venueSearchPerformed = true;
        $service = app(VenueSearchService::class);

        $results = $service->search(
            lat: $this->guestLat,
            lng: $this->guestLng,
            query: trim($this->venueQuery) ?: null,
            radiusKm: 50,
            limit: 20,
        );

        $this->venues = $results->map(fn ($v) => [
            'id' => $v->id,
            'name' => $v->name,
            'city' => $v->city,
            'address' => $v->address,
            'venue_type' => $v->venue_type,
            'distance_km' => $v->distance_km,
        ])->values()->all();
    }

    public function selectVenue(string $venueId): void
    {
        $venue = app(VenueSearchService::class)->findVenue($venueId);
        if (!$venue) {
            $this->addError('venueQuery', __('venues.error_venue_not_found'));

            return;
        }

        $this->locationId = $venue->id;
        $this->city = $venue->city ?? '';
        $this->address = $venue->address ?? '';
        $this->lat = (float) $venue->latitude;
        $this->lng = (float) $venue->longitude;
        $this->locationConfirmed = true;
        $this->editing = false;

        $this->dispatch(
            'location-selected',
            locationId: $venue->id,
            city: $venue->city ?? '',
            address: $venue->address,
            isVenue: true,
        );
    }

    public function clearVenueSearch(): void
    {
        $this->venueQuery = '';
        $this->venues = [];
        $this->venueSearchPerformed = false;
    }

    // ── Address Search Actions ─────────────────────────

    public function confirmAddress(): void
    {
        $this->validateOnly('city');

        $query = trim($this->city . ($this->address ? ', ' . $this->address : ''));

        try {
            $geocodingService = app(GeocodingService::class);
            $result = $geocodingService->geocode($query);

            if ($result) {
                $this->lat = $result['lat'];
                $this->lng = $result['lng'];
                $this->resolveAddressAndEmit();
            } else {
                $this->addError('city', __('location.error_could_not_find_this_location_city'));
            }
        } catch (\Throwable $e) {
            Log::error('Geocoding failed in VenuePicker', [
                'city' => $this->city, 'error' => $e->getMessage(),
            ]);
            $this->addError('city', __('location.error_location_lookup_failed'));
        }
    }

    // ── Shared Actions ─────────────────────────────────

    public function removeLocation(): void
    {
        $this->locationId = null;
        $this->editing = false;
        $this->locationConfirmed = false;
        $this->city = '';
        $this->address = '';
        $this->lat = null;
        $this->lng = null;
        $this->venues = [];
        $this->venueSearchPerformed = false;
        $this->venueQuery = '';

        $this->dispatch('location-removed');
    }

    public function startEditing(): void
    {
        $this->editing = true;
        $this->locationConfirmed = false;
        $this->mode = 'venue';
        $this->venues = [];
        $this->venueSearchPerformed = false;

        // Auto-search venues if we have guest location
        if ($this->hasGuestLocation()) {
            $this->searchVenues();
        }
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        if ($this->locationId) {
            $this->locationConfirmed = true;
        }
    }

    public function switchMode(string $mode): void
    {
        $this->mode = in_array($mode, ['venue', 'address']) ? $mode : 'venue';
    }

    // ── Guest Location Hook ────────────────────────────

    public function onGuestLocationUpdated(float $lat, float $lng, string $source = 'unknown'): void
    {
        parent::onGuestLocationUpdated($lat, $lng, $source);

        // Auto-trigger venue search once location arrives
        if ($this->editing && $this->mode === 'venue' && !$this->venueSearchPerformed) {
            $this->searchVenues();
        }
    }

    /**
     * Sync instructions to parent form whenever they change.
     */
    public function updatedLocationInstructions(string $value): void
    {
        $this->dispatch('location-instructions-updated', instructions: $value);
    }

    // ── Computed ───────────────────────────────────────

    #[Computed]
    public function selectedVenue(): ?Location
    {
        if (!$this->locationId || !$this->locationConfirmed) {
            return null;
        }

        return Location::find($this->locationId);
    }

    #[Computed]
    public function selectedIsVenue(): bool
    {
        if (!$this->locationId || !$this->locationConfirmed) {
            return false;
        }

        return Location::where('id', $this->locationId)
            ->where('is_verified', true)
            ->exists();
    }

    // ── Internals ──────────────────────────────────────

    private function resolveAddressAndEmit(): void
    {
        if ($this->lat === null || $this->lng === null) {
            return;
        }

        $geocodingService = app(GeocodingService::class);
        $query = trim($this->city . ($this->address ? ', ' . $this->address : ''));
        $geocodeResult = $geocodingService->geocode($query);

        $placeId = null;
        $country = null;
        $postalCode = null;
        $resolvedCity = $this->city;
        $resolvedAddress = $this->address ?: null;

        if ($geocodeResult) {
            $placeId = $geocodeResult['place_id'] ?? null;
            $raw = $geocodeResult['raw'] ?? [];
            $addr = $raw['address'] ?? [];
            $country = strtoupper($addr['country_code'] ?? '') ?: null;
            $postalCode = $addr['postcode'] ?? null;
            $resolvedCity = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? $this->city;
            if (!$resolvedAddress) {
                $resolvedAddress = $addr['road'] ?? null;
            }
        }

        // Try existing location by place_id
        if ($placeId) {
            $existing = Location::where('place_id', $placeId)->first();
            if ($existing) {
                $this->locationId = $existing->id;
                $this->city = $existing->city ?? $this->city;
                $this->locationConfirmed = true;
                $this->editing = false;
                $this->dispatch(
                    'location-selected',
                    locationId: $existing->id,
                    city: $this->city,
                    address: $resolvedAddress,
                    isVenue: false,
                );

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
            'source' => 'session',
        ]);

        $this->locationId = $location->id;
        $this->locationConfirmed = true;
        $this->editing = false;
        $this->dispatch(
            'location-selected',
            locationId: $location->id,
            city: $resolvedCity,
            address: $resolvedAddress,
            isVenue: false,
        );
    }

    public function render()
    {
        return view('livewire.components.venue-picker');
    }
}
