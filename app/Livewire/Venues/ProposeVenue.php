<?php

namespace App\Livewire\Venues;

use App\Enums\VenueType;
use App\Http\Controllers\Api\CitiesSuggestController;
use App\Models\Location;
use App\Services\GeocodingService;
use App\Services\VenueProposalService;
use App\Support\Country;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ProposeVenue extends Component
{
    // ── Form Properties ──────────────────────────────

    public string $name = '';

    public string $address = '';

    public string $city = '';

    /** @var array<int, array<string, mixed>> */
    public array $citySuggestions = [];

    public bool $showSuggestions = false;

    public ?string $postal_code = null;

    public string $country = '';

    public string $venue_type = '';

    public ?string $website_url = null;

    public ?string $notes = null;

    public ?string $proposer_notes = null;

    public bool $submitted = false;

    public ?string $ticketReference = null;

    public ?bool $existingLocation = null;

    public ?string $existingLocationCity = null;

    // ── Lifecycle ────────────────────────────────────

    public function mount(): void
    {
        // Route is protected by 'auth' middleware
    }

    // ── Validation ───────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    /**
     * Debounced city-input guidance (D174): alias rules and fuzzy matches
     * surface existing hubs as the user types; free text stays allowed —
     * picking a suggestion only fills the canonical label into the field.
     */
    public function updatedCity(string $value): void
    {
        $this->showSuggestions = mb_strlen(trim($value)) >= 3;

        if (! $this->showSuggestions) {
            $this->citySuggestions = [];

            return;
        }

        $response = app(CitiesSuggestController::class)(request()->merge(['q' => $value]));
        $data = $response->getData(true);
        $suggestions = is_array($data) && is_array($data['suggestions'] ?? null) ? $data['suggestions'] : [];

        $this->citySuggestions = collect($suggestions)
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => $item)
            ->values()
            ->all();
    }

    public function selectCitySuggestion(string $city): void
    {
        $this->city = $city;
        $this->showSuggestions = false;
        $this->citySuggestions = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'country' => ['required', 'string', Rule::in(array_keys(Country::options()))],
            'venue_type' => 'required|string|in:'.implode(',', VenueType::values()),
            'website_url' => 'nullable|url|max:500',
            'notes' => 'nullable|string|max:1000',
            'proposer_notes' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationAttributes(): array
    {
        return [
            'name' => __('location.field_venue_name'),
            'address' => __('location.field_address'),
            'city' => __('location.field_city'),
            'postal_code' => __('location.field_postal_code'),
            'country' => __('location.field_country'),
            'venue_type' => __('location.field_venue_type'),
            'website_url' => __('location.field_website_url'),
            'notes' => __('location.field_venue_notes'),
            'proposer_notes' => __('location.field_proposer_notes'),
        ];
    }

    // ── Actions ──────────────────────────────────────

    public function submit(
        VenueProposalService $proposalService,
        GeocodingService $geocodingService,
    ): void {
        $this->validate();

        $user = authenticatedUser();

        $normalizedName = trim($this->name);

        // Check for duplicate pending proposal from same user
        if ($proposalService->hasPendingProposal($user, $normalizedName)) {
            $this->addError('name', __('location.error_proposal_duplicate'));

            return;
        }

        // Rate limit: 5 proposals per day per user
        $rateLimitKey = 'venue-proposal:'.$user->id;

        if (RateLimiter::attempt($rateLimitKey, 5, fn () => true, decaySeconds: 86400) !== true) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $hours = ceil($seconds / 3600);
            $this->addError('name', __('location.error_proposal_rate_limit', ['hours' => $hours]));

            return;
        }

        // Geocode the full address (graceful failure)
        $fullAddress = trim($this->address).', '.trim($this->city);
        if ($this->postal_code !== null && $this->postal_code !== '') {
            $fullAddress .= ', '.trim($this->postal_code);
        }
        if ($this->country !== '') {
            $fullAddress .= ', '.trim($this->country);
        }

        $geocodeResult = $geocodingService->geocode($fullAddress);

        // Check if a Location with matching name+city already exists
        $existingLocation = Location::where('name', trim($this->name))
            ->where('city', trim($this->city))
            ->first();

        $this->existingLocation = $existingLocation !== null;
        $this->existingLocationCity = $existingLocation?->city;

        // Build proposal data
        $proposalData = [
            'name' => $normalizedName,
            'address' => trim($this->address),
            'city' => trim($this->city),
            'postal_code' => $this->postal_code !== null && $this->postal_code !== '' ? trim($this->postal_code) : null,
            'country' => trim($this->country),
            'venue_type' => $this->venue_type,
            'website_url' => $this->website_url !== null && $this->website_url !== '' ? trim($this->website_url) : null,
            'proposer_notes' => $this->proposer_notes !== null && $this->proposer_notes !== '' ? trim($this->proposer_notes) : null,
            'admin_notes' => $this->notes !== null && $this->notes !== '' ? trim($this->notes) : null,
            'latitude' => $geocodeResult['lat'] ?? null,
            'longitude' => $geocodeResult['lng'] ?? null,
            'geocoded_display_name' => $geocodeResult['display_name'] ?? null,
            'existing_location_id' => $existingLocation?->id,
        ];

        try {
            $ticket = $proposalService->createProposal($user, $proposalData);
        } catch (\RuntimeException $e) {
            report($e);
            $this->addError('name', __('location.error_proposal_submission_failed'));

            return;
        }

        $this->ticketReference = $ticket->reference;
        $this->reset(['name', 'address', 'city', 'postal_code', 'country', 'venue_type', 'website_url', 'notes', 'proposer_notes']);
        $this->submitted = true;

        session()->flash('success', __('location.content_proposal_success'));
    }

    // ── Render ───────────────────────────────────────

    public function render(): View
    {
        return view('livewire.venues.propose-venue');
    }
}
