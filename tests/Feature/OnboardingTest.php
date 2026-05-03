<?php

use App\Livewire\Onboarding\CompleteProfile;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;


// ── Onboarding page access ────────────────────────────

it('allows unprofiled user to access onboarding page', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
    ]);

    $response = $this->actingAs($user)->get(route('onboarding.index'));

    $response->assertOk();
});

it('redirects profiled user away from onboarding to dashboard', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
    ]);

    $response = $this->actingAs($user)->get(route('onboarding.index'));

    $response->assertRedirect(route('dashboard'));
});

// ── Registration sets profile_complete=false ──────────

it('redirects to onboarding after registration', function () {
    $response = $this->post(route('register'), [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('onboarding.index'));
})->group('smoke');

// ── Profile completion (direct model) ─────────────────

it('marks profile complete after updating profile fields', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'gender' => null,
        'pronouns' => null,
    ]);

    $user->update([
        'gender' => 'non-binary',
        'pronouns' => 'they/them',
        'phone' => '+15551234567',
        'profile_complete' => true,
        'profile_version' => $user->profile_version + 1,
        'profile_updated_at' => now(),
    ]);

    $fresh = $user->fresh();
    expect($fresh)->profile_complete->toBeTrue()
        ->and($fresh)->gender->toBe('non-binary')
        ->and($fresh)->pronouns->toBe('they/them')
        ->and($fresh)->phone->toBe('+15551234567')
        ->and($fresh)->profile_version->toBe(1);
})->group('smoke');

// ── Livewire Component: Multi-step flow ───────────────

it('redirects profiled user on mount', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->assertRedirect(route('dashboard'));
});

it('advances to step 2 (Identity) with confirmed location', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->assertSet('step', 2)
        ->assertSee('Tell us about yourself');
});

it('validates city is required on step 1', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', '')
        ->call('nextStep')
        ->assertHasErrors('city')
        ->assertSet('step', 1);
});

it('validates location must be confirmed on step 1', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('locationConfirmed', false)
        ->call('nextStep')
        ->assertHasErrors('city')
        ->assertSet('step', 1);
});

it('advances to step 3 (Contact) from step 2 with valid gender and pronouns', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->assertSet('step', 2)
        ->set('gender', 'non-binary')
        ->set('pronouns', 'they/them')
        ->call('nextStep')
        ->assertSet('step', 3)
        ->assertSee('Contact information');
});

it('validates gender is required on step 2', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', '')
        ->set('pronouns', 'they/them')
        ->call('nextStep')
        ->assertHasErrors('gender')
        ->assertSet('step', 2);
});

it('validates pronouns is required on step 2', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', '')
        ->call('nextStep')
        ->assertHasErrors('pronouns')
        ->assertSet('step', 2);
});

it('advances to step 4 (Preferences) from step 3 with optional phone', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'female')
        ->set('pronouns', 'she/her')
        ->call('nextStep')
        ->assertSet('step', 3)
        ->set('phone', '+15551234567')
        ->call('nextStep')
        ->assertSet('step', 4)
        ->assertSee('Game preferences');
});

it('allows empty phone on step 3', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->set('phone', '')
        ->call('nextStep')
        ->assertSet('step', 4);
});

it('goes back to previous step', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->assertSet('step', 2)
        ->call('previousStep')
        ->assertSet('step', 1);
});

// ── Completion ────────────────────────────────────────

// smoke: core onboarding flow completes and redirects to dashboard
it('completes profile and redirects to dashboard', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'non-binary')
        ->set('pronouns', 'they/them')
        ->call('nextStep')
        ->set('phone', '+15551234567')
        ->call('nextStep')
        ->call('complete')
        ->assertRedirect(route('dashboard'));

    $fresh = $user->fresh();
    expect($fresh->profile_complete)->toBeTrue()
        ->and($fresh->gender)->toBe('non-binary')
        ->and($fresh->pronouns)->toBe('they/them')
        ->and($fresh->phone)->toBe('+15551234567')
        ->and($fresh->profile_version)->toBe(1)
        ->and($fresh->profile_updated_at)->not->toBeNull();
})->group('smoke');

