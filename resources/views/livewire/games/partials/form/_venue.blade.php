{{-- Venue picker.
     Shared by the create form and the edit mode (D315): included with no argument array, so it
     reads the including view's scope — $location_id, $location_instructions. --}}
    <livewire:components.venue-picker :location-id="$location_id" :location-instructions="$location_instructions" />
