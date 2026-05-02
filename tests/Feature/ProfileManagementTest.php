<?php

use App\Livewire\Profile\Show;
use App\Models\GameSystem;
use App\Models\LinkedAccount;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── Show Component: Page Rendering ────────────────────

// smoke: profile show page renders for authenticated user
it('displays profile show page', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSee('My Profile');
})->group('smoke');

it('loads user data on mount', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'gender' => 'non-binary',
        'pronouns' => 'they/them',
        'phone' => '+15551234567',
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->assertSet('name', $user->name)
        ->assertSet('email', $user->email)
        ->assertSet('gender', 'non-binary')
        ->assertSet('pronouns', 'they/them')
        ->assertSet('phone', '+15551234567');
});

it('loads game system preferences on mount', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    $gs = GameSystem::factory()->create();
    $user->gameSystemPreferences()->attach($gs, ['preference_type' => 'favorite']);

    $component = Livewire::actingAs($user)->test(Show::class);

    expect($component->get('favoriteGameSystemIds'))->toContain($gs->id);
});

it('loads linked accounts in render data', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    LinkedAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-123',
    ]);

    $component = Livewire::actingAs($user)->test(Show::class);

    $component->assertViewHas('linkedAccounts');
    $linkedAccounts = $component->viewData('linkedAccounts');
    expect($linkedAccounts)->toHaveCount(1);
    expect($linkedAccounts->first()->provider)->toBe('google');
});

it('loads favorite and avoided game system IDs on mount', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    $component = Livewire::actingAs($user)->test(Show::class);

    $component
        ->assertSet('favoriteGameSystemIds', [])
        ->assertSet('avoidedGameSystemIds', []);
});

it('sets userHasPassword based on password_set_at', function () {
    // User with password
    $userWithPassword = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Livewire::actingAs($userWithPassword)
        ->test(Show::class)
        ->assertSet('userHasPassword', true);

    // OAuth user without password
    $oauthUser = User::factory()->oauthUser()->create(['profile_complete' => true]);

    Livewire::actingAs($oauthUser)
        ->test(Show::class)
        ->assertSet('userHasPassword', false);
});

// ── Profile Information Save ──────────────────────────

it('can save profile information', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('name', 'Updated Name')
        ->set('email', 'updated@example.com')
        ->set('gender', 'non-binary')
        ->set('pronouns', 'they/them')
        ->set('phone', '+15559876543')
        ->call('saveProfile')
        ->assertSet('saved', true);

    $fresh = $user->fresh();
    expect($fresh)
        ->name->toBe('Updated Name')
        ->email->toBe('updated@example.com')
        ->gender->toBe('non-binary')
        ->pronouns->toBe('they/them')
        ->phone->toBe('+15559876543');
});

it('can save game preferences independently', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
    ]);
    $gs = GameSystem::factory()->create();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('favoriteGameSystemIds', [$gs->id])
        ->call('savePreferences')
        ->assertSet('preferencesSaved', true);

    expect($user->fresh()->gameSystemPreferences()->pluck('game_systems.id')->toArray())->toContain($gs->id);
});

it('increments profile version on save', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'profile_version' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('name', 'New Name')
        ->call('saveProfile');

    expect($user->fresh()->profile_version)->toBe(2);
});

it('resets email verification when email changes', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('email', 'newemail@example.com')
        ->call('saveProfile');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('does not reset email verification when email unchanged', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('name', 'New Name')
        ->call('saveProfile');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('syncs game system preferences idempotently', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    $gs1 = GameSystem::factory()->create();
    $gs2 = GameSystem::factory()->create();

    // First save with gs1
    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('favoriteGameSystemIds', [$gs1->id])
        ->call('savePreferences');

    expect($user->fresh()->gameSystemPreferences()->pluck('game_systems.id')->toArray())->toContain($gs1->id);

    // Second save with gs2 (replaces gs1)
    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('favoriteGameSystemIds', [$gs2->id])
        ->call('savePreferences');

    $prefs = $user->fresh()->gameSystemPreferences()->pluck('game_systems.id')->toArray();
    expect($prefs)->toContain($gs2->id);
    expect($prefs)->not->toContain($gs1->id);
});

it('validates required name and email', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('name', '')
        ->call('saveProfile')
        ->assertHasErrors(['name' => 'required']);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('email', 'not-an-email')
        ->call('saveProfile')
        ->assertHasErrors(['email' => 'email']);
});