it('syncs favorite game systems on completion', function () {
    $user = User::factory()->create(['profile_complete' => false]);
    $gs1 = GameSystem::create(['name' => 'D&D 5e', 'slug' => 'dnd-5e']);
    $gs2 = GameSystem::create(['name' => 'Pathfinder', 'slug' => 'pathfinder']);
    $gs3 = GameSystem::create(['name' => 'Call of Cthulhu', 'slug' => 'call-of-cthulhu']);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->set('favoriteGameSystemIds', [$gs1->id, $gs3->id])
        ->call('complete');

    $fresh = $user->fresh();
    expect($fresh->favoriteGameSystems->pluck('id')->sort()->values()->toArray())
        ->toBe([$gs1->id, $gs3->id]);
});

it('logs onboarding completion event', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Log::shouldReceive('info')
        ->atLeast()
        ->once()
        ->with('Onboarding completed', \Mockery::on(function ($context) use ($user) {
            return $context['user_id'] === $user->id
                && $context['gender'] === 'male'
                && $context['game_systems_count'] === 0
                && isset($context['location_source']);
        }));

    // Allow additional log calls from sync-dispatched discovery job
    Log::shouldReceive('info')->atLeast(0)->andReturn(null);
    Log::shouldReceive('debug')->atLeast(0)->andReturn(null);
    Log::shouldReceive('warning')->atLeast(0)->andReturn(null);
    Log::shouldReceive('error')->atLeast(0)->andReturn(null);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->call('complete');
});

it('validates all steps when completing from step 4 with invalid step 2 data', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    // Go through steps with valid data, then change step 2 to invalid
    $component = Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->assertSet('step', 4);

    // Manually clear gender (simulating tampered state)
    $component->set('gender', '')
        ->call('complete')
        ->assertHasErrors('gender');

    // Profile should NOT be complete
    expect($user->fresh()->profile_complete)->toBeFalse();
});

it('pre-fills existing user data from OAuth on mount', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'gender' => 'non-binary',
        'pronouns' => 'they/them',
    ]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->assertSet('gender', 'non-binary')
        ->assertSet('pronouns', 'they/them');
});

it('replaces game system preferences on re-sync', function () {
    $user = User::factory()->create(['profile_complete' => false]);
    $gs1 = GameSystem::create(['name' => 'D&D 5e', 'slug' => 'dnd-5e']);
    $gs2 = GameSystem::create(['name' => 'Pathfinder', 'slug' => 'pathfinder']);

    // First attach gs1
    $user->gameSystemPreferences()->attach($gs1->id, ['preference_type' => 'favorite']);

    // Now complete with gs2 only — gs1 should be removed
    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->set('favoriteGameSystemIds', [$gs2->id])
        ->call('complete');

    $fresh = $user->fresh();
    $prefIds = $fresh->favoriteGameSystems->pluck('id')->toArray();
    expect($prefIds)->toBe([$gs2->id]);
});

// ── Phone validation ─────────────────────────────────

it('validates phone max length on step 3', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'female')
        ->set('pronouns', 'she/her')
        ->call('nextStep')
        ->assertSet('step', 3)
        ->set('phone', str_repeat('1', 31))
        ->call('nextStep')
        ->assertHasErrors('phone')
        ->assertSet('step', 3);
});

it('accepts valid phone number on step 3', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->set('phone', '+1 (555) 123-4567')
        ->call('nextStep')
        ->assertSet('step', 4);
});

// ── Middleware: additional allowed routes for incomplete users ──

it('allows profile show route for incomplete user', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('profile.show'));

    $response->assertOk();
});

it('allows logout route for incomplete user', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('root'));
});

// ── Observability: profile completion funnel ──────────

it('logs profile version and updated_at on completion for funnel tracking', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'profile_version' => 0,
        'profile_updated_at' => null,
    ]);

    Log::shouldReceive('info')
        ->atLeast()
        ->once()
        ->with('Onboarding completed', \Mockery::on(function ($context) use ($user) {
            return $context['user_id'] === $user->id
                && isset($context['profile_version'])
                && $context['game_systems_count'] === 0
                && isset($context['location_source']);
        }));

    // Allow additional log calls from sync-dispatched discovery job
    Log::shouldReceive('info')->atLeast(0)->andReturn(null);
    Log::shouldReceive('debug')->atLeast(0)->andReturn(null);
    Log::shouldReceive('warning')->atLeast(0)->andReturn(null);
    Log::shouldReceive('error')->atLeast(0)->andReturn(null);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'female')
        ->set('pronouns', 'she/her')
        ->call('nextStep')
        ->call('nextStep')
        ->call('complete');

    $fresh = $user->fresh();
    expect($fresh->profile_version)->toBe(1);
    expect($fresh->profile_updated_at)->not->toBeNull();
});

