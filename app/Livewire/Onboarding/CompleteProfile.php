<?php

namespace App\Livewire\Onboarding;

use App\Enums\ContentLanguage;
use App\Jobs\UpdateUserDiscoveryCache;
use App\Models\Location;
use App\Services\GeocodingService;
use App\Traits\HasGuestLocation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
#[Layout('onboarding.layout')]
class CompleteProfile extends Component
{
    use HasGuestLocation;

    #[On('selection-changed')]
    public function onSelectionChanged(string $preferenceType, array $selectedIds): void
    {
        if ($preferenceType === 'favorite') {
            $this->favoriteGameSystemIds = $selectedIds;
        }
    }

    public int $step = 1;

    // Step 1: Location properties
    #[Validate(['required', 'string', 'max:255'])]
    public string $city = '';

    #[Validate(['nullable', 'string', 'max:255'])]
    public string $address = '';

    public ?float $lat = null;

    public ?float $lng = null;

    public string $locationSource = 'manual'; // 'manual' or 'localStorage'

    public bool $locationConfirmed = false;

    public bool $showManualEntry = false;

    // Step 2: Identity properties
    #[Validate(['required', 'string', 'max:50'])]
    public string $gender = '';

    #[Validate(['required', 'string', 'max:50'])]
    public string $pronouns = '';

    // Step 3: Contact properties
    #[Validate(['nullable', 'string', 'max:30'])]
    public string $phone = '';

    // Step 4: Preferences properties
    /** @var array<int> */
    #[Validate(['array'])]
    public array $favoriteGameSystemIds = [];

    public function rules(): array
    {
        return [
            'favoriteGameSystemIds.*' => ['exists:game_systems,id'],
        ];
    }

    public function mount(): void
    {
        $user = Auth::user();

        if ($user->profile_complete) {
            $this->redirectRoute('dashboard');

            return;
        }

        // Pre-fill from any existing user data (e.g. from OAuth)
        $this->gender = $user->gender ?? '';
        $this->pronouns = $user->pronouns ?? '';
        $this->phone = $user->phone ?? '';

        // Pre-fill location from existing user location
        if ($user->location_id && $user->linkedLocation) {
            $loc = $user->linkedLocation;
            $this->city = $loc->city ?? '';
            $this->address = $loc->address ?? '';
            $this->lat = (float) $loc->latitude;
            $this->lng = (float) $loc->longitude;
            $this->locationConfirmed = true;
        }
    }

