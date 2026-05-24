<?php

use App\Livewire\Components\LocationPicker;
use App\Livewire\Profile\Show;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

// ── Location Persistence via Profile ──────────────────

it('persists location_id on save', function () {
    $location = Location::factory()->create([
        'name' => 'Munich',
        'city' => 'Munich',
        'country' => 'DE',
    ]);
    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('locationId', $location->id)
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    expect($user->fresh()->location_id)->toBe($location->id);
});

// ── LocationPicker Validation ─────────────────────────

it('validates city max length in LocationPicker', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(LocationPicker::class, ['locationId' => null])
        ->set('city', str_repeat('a', 256))
        ->call('confirmLocation')
        ->assertHasErrors(['city']);
});

it('validates city is required for confirm in LocationPicker', function () {
    Livewire::test(LocationPicker::class, ['locationId' => null])
        ->set('city', '')
        ->call('confirmLocation')
        ->assertHasErrors(['city']);
});

// ── Location Display ──────────────────────────────────

it('displays current location from location_id relationship', function () {
    $location = Location::factory()->create([
        'name' => 'Tokyo',
        'city' => 'Tokyo',
        'country' => 'JP',
        'address' => 'Shibuya',
        'postal_code' => '150-0001',
    ]);
    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => $location->id,
    ]);

    $component = Livewire::actingAs($user)->test(Show::class);

    $component->assertSet('locationId', $location->id);
    $component->assertViewHas('currentLocation');
    $currentLocation = $component->viewData('currentLocation');
    expect($currentLocation)->not->toBeNull();
    expect($currentLocation->fullAddress())->toContain('Tokyo');
});

it('shows add location prompt when user has no location', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->assertSet('locationId', null)
        ->assertSee(__('location.action_set_your_location'));
});

// ── Location Removal ──────────────────────────────────

it('removes location when removeLocation called in LocationPicker', function () {
    $location = Location::factory()->create([
        'name' => 'Vienna',
        'city' => 'Vienna',
        'country' => 'AT',
    ]);

    Livewire::test(LocationPicker::class, ['locationId' => $location->id])
        ->call('removeLocation')
        ->assertSet('locationId', null)
        ->assertSet('editing', false)
        ->assertSet('locationConfirmed', false)
        ->assertSet('city', '')
        ->assertDispatched('location-removed');
});

// ── Location Search & Geocoding ───────────────────────

it('searches and resolves location via geocoding in LocationPicker', function () {
    Http::fake([
        '*nominatim*' => Http::response([[
            'lat' => '52.5200',
            'lon' => '13.4050',
            'display_name' => 'Berlin, Germany',
            'place_id' => 12345,
            'address' => [
                'city' => 'Berlin',
                'country' => 'Germany',
                'country_code' => 'de',
                'postcode' => '10115',
            ],
        ]], 200),
    ]);

    Cache::flush();

    $component = Livewire::test(LocationPicker::class, ['locationId' => null])
        ->set('city', 'Berlin, Germany')
        ->call('findMyLocation');

    $locationId = $component->get('locationId');
    expect($locationId)->not->toBeNull();

    $location = Location::find($locationId);
    expect($location)->not->toBeNull()
        ->and($location->city)->toBe('Berlin')
        ->and($location->country)->toBe('DE')
        ->and($location->source)->toBe('profile');

    $component->assertDispatched('location-selected');
});

it('reuses existing location when place_id matches in LocationPicker', function () {
    $existingLocation = Location::factory()->create([
        'name' => 'Berlin',
        'city' => 'Berlin',
        'country' => 'DE',
        'place_id' => 'existing-place-123',
        'latitude' => '52.5200000',
        'longitude' => '13.4050000',
    ]);

    Http::fake([
        '*nominatim*' => Http::response([[
            'lat' => '52.5200',
            'lon' => '13.4050',
            'display_name' => 'Berlin, Germany',
            'place_id' => 'existing-place-123',
            'address' => [
                'city' => 'Berlin',
                'country' => 'Germany',
                'country_code' => 'de',
            ],
        ]], 200),
    ]);

    Cache::flush();

    Livewire::test(LocationPicker::class, ['locationId' => null])
        ->set('city', 'Berlin')
        ->call('findMyLocation')
        ->assertSet('locationId', $existingLocation->id);

    expect(Location::count())->toBe(1);
});