// ── Game system validation (M2) ───────────────────────

it('rejects invalid game system IDs during onboarding', function () {
    $user = User::factory()->create(['profile_complete' => false]);
    $gs = GameSystem::create(['name' => 'D&D 5e', 'slug' => 'dnd-5e']);

    // Use a non-existent game system ID
    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->set('favoriteGameSystemIds', [$gs->id, \Illuminate\Support\Str::uuid()->toString()])
        ->call('complete')
        ->assertHasErrors(['favoriteGameSystemIds.1']);
});

it('rejects all non-existent game system IDs during onboarding', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->set('favoriteGameSystemIds', [\Illuminate\Support\Str::uuid()->toString(), \Illuminate\Support\Str::uuid()->toString()])
        ->call('complete')
        ->assertHasErrors(['favoriteGameSystemIds.0', 'favoriteGameSystemIds.1']);
});

it('accepts valid game system IDs during onboarding', function () {
    $user = User::factory()->create(['profile_complete' => false]);
    $gs1 = GameSystem::create(['name' => 'D&D 5e', 'slug' => 'dnd-5e']);
    $gs2 = GameSystem::create(['name' => 'Pathfinder', 'slug' => 'pathfinder']);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->set('favoriteGameSystemIds', [$gs1->id, $gs2->id])
        ->call('complete')
        ->assertRedirect(route('dashboard'));

    $fresh = $user->fresh();
    expect($fresh->favoriteGameSystems->pluck('id')->sort()->values()->toArray())
        ->toBe([$gs1->id, $gs2->id]);
});

// ── Location step: confirmLocation / editLocation ─────

it('confirms detected location', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('locationSource', 'localStorage')
        ->call('confirmLocation')
        ->assertSet('locationConfirmed', true);
});

it('enters edit mode from detected location', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('locationSource', 'localStorage')
        ->call('editLocation')
        ->assertSet('locationConfirmed', false)
        ->assertSet('showManualEntry', true);
});

it('edit location from confirmed state', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('editLocation')
        ->assertSet('locationConfirmed', false)
        ->assertSet('showManualEntry', true);
});

it('re-confirms location after editing', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->call('editLocation')
        ->set('city', 'Munich')
        ->set('lat', 48.14)
        ->set('lng', 11.58)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->assertSet('step', 2);
});

// ── Location: localStorage pre-population via onGuestLocationUpdated ─

it('reverse geocodes coordinates from onGuestLocationUpdated to pre-populate city', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Http::fake([
        '*nominatim*' => Http::response([
            'display_name' => 'Berlin, Germany',
            'address' => ['city' => 'Berlin', 'country' => 'Germany', 'country_code' => 'de'],
        ], 200),
    ]);

    Cache::flush();

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->assertSet('city', '')
        ->call('onGuestLocationUpdated', 52.52, 13.405, 'localStorage')
        ->assertSet('city', 'Berlin')
        ->assertSet('lat', 52.52)
        ->assertSet('lng', 13.405)
        ->assertSet('locationSource', 'localStorage');
});

it('does not overwrite manually entered city when guest location arrives', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Http::fake([
        '*nominatim*' => Http::response([
            'display_name' => 'Munich, Germany',
            'address' => ['city' => 'Munich', 'country' => 'Germany', 'country_code' => 'de'],
        ], 200),
    ]);

    Cache::flush();

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->call('onGuestLocationUpdated', 48.14, 11.58, 'localStorage')
        ->assertSet('city', 'Berlin'); // Should NOT be overwritten
});