    /**
     * Handle the guest location arriving from localStorage via the HasGuestLocation trait.
     * Reverse geocode to get the city name and pre-populate the location step.
     */
    public function onGuestLocationUpdated(float $lat, float $lng, string $source = 'unknown'): void
    {
        $this->guestLat = $lat;
        $this->guestLng = $lng;
        $this->guestLocationSource = $source;

        // Only auto-populate if user hasn't already set location
        if ($this->city === '' && ! $this->locationConfirmed) {
            $this->lat = $lat;
            $this->lng = $lng;
            $this->locationSource = 'localStorage';

            // Reverse geocode to get the city name
            try {
                $geocodingService = app(GeocodingService::class);
                $result = $geocodingService->reverseGeocode($lat, $lng);

                if ($result && isset($result['address'])) {
                    $address = $result['address'];
                    $this->city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? '';
                }
            } catch (\Throwable $e) {
                Log::warning('Reverse geocoding failed during onboarding', [
                    'lat' => $lat,
                    'lng' => $lng,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * User confirms the detected location.
     */
    public function confirmLocation(): void
    {
        $this->validateOnly('city');
        $this->locationConfirmed = true;
    }

    /**
     * User wants to edit the detected / confirmed location.
     */
    public function editLocation(): void
    {
        $this->locationConfirmed = false;
        $this->showManualEntry = true;
    }

    /**
     * "Find my location" action.
     *
     * If the user has typed a city, geocode that text.
     * If the city field is empty, trigger browser geolocation which
     * dispatches back via onGuestLocationUpdated() after reverse-geocoding.
     */
    public function findMyLocation(): void
    {
        $query = trim($this->city . ($this->address ? ', ' . $this->address : ''));

        if ($query === '') {
            // No text entered — trigger browser geolocation.
            // The result arrives via handleBrowserLocation() below.
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

        // User typed something — geocode their text
        $this->geocodeCityText($query);
    }

    /**
     * Receive browser geolocation coordinates from the JS bridge.
     * Reverse-geocode to populate the city field and show the detected location.
     */
    public function handleBrowserLocation(float $lat, float $lng): void
    {
        $this->lat = $lat;
        $this->lng = $lng;
        $this->locationSource = 'localStorage';

        try {
            $geocodingService = app(GeocodingService::class);
            $result = $geocodingService->reverseGeocode($lat, $lng);

            if ($result && isset($result['address'])) {
                $address = $result['address'];
                $this->city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? '';
            }
        } catch (\Throwable $e) {
            Log::warning('Reverse geocoding failed during onboarding findMyLocation', [
                'lat' => $lat,
                'lng' => $lng,
                'error' => $e->getMessage(),
            ]);
        }

        // If reverse geocoding couldn't resolve a city, show an error
        if ($this->city === '') {
            $this->addError('city', __('location.error_could_not_detect_city'));
        }
    }

    /**
     * Add an error when browser geolocation was denied or unavailable.
     * Called from the JS fallback after locateAndDispatch fails.
     */
    public function addGeolocationError(): void
    {
        $this->addError('city', __('location.error_location_permission_denied'));
    }

    /**
     * Geocode a city/address text query to resolve coordinates.
     */
    private function geocodeCityText(string $query): void
    {
        try {
            $geocodingService = app(GeocodingService::class);
            $result = $geocodingService->geocode($query);

            if ($result) {
                $this->lat = $result['lat'];
                $this->lng = $result['lng'];
                $this->locationConfirmed = true;
                $this->locationSource = 'manual';
                Log::info('Onboarding: city geocoded successfully', [
                    'city' => $this->city,
                    'lat' => $result['lat'],
                    'lng' => $result['lng'],
                ]);
            } else {
                $this->addError('city', __('location.error_could_not_find_this_location_city'));
            }
        } catch (\Throwable $e) {
            Log::error('Geocoding failed during onboarding', [
                'city' => $this->city,
                'error' => $e->getMessage(),
            ]);
            $this->addError('city', __('location.error_location_lookup_failed'));
        }
    }

    public function nextStep(): void
    {
        $this->validateStep($this->step);
        $this->step++;
    }

    public function previousStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function complete(): void
    {
        // Validate all steps before completing
        $this->validateStep(1);
        $this->validateStep(2);
        $this->validateStep(3);

        // Validate game system IDs against actual GameSystem records
        $this->validate([
            'favoriteGameSystemIds' => ['array'],
            'favoriteGameSystemIds.*' => ['exists:game_systems,id'],
        ]);

        $user = Auth::user();

        $locale = app()->getLocale();
        $preferredLanguage = match ($locale) {
            'de' => ContentLanguage::De,
            default => ContentLanguage::En,
        };

        // Resolve or create the Location record
        $locationId = $this->resolveLocationId();

        $user->update([
            'gender' => $this->gender,
            'pronouns' => $this->pronouns,
            'phone' => $this->phone ?: null,
            'preferred_language' => $preferredLanguage,
            'location_id' => $locationId,
            'profile_complete' => true,
            'profile_version' => ($user->profile_version ?? 0) + 1,
            'profile_updated_at' => now(),
        ]);

        // Sync favorite game systems using syncWithPivotValues for idempotency
        $syncData = collect($this->favoriteGameSystemIds)->mapWithKeys(fn ($id) => [
            $id => ['preference_type' => 'favorite'],
        ])->toArray();
        $user->gameSystemPreferences()->sync($syncData);

        Log::info('Onboarding completed', [
            'user_id' => $user->id,
            'gender' => $this->gender,
            'game_systems_count' => count($this->favoriteGameSystemIds),
            'profile_version' => $user->profile_version,
            'location_id' => $locationId,
            'location_source' => $this->locationSource,
        ]);

        // Dispatch discovery cache population for new users with a valid location
        $freshUser = $user->fresh();
        if ($freshUser->linkedLocation?->latitude && $freshUser->linkedLocation?->longitude) {
            UpdateUserDiscoveryCache::dispatch($freshUser->id, 'location_change');
        }

        $this->redirectRoute('dashboard');
    }

    public function render()
    {
        return view('livewire.onboarding.complete-profile');
    }

    /**
     * Resolve or create a Location record from the onboarding location data.
     */
    private function resolveLocationId(): ?int
    {
        if (! $this->locationConfirmed || $this->lat === null || $this->lng === null) {
            return null;
        }

        $geocodingService = app(GeocodingService::class);

        // Try to get full geocoding data for the location
        $query = trim($this->city . ($this->address ? ', ' . $this->address : ''));
        $geocodeResult = $geocodingService->geocode($query);

        $placeId = null;
        $country = null;
        $postalCode = null;

        if ($geocodeResult) {
            $placeId = $geocodeResult['place_id'] ?? null;
            // Extract address components from raw result
            $raw = $geocodeResult['raw'] ?? [];
            $address = $raw['address'] ?? [];
            // Use country_code (ISO 2-char like "DE") — country column is varchar(3)
            $country = strtoupper($address['country_code'] ?? '') ?: null;
            $postalCode = $address['postcode'] ?? null;
        }

        // If we have a place_id, try to find existing location
        if ($placeId) {
            $existing = Location::where('place_id', $placeId)->first();
            if ($existing) {
                return $existing->id;
            }
        }

        // Create new location
        $location = Location::create([
            'name' => $this->city,
            'address' => $this->address ?: null,
            'city' => $this->city,
            'country' => $country,
            'postal_code' => $postalCode,
            'latitude' => $this->lat,
            'longitude' => $this->lng,
            'place_id' => $placeId,
            'source' => 'onboarding',
        ]);

        return $location->id;
    }

    private function validateStep(int $step): void
    {
        match ($step) {
            1 => (function () {
                // Location step: city is required, coordinates must be confirmed
                $this->validateOnly('city');
                if (! $this->locationConfirmed || $this->lat === null || $this->lng === null) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'city' => [__('location.error_please_confirm_your_location_to_continue')],
                    ]);
                }
            })(),
            2 => (function () {
                $this->validateOnly('gender');
                $this->validateOnly('pronouns');
            })(),
            3 => $this->validateOnly('phone'),
            default => null,
        };
    }
}