it('shows error when geocoding finds no results in LocationPicker', function () {
    Http::fake([
        '*nominatim*' => Http::response([], 200),
    ]);

    Cache::flush();

    Livewire::test(LocationPicker::class, ['locationId' => null])
        ->set('city', 'asdfghjkl nonexistent')
        ->call('findMyLocation')
        ->assertHasErrors(['city']);
});

// ── Location Confirmation ─────────────────────────────

it('confirms location and sets confirmed state in LocationPicker', function () {
    Http::fake([
        '*nominatim*' => Http::response([[
            'lat' => '48.8566',
            'lon' => '2.3522',
            'display_name' => 'Paris, France',
            'place_id' => 'paris-999',
            'address' => [
                'city' => 'Paris',
                'country' => 'France',
                'country_code' => 'fr',
                'postcode' => '75001',
            ],
        ]], 200),
    ]);

    Cache::flush();

    Livewire::test(LocationPicker::class, ['locationId' => null])
        ->set('city', 'Paris')
        ->call('findMyLocation')
        ->assertSet('locationConfirmed', true)
        ->assertSet('city', 'Paris');
});

// ── Location Edit & Replace ───────────────────────────

it('can edit and replace location in LocationPicker', function () {
    $oldLocation = Location::factory()->create([
        'name' => 'Berlin',
        'city' => 'Berlin',
        'country' => 'DE',
        'place_id' => 'old-berlin-place',
        'latitude' => '52.5200000',
        'longitude' => '13.4050000',
    ]);

    Http::fake([
        '*nominatim*' => Http::response([[
            'lat' => '48.8566',
            'lon' => '2.3522',
            'display_name' => 'Paris, France',
            'place_id' => 'new-paris-place',
            'address' => [
                'city' => 'Paris',
                'country' => 'France',
                'country_code' => 'fr',
                'postcode' => '75001',
            ],
        ]], 200),
    ]);

    Cache::flush();

    $component = Livewire::test(LocationPicker::class, ['locationId' => $oldLocation->id])
        ->assertSet('locationId', $oldLocation->id)
        ->call('startEditing')
        ->assertSet('editing', true)
        ->set('city', 'Paris')
        ->call('findMyLocation')
        ->assertSet('editing', false);

    $newLocationId = $component->get('locationId');
    expect($newLocationId)->not->toBeNull()
        ->and($newLocationId)->not->toBe($oldLocation->id);

    $newLocation = Location::find($newLocationId);
    expect($newLocation->city)->toBe('Paris')
        ->and($newLocation->country)->toBe('FR');
});

// ── Location Integration: Picker → Profile Save ──────

it('persists location via Show after LocationPicker resolves it', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => null,
    ]);

    Http::fake([
        '*nominatim*' => Http::response([[
            'lat' => '47.3769',
            'lon' => '8.5417',
            'display_name' => 'Zurich, Switzerland',
            'place_id' => 'zurich-persist-test',
            'address' => [
                'city' => 'Zurich',
                'country' => 'Switzerland',
                'country_code' => 'ch',
                'postcode' => '8001',
            ],
        ]], 200),
    ]);

    Cache::flush();

    // Resolve location via picker
    $picker = Livewire::test(LocationPicker::class, ['locationId' => null])
        ->set('city', 'Zurich')
        ->call('findMyLocation');

    $resolvedLocationId = $picker->get('locationId');
    expect($resolvedLocationId)->not->toBeNull();

    $location = Location::find($resolvedLocationId);
    expect($location->city)->toBe('Zurich')
        ->and($location->country)->toBe('CH')
        ->and($location->source)->toBe('profile');

    // Persist via Show component
    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('locationId', $resolvedLocationId)
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($user->fresh()->location_id)->toBe($resolvedLocationId);
});