it('does not overwrite confirmed location when guest location arrives', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Http::fake([
        '*nominatim*' => Http::response([
            'display_name' => 'Munich, Germany',
            'address' => ['city' => 'Munich', 'country' => 'Germany', 'country_code' => 'de'],
        ], 200),
    ]);

    Cache::flush();

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('onGuestLocationUpdated', 48.14, 11.58, 'localStorage')
        ->assertSet('city', 'Berlin')
        ->assertSet('locationConfirmed', true);
});

it('handles reverse geocoding failure gracefully during onGuestLocationUpdated', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    // Simulate geocoding returning null (API failure)
    Http::fake([
        '*nominatim*' => Http::response(['error' => 'Unable to geocode'], 500),
    ]);

    Cache::flush();

    // Should not throw, just leave city empty
    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->call('onGuestLocationUpdated', 52.52, 13.405, 'localStorage')
        ->assertSet('lat', 52.52)
        ->assertSet('lng', 13.405)
        ->assertSet('locationSource', 'localStorage')
        ->assertSet('city', ''); // City stays empty, user must enter manually
});

// ── Location: manual city entry with geocoding ────────

it('geocodes manually entered city and confirms location', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Http::fake([
        '*nominatim*' => Http::response([[
            'lat' => '48.8566',
            'lon' => '2.3522',
            'display_name' => 'Paris, France',
            'place_id' => 99999,
            'address' => ['city' => 'Paris', 'country' => 'France'],
        ]], 200),
    ]);

    Cache::flush();

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('showManualEntry', true)
        ->set('city', 'Paris')
        ->call('findMyLocation')
        ->assertSet('lat', 48.8566)
        ->assertSet('lng', 2.3522)
        ->assertSet('locationConfirmed', true)
        ->assertSet('locationSource', 'manual');
});

it('shows error when geocoding finds no results for city', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Http::fake([
        '*nominatim*' => Http::response([], 200),
    ]);

    Cache::flush();

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('showManualEntry', true)
        ->set('city', 'NonexistentCityXYZ123')
        ->call('findMyLocation')
        ->assertHasErrors('city')
        ->assertSet('locationConfirmed', false);
});

it('triggers browser geolocation when city is empty', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('showManualEntry', true)
        ->set('city', '')
        ->call('findMyLocation')
        ->assertSet('lat', null)     // JS didn't execute in test — no coordinates
        ->assertSet('locationConfirmed', false);
});

it('populates city from browser geolocation via handleBrowserLocation', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Http::fake([
        '*nominatim*' => Http::response([
            'address' => ['city' => 'Munich', 'country' => 'Germany'],
        ], 200),
    ]);

    Cache::flush();

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('showManualEntry', true)
        ->set('city', '')
        ->call('handleBrowserLocation', 48.1351, 11.5820)
        ->assertSet('city', 'Munich')
        ->assertSet('lat', 48.1351)
        ->assertSet('lng', 11.5820)
        ->assertSet('locationSource', 'localStorage')
        ->assertSet('locationConfirmed', false);
});

it('shows error when handleBrowserLocation cannot resolve a city', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Http::fake([
        '*nominatim*' => Http::response([], 200),
    ]);

    Cache::flush();

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('showManualEntry', true)
        ->call('handleBrowserLocation', 0.0, 0.0)
        ->assertHasErrors('city');
});

// ── Location: location_id created on profile completion ──

it('creates a Location record on profile completion with geocoded data', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Http::fake([
        '*nominatim*' => Http::response([[
            'lat' => '52.5200',
            'lon' => '13.4050',
            'display_name' => 'Berlin, Germany',
            'place_id' => 'onboarding-berlin-123',
            'address' => [
                'city' => 'Berlin',
                'country' => 'Germany', 'country_code' => 'de',
                'postcode' => '10115',
            ],
        ]], 200),
    ]);

    Cache::flush();

    Log::shouldReceive('info')->andReturn(null);
    Log::shouldReceive('debug')->andReturn(null);
    Log::shouldReceive('warning')->andReturn(null);
    Log::shouldReceive('error')->andReturn(null);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->call('complete');

    // User should have a location_id set
    $fresh = $user->fresh();
    expect($fresh->location_id)->not->toBeNull();

    // Location record should exist with correct data
    $location = Location::find($fresh->location_id);
    expect($location)->not->toBeNull()
        ->and($location->city)->toBe('Berlin')
        ->and($location->country)->toBe('DE')
        ->and($location->place_id)->toBe('onboarding-berlin-123')
        ->and($location->source)->toBe('onboarding');
});