it('validates unique email', function () {
    $user1 = User::factory()->create(['profile_complete' => true]);
    $user2 = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($user2)
        ->test(Show::class)
        ->set('email', $user1->email)
        ->call('saveProfile')
        ->assertHasErrors(['email' => 'unique']);
});

it('validates game system IDs exist', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('favoriteGameSystemIds', [999999])
        ->call('savePreferences')
        ->assertHasErrors(['favoriteGameSystemIds.0']);
});

// ── Password Change: Users With Passwords ─────────────

it('can change password with correct current password', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', 'password')
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'newpassword123')
        ->call('changePassword');

    expect(Hash::check('newpassword123', $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->password_set_at)->not->toBeNull();
});

it('rejects password change with incorrect current password', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', 'wrongpassword')
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'newpassword123')
        ->call('changePassword')
        ->assertHasErrors(['current_password']);
});

it('validates new password minimum length', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', 'password')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('changePassword')
        ->assertHasErrors(['password']);
});

it('validates new password confirmation', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', 'password')
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'different')
        ->call('changePassword')
        ->assertHasErrors(['password']);
});

it('validates current password is required for password change', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', '')
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'newpassword123')
        ->call('changePassword')
        ->assertHasErrors(['current_password']);
});

it('resets password form fields after successful change', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    $component = Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', 'password')
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'newpassword123')
        ->set('showPasswordForm', true)
        ->call('changePassword');

    $component->assertSet('current_password', '')
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '')
        ->assertSet('showPasswordForm', false);
});

// ── Password Set: OAuth Users (No Password) ───────────

it('OAuth user can set a password without confirming current', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->assertSet('userHasPassword', false)
        ->set('password', 'mynewpassword1')
        ->set('password_confirmation', 'mynewpassword1')
        ->call('changePassword');

    $fresh = $user->fresh();
    expect(Hash::check('mynewpassword1', $fresh->password))->toBeTrue();
    expect($fresh->password_set_at)->not->toBeNull();
});

it('OAuth user does not see current_password validation when setting password', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    // Should succeed without setting current_password at all
    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('password', 'mynewpassword1')
        ->set('password_confirmation', 'mynewpassword1')
        ->call('changePassword')
        ->assertHasNoErrors();
});

it('OAuth user password set updates userHasPassword to true', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    $component = Livewire::actingAs($user)
        ->test(Show::class)
        ->assertSet('userHasPassword', false)
        ->set('password', 'mynewpassword1')
        ->set('password_confirmation', 'mynewpassword1')
        ->call('changePassword');

    $component->assertSet('userHasPassword', true);
});

it('OAuth user still needs password confirmation', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('password', 'mynewpassword1')
        ->set('password_confirmation', 'different')
        ->call('changePassword')
        ->assertHasErrors(['password']);
});

it('OAuth user still needs minimum password length', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('changePassword')
        ->assertHasErrors(['password']);
});

// ── Account Deletion: Users With Passwords ────────────

it('user with password can delete account with correct password', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('delete_password', 'password')
        ->call('deleteAccount');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

it('user with password cannot delete account with wrong password', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('delete_password', 'wrongpassword')
        ->call('deleteAccount')
        ->assertHasErrors(['delete_password']);

    expect($user->fresh())->not->toBeNull();
});

it('user with password must provide password to delete', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('delete_password', '')
        ->call('deleteAccount')
        ->assertHasErrors(['delete_password']);
});

// ── Account Deletion: OAuth Users (No Password) ───────

it('OAuth user can delete account by typing DELETE', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('delete_confirmation', 'DELETE')
        ->call('deleteAccount');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

it('OAuth user cannot delete account with wrong confirmation text', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('delete_confirmation', 'delete')
        ->call('deleteAccount')
        ->assertHasErrors(['delete_confirmation']);

    expect($user->fresh())->not->toBeNull();
});

it('OAuth user cannot delete account with empty confirmation', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('delete_confirmation', '')
        ->call('deleteAccount')
        ->assertHasErrors(['delete_confirmation']);
});