// ── LocationPicker Mode Parameter ─────────────────────

it('shows neighborhood field in profile mode', function () {
    $html = Livewire::test(LocationPicker::class, ['locationId' => null])
        ->call('startEditing')
        ->html();

    expect($html)->toContain(__('location.field_neighborhood'));
});

it('shows address field with optional label in session mode', function () {
    $html = Livewire::test(LocationPicker::class, ['locationId' => null, 'mode' => 'session'])
        ->call('startEditing')
        ->html();

    expect($html)->toContain(__('location.field_address_optional'));
});

it('defaults to profile mode when no mode specified', function () {
    $component = Livewire::test(LocationPicker::class, ['locationId' => null]);

    expect($component->get('mode'))->toBe('profile');
});

it('falls back to profile mode for invalid mode value', function () {
    $component = Livewire::test(LocationPicker::class, ['locationId' => null, 'mode' => 'invalid']);

    expect($component->get('mode'))->toBe('profile');
});

it('auto-fills address from browser location in session mode', function () {
    Http::fake([
        '*nominatim*' => Http::response([
            'address' => [
                'city' => 'Berlin',
                'road' => 'Alexanderstraße',
                'house_number' => '7',
                'country' => 'Germany',
                'country_code' => 'de',
            ],
        ], 200),
    ]);

    Cache::flush();

    $component = Livewire::test(LocationPicker::class, ['locationId' => null, 'mode' => 'session'])
        ->call('handleBrowserLocation', 52.52, 13.405);

    expect($component->get('city'))->toBe('Berlin')
        ->and($component->get('address'))->toBe('7 Alexanderstraße');
});

it('does not auto-fill address from browser location in profile mode', function () {
    Http::fake([
        '*nominatim*' => Http::response([
            'address' => [
                'city' => 'Berlin',
                'road' => 'Alexanderstraße',
                'house_number' => '7',
                'country' => 'Germany',
                'country_code' => 'de',
            ],
        ], 200),
    ]);

    Cache::flush();

    $component = Livewire::test(LocationPicker::class, ['locationId' => null, 'mode' => 'profile'])
        ->call('handleBrowserLocation', 52.52, 13.405);

    expect($component->get('city'))->toBe('Berlin')
        ->and($component->get('address'))->toBe('');
});

it('auto-fills address from guest location in session mode', function () {
    Http::fake([
        '*nominatim*' => Http::response([
            'address' => [
                'city' => 'Berlin',
                'road' => 'Friedrichstraße',
                'house_number' => '123',
                'country' => 'Germany',
                'country_code' => 'de',
            ],
        ], 200),
    ]);

    Cache::flush();

    $component = Livewire::test(LocationPicker::class, ['locationId' => null, 'mode' => 'session'])
        ->call('onGuestLocationUpdated', 52.52, 13.405, 'localStorage');

    expect($component->get('city'))->toBe('Berlin')
        ->and($component->get('address'))->toBe('123 Friedrichstraße');
});

it('shows no location set text when empty', function () {
    $html = Livewire::test(LocationPicker::class, ['locationId' => null])
        ->html();

    expect($html)->toContain(__('location.content_location_not_set'));
});

it('shows set your location CTA in profile mode', function () {
    $html = Livewire::test(LocationPicker::class, ['locationId' => null, 'mode' => 'profile'])
        ->html();

    expect($html)->toContain(__('location.action_set_your_location'));
});

it('shows add location CTA in session mode', function () {
    $html = Livewire::test(LocationPicker::class, ['locationId' => null, 'mode' => 'session'])
        ->html();

    expect($html)->toContain(__('location.action_add_location'));
});