it('reuses existing Location record when place_id matches during completion', function () {
    $existingLocation = Location::factory()->create([
        'name' => 'Berlin',
        'city' => 'Berlin',
        'country' => 'DE',
        'place_id' => 'shared-place-456',
        'latitude' => '52.5200000',
        'longitude' => '13.4050000',
    ]);

    $user = User::factory()->create(['profile_complete' => false]);

    Http::fake([
        '*nominatim*' => Http::response([[
            'lat' => '52.5200',
            'lon' => '13.4050',
            'display_name' => 'Berlin, Germany',
            'place_id' => 'shared-place-456',
            'address' => [
                'city' => 'Berlin',
                'country' => 'Germany', 'country_code' => 'de',
            ],
        ]], 200),
    ]);

    Cache::flush();

    Log::shouldReceive('info')->andReturn(null);
    Log::shouldReceive('debug')->andReturn(null);
    Log::shouldReceive('warning')->andReturn(null);
    Log::shouldReceive('error')->andReturn(null);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->call('complete');

    // Should reuse the existing location, not create a new one
    expect($user->fresh()->location_id)->toBe($existingLocation->id);
    expect(Location::count())->toBe(1);
});

it('pre-fills location from existing user location_id on mount', function () {
    $location = Location::factory()->create([
        'name' => 'Hamburg',
        'city' => 'Hamburg',
        'country' => 'DE',
        'latitude' => '53.5510000',
        'longitude' => '9.9930000',
    ]);
    $user = User::factory()->create([
        'profile_complete' => false,
        'location_id' => $location->id,
    ]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->assertSet('city', 'Hamburg')
        ->assertSet('lat', 53.551)
        ->assertSet('lng', 9.993)
        ->assertSet('locationConfirmed', true);
});

// ── Location: location_source logged during onboarding ──

it('logs location_source as localStorage when location came from browser', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Http::fake([
        '*nominatim*' => Http::response([[
            'lat' => '52.5200',
            'lon' => '13.4050',
            'display_name' => 'Berlin, Germany',
            'place_id' => 'log-test-789',
            'address' => ['city' => 'Berlin', 'country' => 'Germany', 'country_code' => 'de'],
        ]], 200),
    ]);

    Cache::flush();

    Log::shouldReceive('info')
        ->atLeast()
        ->once()
        ->with('Onboarding completed', \Mockery::on(function ($context) {
            return $context['location_source'] === 'localStorage';
        }));

    // Allow additional log calls from sync-dispatched discovery job
    Log::shouldReceive('info')->atLeast(0)->andReturn(null);
    Log::shouldReceive('debug')->atLeast(0)->andReturn(null);
    Log::shouldReceive('warning')->atLeast(0)->andReturn(null);
    Log::shouldReceive('error')->atLeast(0)->andReturn(null);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationSource', 'localStorage')
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->call('complete');
});

it('logs location_source as manual when location entered manually', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Http::fake([
        '*nominatim*' => Http::response([[
            'lat' => '48.1400',
            'lon' => '11.5800',
            'display_name' => 'Munich, Germany',
            'place_id' => 'manual-munich-012',
            'address' => ['city' => 'Munich', 'country' => 'Germany', 'country_code' => 'de'],
        ]], 200),
    ]);

    Cache::flush();

    Log::shouldReceive('info')
        ->atLeast()
        ->once()
        ->with('Onboarding completed', \Mockery::on(function ($context) {
            return $context['location_source'] === 'manual';
        }));

    // Allow additional log calls from sync-dispatched discovery job
    Log::shouldReceive('info')->atLeast(0)->andReturn(null);
    Log::shouldReceive('debug')->atLeast(0)->andReturn(null);
    Log::shouldReceive('warning')->atLeast(0)->andReturn(null);
    Log::shouldReceive('error')->atLeast(0)->andReturn(null);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Munich')
        ->set('lat', 48.14)
        ->set('lng', 11.58)
        ->set('locationSource', 'manual')
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'female')
        ->set('pronouns', 'she/her')
        ->call('nextStep')
        ->call('nextStep')
        ->call('complete');
});