// ── Avatar Management ─────────────────────────────────

it('can remove avatar', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Log::shouldReceive('info')
        ->with('Avatar removed', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $user->id))
        ->once();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->call('removeAvatar');
});

// ── Observability ─────────────────────────────────────

it('logs profile update event', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Log::shouldReceive('info')
        ->with('Profile updated', \Mockery::type('array'))
        ->once();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('name', 'New Name')
        ->call('saveProfile');
});

it('logs email change event', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    Log::shouldReceive('info')
        ->with('Profile email changed', \Mockery::on(fn ($ctx) => (
            $ctx['user_id'] === $user->id && isset($ctx['new_email'])
        )))
        ->once();

    Log::shouldReceive('info')
        ->with('Profile updated', \Mockery::type('array'))
        ->once();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('email', 'changed@example.com')
        ->call('saveProfile');
});

it('logs password change event', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Log::shouldReceive('info')
        ->with('Password changed', \Mockery::type('array'))
        ->once();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', 'password')
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'newpassword123')
        ->call('changePassword');
});

it('logs warning on incorrect password change attempt', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Log::shouldReceive('warning')
        ->with('Password change failed: incorrect current password', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $user->id))
        ->once();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', 'wrongpassword')
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'newpassword123')
        ->call('changePassword');
});

it('logs account deletion event', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Log::shouldReceive('info')
        ->with('Account deletion initiated by user', \Mockery::type('array'))
        ->once();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('delete_password', 'password')
        ->call('deleteAccount');
});

// ── User Model: hasPasswordSet() ──────────────────────

it('hasPasswordSet returns true for registered user', function () {
    $user = User::factory()->create(['password_set_at' => now()]);

    expect($user->hasPasswordSet())->toBeTrue();
});

it('hasPasswordSet returns false for OAuth user', function () {
    $user = User::factory()->oauthUser()->create();

    expect($user->hasPasswordSet())->toBeFalse();
});

it('hasPasswordSet returns false when password_set_at is set but password is null', function () {
    $user = User::factory()->create([
        'password' => null,
        'password_set_at' => now(),
    ]);

    expect($user->hasPasswordSet())->toBeFalse();
});

// ── Language & Location ───────────────────────────────

it('loads preferred_language and location on mount', function () {
    $location = \App\Models\Location::factory()->create([
        'name' => 'Berlin',
        'city' => 'Berlin',
        'country' => 'DE',
    ]);
    $user = User::factory()->create([
        'profile_complete' => true,
        'preferred_language' => \App\Enums\ContentLanguage::De,
        'location_id' => $location->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->assertSet('preferredLanguage', 'de')
        ->assertSet('locationId', $location->id);
});

it('loads empty preferred_language and location when not set', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'preferred_language' => null,
        'location_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->assertSet('preferredLanguage', '')
        ->assertSet('locationId', null);
});

it('persists preferred_language on save', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'preferred_language' => null,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('preferredLanguage', 'de')
        ->call('saveProfile')
        ->assertSet('saved', true);

    expect($user->fresh()->preferred_language)->toBe(\App\Enums\ContentLanguage::De);
});

it('persists location_id on save', function () {
    $location = \App\Models\Location::factory()->create([
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
        ->assertSet('saved', true);

    expect($user->fresh()->location_id)->toBe($location->id);
});

it('sets preferred_language to null when empty string saved', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'preferred_language' => \App\Enums\ContentLanguage::En,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('preferredLanguage', '')
        ->call('saveProfile');

    expect($user->fresh()->preferred_language)->toBeNull();
});

it('sets location_id to null when location removed', function () {
    $location = \App\Models\Location::factory()->create([
        'name' => 'Old City',
        'city' => 'Old City',
    ]);
    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => $location->id,
    ]);

    // Remove location via the LocationPicker component
    Livewire::actingAs($user)
        ->test(\App\Livewire\Components\LocationPicker::class, ['locationId' => $location->id])
        ->call('removeLocation')
        ->assertDispatched('location-removed');

    // Verify profile save with null locationId clears it
    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('locationId', null)
        ->call('saveProfile');

    expect($user->fresh()->location_id)->toBeNull();
});

