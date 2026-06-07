<?php

namespace App\Traits;

use App\Models\Location;
use App\Services\VenueSearchService;

/**
 * Shared venue search and address creation for edit modals.
 *
 * Requires the consuming Livewire component to define:
 * - public ?string $edit_location_id = null;
 * - public string $edit_location_instructions = '';
 * - public string $edit_location_name = '';
 * - public string $edit_location_city = '';
 * - public string $edit_location_address = '';
 * - public string $edit_venue_query = '';
 * - public array $edit_venue_results = [];
 * - public bool $edit_venue_searched = false;
 * - public string $edit_address_city = '';
 * - public string $edit_address_street = '';
 * - public string $edit_address_mode = 'venue';
 * - public ?string $editingGameId or $editingCampaignId (for implicit auth guard)
 */
trait EditsVenueLocation
{
    /**
     * Search verified venues by text query.
     * Returns up to 8 results, ordered alphabetically (no proximity in edit modals).
     */
    public function editSearchVenues(): void
    {
        if (! $this->requireEditContext()) {
            return;
        }

        $this->edit_venue_results = app(VenueSearchService::class)
            ->search(lat: null, lng: null, query: $this->edit_venue_query, limit: 8)
            ->toArray();
        $this->edit_venue_searched = true;
    }

    /**
     * Select a verified venue as the edit-modal's location.
     */
    public function editSelectVenue(string $venueId): void
    {
        if (! $this->requireEditContext()) {
            return;
        }

        $venue = Location::where('id', $venueId)->where('is_verified', true)->first();
        if (! $venue) {
            return;
        }

        $this->edit_location_id = $venue->id;
        $this->edit_location_name = $venue->name;
        $this->edit_location_city = $venue->city ?? '';
        $this->edit_location_address = $venue->address ?? '';
        $this->edit_address_city = $venue->city ?? '';
        $this->edit_address_street = $venue->address ?? '';
        $this->edit_venue_results = [];
        $this->edit_venue_searched = false;
        $this->edit_venue_query = '';
    }

    /**
     * Clear the selected location in the edit modal.
     */
    public function editClearLocation(): void
    {
        if (! $this->requireEditContext()) {
            return;
        }

        $this->edit_location_id = null;
        $this->edit_location_name = '';
        $this->edit_location_city = '';
        $this->edit_location_address = '';
        $this->edit_address_city = '';
        $this->edit_address_street = '';
    }

    /**
     * Create a new Location from the address fields and select it.
     * The location is marked source=manual and created without geocoding.
     * If the edit is cancelled, the orphan will be cleaned by the
     * PruneOrphanLocations command (or can be cleaned manually).
     */
    public function editSaveAddress(): void
    {
        if (! $this->requireEditContext()) {
            return;
        }

        $this->validateOnly('edit_address_city', ['edit_address_city' => 'required|string|max:255']);

        $location = Location::create([
            'name' => trim($this->edit_address_street
                ? $this->edit_address_street . ', ' . $this->edit_address_city
                : $this->edit_address_city),
            'address' => $this->edit_address_street ?: null,
            'city' => $this->edit_address_city,
            'source' => 'manual',
        ]);

        $this->edit_location_id = $location->id;
        $this->edit_location_name = $location->name;
        $this->edit_location_city = $location->city ?? '';
        $this->edit_location_address = $location->address ?? '';
    }

    /**
     * Switch between venue and address mode in the edit modal.
     */
    public function editSetAddressMode(string $mode): void
    {
        if (in_array($mode, ['venue', 'address'], true)) {
            $this->edit_address_mode = $mode;
        }
    }

    /**
     * Guard: ensure an edit context is active before performing venue actions.
     * Prevents unauthorized Location creation when no entity is being edited.
     */
    private function requireEditContext(): bool
    {
        return (property_exists($this, 'editingGameId') && $this->editingGameId !== null)
            || (property_exists($this, 'editingCampaignId') && $this->editingCampaignId !== null);
    }
}
