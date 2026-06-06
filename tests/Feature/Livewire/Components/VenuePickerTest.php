<?php

use App\Enums\VenueType;
use App\Livewire\Components\VenuePicker;
use App\Models\Location;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

// ── Venue Search ─────────────────────────────────────────

it('renders with no location set and shows venue CTA', function () {
    Livewire::test(VenuePicker::class)
        ->assertSet('locationId', null)
        ->assertSet('locationConfirmed', false)
        ->assertSee(__('venues.action_choose_venue'));
});

it('renders with existing venue pre-selected', function () {
    $venue = Location::factory()->create([
        'is_verified' => true,
        'venue_type' => VenueType::Cafe,
        'name' => 'Test Cafe',
        'city' => 'Berlin',
    ]);

    Livewire::test(VenuePicker::class, ['locationId' => $venue->id])
        ->assertSet('locationId', $venue->id)
        ->assertSet('locationConfirmed', true)
        ->assertSee('Test Cafe');
});

it('dispatches location-selected when venue is selected', function () {
    $venue = Location::factory()->create([
        'is_verified' => true,
        'venue_type' => VenueType::Cafe,
        'name' => 'Board Cafe',
        'city' => 'Berlin',
        'address' => 'Main St 1',
        'latitude' => 52.52,
        'longitude' => 13.41,
    ]);

    Livewire::test(VenuePicker::class)
        ->call('startEditing')
        ->call('selectVenue', $venue->id)
        ->assertDispatched('location-selected', function ($name, $params) use ($venue) {
            return $params['locationId'] === $venue->id
                && $params['isVenue'] === true;
        })
        ->assertSet('locationConfirmed', true);
});

it('shows error for non-existent venue selection', function () {
    Livewire::test(VenuePicker::class)
        ->call('startEditing')
        ->call('selectVenue', '00000000-0000-0000-0000-000000000000')
        ->assertHasErrors('venueQuery');
});

it('searches venues and returns results', function () {
    Location::factory()->create([
        'name' => 'Board Game Cafe',
        'city' => 'Berlin',
        'is_verified' => true,
        'venue_type' => VenueType::Cafe,
        'latitude' => 52.52,
        'longitude' => 13.41,
    ]);

    Livewire::test(VenuePicker::class)
        ->set('guestLat', 52.52)
        ->set('guestLng', 13.41)
        ->call('startEditing')
        ->set('venueQuery', 'Board')
        ->call('searchVenues')
        ->assertSet('venueSearchPerformed', true)
        ->assertSee('Board Game Cafe');
});

// ── Mode Switching ───────────────────────────────────────

it('switches between venue and address mode', function () {
    Livewire::test(VenuePicker::class)
        ->call('startEditing')
        ->assertSet('mode', 'venue')
        ->call('switchMode', 'address')
        ->assertSet('mode', 'address')
        ->call('switchMode', 'venue')
        ->assertSet('mode', 'venue');
});

it('ignores invalid mode values', function () {
    Livewire::test(VenuePicker::class)
        ->call('startEditing')
        ->call('switchMode', 'invalid')
        ->assertSet('mode', 'venue');
});

// ── Location Removal ─────────────────────────────────────

it('dispatches location-removed and clears state', function () {
    $venue = Location::factory()->create([
        'is_verified' => true,
        'name' => 'Test Venue',
        'city' => 'Berlin',
        'latitude' => 52.52,
        'longitude' => 13.41,
    ]);

    Livewire::test(VenuePicker::class, ['locationId' => $venue->id])
        ->call('removeLocation')
        ->assertDispatched('location-removed')
        ->assertSet('locationId', null)
        ->assertSet('locationConfirmed', false)
        ->assertSet('city', '');
});

// ── Location Instructions ────────────────────────────────

it('dispatches instructions-updated on change', function () {
    Livewire::test(VenuePicker::class)
        ->set('locationInstructions', 'Ring buzzer 3')
        ->assertDispatched('location-instructions-updated', function ($name, $params) {
            return $params['instructions'] === 'Ring buzzer 3';
        });
});

it('pre-fills instructions from mount parameter', function () {
    Livewire::test(VenuePicker::class, ['locationInstructions' => 'Back entrance'])
        ->assertSet('locationInstructions', 'Back entrance');
});

it('emits initial instructions on mount when non-empty', function () {
    Livewire::test(VenuePicker::class, ['locationInstructions' => 'Use side door'])
        ->assertDispatched('location-instructions-updated', function ($name, $params) {
            return $params['instructions'] === 'Use side door';
        });
});

// ── Address Mode ─────────────────────────────────────────

it('shows error when confirming address with empty city', function () {
    Livewire::test(VenuePicker::class)
        ->call('startEditing')
        ->call('switchMode', 'address')
        ->set('city', '')
        ->call('confirmAddress')
        ->assertHasErrors('city');
});

// ── Cancel Editing ───────────────────────────────────────

it('restores confirmed state on cancel', function () {
    $venue = Location::factory()->create([
        'is_verified' => true,
        'name' => 'Existing Venue',
        'city' => 'Berlin',
        'latitude' => 52.52,
        'longitude' => 13.41,
    ]);

    Livewire::test(VenuePicker::class, ['locationId' => $venue->id])
        ->call('startEditing')
        ->call('cancelEditing')
        ->assertSet('locationConfirmed', true)
        ->assertSet('editing', false);
});