it('validates preferred_language must be a valid ContentLanguage value', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('preferredLanguage', 'invalid-lang')
        ->call('saveProfile')
        ->assertHasErrors(['preferredLanguage']);
});

it('validates city max length in LocationPicker', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(\App\Livewire\Components\LocationPicker::class, ['locationId' => null])
        ->set('city', str_repeat('a', 256))
        ->call('confirmLocation')
        ->assertHasErrors(['city']);
});

// ── Location Search & Edit ────────────────────────────

it('displays current location from location_id relationship', function () {
    $location = \App\Models\Location::factory()->create([
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
        ->assertSee('Add Location');
});

it('enters edit mode in LocationPicker when startEditing called', function () {
    $location = \App\Models\Location::factory()->create([
        'name' => 'Paris',
        'city' => 'Paris',
        'country' => 'FR',
    ]);

    Livewire::test(\App\Livewire\Components\LocationPicker::class, ['locationId' => $location->id])
        ->call('startEditing')
        ->assertSet('editing', true)
        ->assertSet('locationConfirmed', false);
});

it('cancels edit mode in LocationPicker when cancelEditing called', function () {
    $location = \App\Models\Location::factory()->create([
        'name' => 'Berlin',
        'city' => 'Berlin',
    ]);

    Livewire::test(\App\Livewire\Components\LocationPicker::class, ['locationId' => $location->id])
        ->call('startEditing')
        ->set('city', 'Munich')
        ->call('cancelEditing')
        ->assertSet('editing', false)
        ->assertSet('locationConfirmed', true);
});

it('removes location when removeLocation called in LocationPicker', function () {
    $location = \App\Models\Location::factory()->create([
        'name' => 'Vienna',
        'city' => 'Vienna',
        'country' => 'AT',
    ]);

    Livewire::test(\App\Livewire\Components\LocationPicker::class, ['locationId' => $location->id])
        ->call('removeLocation')
        ->assertSet('locationId', null)
        ->assertSet('editing', false)
        ->assertSet('locationConfirmed', false)
        ->assertSet('city', '')
        ->assertDispatched('location-removed');
});


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

    $component = Livewire::test(\App\Livewire\Components\LocationPicker::class, ['locationId' => null])
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

    Livewire::test(\App\Livewire\Components\LocationPicker::class, ['locationId' => null])
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

    Livewire::test(\App\Livewire\Components\LocationPicker::class, ['locationId' => null])
        ->set('city', 'asdfghjkl nonexistent')
        ->call('findMyLocation')
        ->assertHasErrors(['city']);
});

it('validates city is required for confirm in LocationPicker', function () {
    Livewire::test(\App\Livewire\Components\LocationPicker::class, ['locationId' => null])
        ->set('city', '')
        ->call('confirmLocation')
        ->assertHasErrors(['city']);
});

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

    Livewire::test(\App\Livewire\Components\LocationPicker::class, ['locationId' => null])
        ->set('city', 'Paris')
        ->call('findMyLocation')
        ->assertSet('locationConfirmed', true)
        ->assertSet('city', 'Paris');
});

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

    $component = Livewire::test(\App\Livewire\Components\LocationPicker::class, ['locationId' => $oldLocation->id])
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
    $picker = Livewire::test(\App\Livewire\Components\LocationPicker::class, ['locationId' => null])
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
        ->call('saveProfile');

    expect($user->fresh()->location_id)->toBe($resolvedLocationId);
});
// ── User Model: location() relationship ───────────────

it('user linkedLocation() relationship returns the linked Location', function () {
    $location = \App\Models\Location::factory()->create([
        'name' => 'Test City',
        'city' => 'Test City',
        'country' => 'XX',
    ]);
    $user = User::factory()->create([
        'location_id' => $location->id,
    ]);

    $userLocation = $user->linkedLocation;
    expect($userLocation)->not->toBeNull()
        ->and($userLocation->id)->toBe($location->id)
        ->and($userLocation->city)->toBe('Test City');
});

it('user linkedLocation() returns null when no location_id set', function () {
    $user = User::factory()->create(['location_id' => null]);

    expect($user->linkedLocation)->toBeNull();
});

