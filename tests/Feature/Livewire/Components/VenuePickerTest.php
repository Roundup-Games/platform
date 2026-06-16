<?php

use App\Enums\VenueType;
use App\Livewire\Components\VenuePicker;
use App\Models\Location;
use App\Models\User;
use App\Services\GeocodingService;
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

it('saves address even when geocoding returns no results', function () {
    $this->mock(GeocodingService::class, function ($mock) {
        $mock->shouldReceive('geocode')->andReturn(null);
    });

    Livewire::test(VenuePicker::class)
        ->call('startEditing')
        ->call('switchMode', 'address')
        ->set('city', 'Nonexistent City')
        ->set('address', '123 Fictional St')
        ->call('confirmAddress')
        ->assertSet('locationConfirmed', true)
        ->assertSet('city', 'Nonexistent City')
        ->assertDispatched('location-selected');
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

// ── Disclosure-Consequence Preview (T08) ────────────────

it('shows the full-address preview for a verified commercial venue', function () {
    $venue = Location::factory()->create([
        'is_verified' => true,
        'venue_type' => VenueType::Cafe,
        'name' => 'Board Cafe',
        'address' => '123 Main St',
        'city' => 'Berlin',
        'postal_code' => '10115',
    ]);

    $component = Livewire::test(VenuePicker::class, ['locationId' => $venue->id]);

    // The computed resolves the stranger preview level via the disclosure service.
    $preview = $component->instance()->disclosurePreview;
    expect($preview['level'])->toBe('exact')
        ->and($preview['address'])->toBe('123 Main St, 10115 Berlin');

    // The view renders the consequence line with the full address.
    $component->assertSee(__('location.content_preview_heading'))
        ->assertSee(__('location.content_preview_exact', ['address' => '123 Main St, 10115 Berlin']));
});

it('shows the in-your-area preview for a private address', function () {
    $private = Location::factory()->create([
        'is_verified' => false,
        'venue_type' => null,
        'name' => 'My Apartment',
        'address' => '123 Main St',
        'city' => 'Berlin',
    ]);

    $component = Livewire::test(VenuePicker::class, ['locationId' => $private->id]);

    $preview = $component->instance()->disclosurePreview;
    expect($preview['level'])->toBe('area')
        ->and($preview['address'])->toBeNull();

    $component->assertSee(__('location.content_preview_heading'))
        ->assertSee(__('location.content_preview_area'))
        // The exact-address line must NOT appear for a private location.
        ->assertDontSee(__('location.content_preview_exact', ['address' => '123 Main St']));
});

it('shows the in-your-area preview for a verified "other" venue type', function () {
    // VenueType::Other is intentionally excluded from commercial types — a
    // verified 'other' is treated as private for stranger disclosure.
    $other = Location::factory()->create([
        'is_verified' => true,
        'venue_type' => VenueType::Other,
        'name' => 'Clubhouse',
        'address' => '5 Side St',
        'city' => 'Berlin',
    ]);

    $component = Livewire::test(VenuePicker::class, ['locationId' => $other->id]);

    $preview = $component->instance()->disclosurePreview;
    expect($preview['level'])->toBe('area')
        ->and($preview['address'])->toBeNull();

    $component->assertSee(__('location.content_preview_area'))
        ->assertDontSee(__('location.content_preview_exact', ['address' => '5 Side St']));
});

it('does not render the preview before a location is confirmed', function () {
    // No location selected → no preview.
    Livewire::test(VenuePicker::class)
        ->assertDontSee(__('location.content_preview_heading'))
        ->assertDontSee(__('location.content_preview_area'))
        ->assertDontSee(__('location.content_preview_exact', ['address' => '']));
});

it('updates the preview when switching from a private to a verified venue', function () {
    $private = Location::factory()->create([
        'is_verified' => false,
        'venue_type' => null,
        'city' => 'Berlin',
    ]);
    $venue = Location::factory()->create([
        'is_verified' => true,
        'venue_type' => VenueType::Cafe,
        'name' => 'Board Cafe',
        'address' => '123 Main St',
        'city' => 'Berlin',
        'latitude' => 52.52,
        'longitude' => 13.41,
    ]);

    $component = Livewire::test(VenuePicker::class, ['locationId' => $private->id])
        ->assertSee(__('location.content_preview_area'));

    // Switch to the verified venue.
    $component->call('startEditing')
        ->call('selectVenue', $venue->id)
        ->assertSee(__('location.content_preview_exact', ['address' => $venue->fresh()->fullAddress()]));
});

it('renders the preview with an aria-hidden decorative icon', function () {
    // Decorative icons must carry aria-hidden per the a11y convention.
    $venue = Location::factory()->create([
        'is_verified' => true,
        'venue_type' => VenueType::Cafe,
        'name' => 'Board Cafe',
        'address' => '123 Main St',
        'city' => 'Berlin',
    ]);

    Livewire::test(VenuePicker::class, ['locationId' => $venue->id])
        ->assertSeeHtml('aria-hidden="true"');
});