it('user location_id is accessible via direct attribute', function () {
    $location = \App\Models\Location::factory()->create([
        'city' => 'Dresden',
        'country' => 'DE',
    ]);
    $user = User::factory()->create(['location_id' => $location->id]);

    expect($user->location_id)->toBe($location->id)
        ->and($user->linkedLocation->id)->toBe($location->id);
});

// ── Privacy Settings ──────────────────────────────────

describe('Privacy Settings', function () {
    it('loads default privacy settings on mount when none saved', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->assertSet('privacySettings', [
                'location' => 'everyone',
                'game_systems' => 'friends',
                'vibes' => 'friends',
                'campaigns' => 'friends',
                'teams' => 'friends',
                'friends_list' => 'friends',
                'stats' => 'friends',
            ]);
    });

    it('loads existing privacy settings on mount', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => [
                'location' => 'nobody',
                'game_systems' => 'friends',
                'vibes' => 'everyone',
                'campaigns' => 'nobody',
                'teams' => 'friends',
                'friends_list' => 'nobody',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->assertSet('privacySettings.location', 'nobody')
            ->assertSet('privacySettings.game_systems', 'friends')
            ->assertSet('privacySettings.vibes', 'everyone')
            ->assertSet('privacySettings.campaigns', 'nobody')
            ->assertSet('privacySettings.teams', 'friends')
            ->assertSet('privacySettings.friends_list', 'nobody');
    });

    it('fills missing fields with defaults when partial settings saved', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => [
                'location' => 'nobody',
                'friends_list' => 'friends',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->assertSet('privacySettings.location', 'nobody')
            ->assertSet('privacySettings.friends_list', 'friends')
            // Fields not in the stored settings should default to 'friends' (location → 'everyone')
            ->assertSet('privacySettings.game_systems', 'friends')
            ->assertSet('privacySettings.vibes', 'friends')
            ->assertSet('privacySettings.campaigns', 'friends')
            ->assertSet('privacySettings.teams', 'friends');
    });

    it('saves privacy settings to user model', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        $newSettings = [
            'location' => 'nobody',
            'game_systems' => 'friends',
            'vibes' => 'everyone',
            'campaigns' => 'nobody',
            'teams' => 'friends',
            'friends_list' => 'nobody',
            'stats' => 'nobody',
        ];

        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('privacySettings', $newSettings)
            ->call('savePrivacySettings')
            ->assertSet('privacySaved', true);

        expect($user->fresh()->privacy_settings)->toBe($newSettings);
    });

    it('logs privacy settings update', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        Log::shouldReceive('info')
            ->once()
            ->with('Privacy settings updated', \Mockery::on(function ($ctx) use ($user) {
                return $ctx['user_id'] === $user->id
                    && isset($ctx['settings']);
            }));

        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('privacySettings', [
                'location' => 'nobody',
                'game_systems' => 'friends',
                'vibes' => 'everyone',
                'campaigns' => 'nobody',
                'teams' => 'friends',
                'friends_list' => 'nobody',
            ])
            ->call('savePrivacySettings');
    });

    it('validates privacy setting values', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('privacySettings', [
                'location' => 'invalid_value',
                'game_systems' => 'friends',
                'vibes' => 'everyone',
                'campaigns' => 'nobody',
                'teams' => 'friends',
                'friends_list' => 'nobody',
            ])
            ->call('savePrivacySettings')
            ->assertHasErrors(['privacySettings.location']);
    });

    it('ignores unknown field keys on save', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('privacySettings', [
                'location' => 'everyone',
                'game_systems' => 'friends',
                'vibes' => 'everyone',
                'campaigns' => 'nobody',
                'teams' => 'friends',
                'friends_list' => 'nobody',
                'stats' => 'nobody',
                'unknown_field' => 'everyone',
            ])
            ->call('savePrivacySettings');

        $saved = $user->fresh()->privacy_settings;
        // Only known fields should be persisted
        expect(array_keys($saved))->toBe([
            'location', 'game_systems', 'vibes', 'campaigns', 'teams', 'friends_list', 'stats',
        ]);
    });

    it('renders privacy settings section on the profile page', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Privacy Settings')
            ->assertSee('Everyone')
            ->assertSee('Friends')
            ->assertSee('Nobody');
    });
});

