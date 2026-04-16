<?php

use App\Livewire\Profile\Show;
use App\Models\GameSystem;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── Show Component: Page Rendering ────────────────────

it('displays profile show page', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSee('My Profile');
});

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

it('can save profile information with game preferences', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
    $gs = GameSystem::factory()->create();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('name', 'Updated Name')
        ->set('email', 'updated@example.com')
        ->set('gender', 'non-binary')
        ->set('pronouns', 'they/them')
        ->set('phone', '+15559876543')
        ->set('favoriteGameSystemIds', [$gs->id])
        ->call('saveProfile')
        ->assertSet('saved', true);

    $fresh = $user->fresh();
    expect($fresh)
        ->name->toBe('Updated Name')
        ->email->toBe('updated@example.com')
        ->gender->toBe('non-binary')
        ->pronouns->toBe('they/them')
        ->phone->toBe('+15559876543');

    expect($fresh->gameSystemPreferences()->pluck('game_systems.id')->toArray())->toContain($gs->id);
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
        ->call('saveProfile');

    expect($user->fresh()->gameSystemPreferences()->pluck('game_systems.id')->toArray())->toContain($gs1->id);

    // Second save with gs2 (replaces gs1)
    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('favoriteGameSystemIds', [$gs2->id])
        ->call('saveProfile');

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
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('favoriteGameSystemIds', [999999])
        ->call('saveProfile')
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
        'country' => 'Germany',
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
        'country' => 'Germany',
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

    Livewire::actingAs($user)
        ->test(Show::class)
        ->call('removeLocation')
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

it('validates locationSearch max length', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('locationSearch', str_repeat('a', 256))
        ->call('searchLocation')
        ->assertHasErrors(['locationSearch']);
});

// ── Location Search & Edit ────────────────────────────

it('displays current location from location_id relationship', function () {
    $location = \App\Models\Location::factory()->create([
        'name' => 'Tokyo',
        'city' => 'Tokyo',
        'country' => 'Japan',
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
        ->assertSet('locationEditing', false)
        ->assertSee('Add Location');
});

it('enters edit mode when editLocation called', function () {
    $location = \App\Models\Location::factory()->create([
        'name' => 'Paris',
        'city' => 'Paris',
        'country' => 'France',
    ]);
    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => $location->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->call('editLocation')
        ->assertSet('locationEditing', true)
        ->assertSet('locationSearch', '');
});

it('cancels edit mode when cancelEditLocation called', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('locationEditing', true)
        ->set('locationSearch', 'Berlin')
        ->call('cancelEditLocation')
        ->assertSet('locationEditing', false)
        ->assertSet('locationSearch', '');
});

it('removes location when removeLocation called', function () {
    $location = \App\Models\Location::factory()->create([
        'name' => 'Vienna',
        'city' => 'Vienna',
        'country' => 'Austria',
    ]);
    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => $location->id,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->call('removeLocation')
        ->assertSet('locationId', null)
        ->assertSet('locationPreview', null)
        ->assertSet('locationEditing', false)
        ->assertSet('locationSearch', '');
});

it('searches and resolves location via geocoding', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => null,
    ]);

    \Illuminate\Support\Facades\Http::fake([
        '*nominatim*' => \Illuminate\Support\Facades\Http::response([[
            'lat' => '52.5200',
            'lon' => '13.4050',
            'display_name' => 'Berlin, Germany',
            'place_id' => 12345,
            'address' => [
                'city' => 'Berlin',
                'country' => 'Germany',
                'postcode' => '10115',
            ],
        ]], 200),
    ]);

    \Illuminate\Support\Facades\Cache::flush();

    Log::shouldReceive('info')->andReturn(null);

    $component = Livewire::actingAs($user)
        ->test(Show::class)
        ->set('locationSearch', 'Berlin, Germany')
        ->call('searchLocation');

    // Component state should have a locationId set
    $locationId = $component->get('locationId');
    expect($locationId)->not->toBeNull();

    // Verify a Location record was created with correct data
    $location = \App\Models\Location::find($locationId);
    expect($location)->not->toBeNull();
    expect($location->city)->toBe('Berlin');
    expect($location->country)->toBe('Germany');

    // Persist via saveProfile
    $component->call('saveProfile');
    expect($user->fresh()->location_id)->toBe($locationId);
});

it('reuses existing location when place_id matches', function () {
    $existingLocation = \App\Models\Location::factory()->create([
        'name' => 'Berlin',
        'city' => 'Berlin',
        'country' => 'Germany',
        'place_id' => 'existing-place-123',
        'latitude' => '52.5200000',
        'longitude' => '13.4050000',
    ]);

    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => null,
    ]);

    \Illuminate\Support\Facades\Http::fake([
        '*nominatim*' => \Illuminate\Support\Facades\Http::response([[
            'lat' => '52.5200',
            'lon' => '13.4050',
            'display_name' => 'Berlin, Germany',
            'place_id' => 'existing-place-123',
            'address' => [
                'city' => 'Berlin',
                'country' => 'Germany',
            ],
        ]], 200),
    ]);

    \Illuminate\Support\Facades\Cache::flush();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('locationSearch', 'Berlin')
        ->call('searchLocation')
        ->assertSet('locationId', $existingLocation->id)
        ->call('saveProfile');

    // Should reuse the existing location, not create a new one
    expect($user->fresh()->location_id)->toBe($existingLocation->id);
    expect(\App\Models\Location::count())->toBe(1);
});

it('shows error when geocoding finds no results', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => null,
    ]);

    \Illuminate\Support\Facades\Http::fake([
        '*nominatim*' => \Illuminate\Support\Facades\Http::response([], 200),
    ]);

    \Illuminate\Support\Facades\Cache::flush();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('locationSearch', 'asdfghjkl nonexistent')
        ->call('searchLocation')
        ->assertHasErrors(['locationSearch']);
});

it('validates locationSearch is required for search', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('locationSearch', '')
        ->call('searchLocation')
        ->assertHasErrors(['locationSearch']);
});

it('displays location preview after successful search', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => null,
    ]);

    \Illuminate\Support\Facades\Http::fake([
        '*nominatim*' => \Illuminate\Support\Facades\Http::response([[
            'lat' => '48.8566',
            'lon' => '2.3522',
            'display_name' => 'Paris, France',
            'place_id' => 'paris-999',
            'address' => [
                'city' => 'Paris',
                'country' => 'France',
                'postcode' => '75001',
            ],
        ]], 200),
    ]);

    \Illuminate\Support\Facades\Cache::flush();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('locationSearch', 'Paris')
        ->call('searchLocation')
        ->assertSet('locationPreview', '75001 Paris, France');
});

// ── Location: edit and replace ────────────────────────

it('can edit location by searching for a new one', function () {
    $oldLocation = \App\Models\Location::factory()->create([
        'name' => 'Berlin',
        'city' => 'Berlin',
        'country' => 'Germany',
        'place_id' => 'old-berlin-place',
        'latitude' => '52.5200000',
        'longitude' => '13.4050000',
    ]);
    $user = User::factory()->create([
        'profile_complete' => true,
        'location_id' => $oldLocation->id,
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
                'postcode' => '75001',
            ],
        ]], 200),
    ]);

    Cache::flush();

    Log::shouldReceive('info')->andReturn(null);

    $component = Livewire::actingAs($user)
        ->test(Show::class)
        ->assertSet('locationId', $oldLocation->id)
        ->call('editLocation')
        ->assertSet('locationEditing', true)
        ->set('locationSearch', 'Paris')
        ->call('searchLocation')
        ->assertSet('locationEditing', false);

    // Verify the new locationId is different from the old one
    $newLocationId = $component->get('locationId');
    expect($newLocationId)->not->toBeNull()
        ->and($newLocationId)->not->toBe($oldLocation->id);

    // Save and verify persistence
    $component->call('saveProfile');
    expect($user->fresh()->location_id)->toBe($newLocationId);

    // Verify the new location record
    $newLocation = \App\Models\Location::find($newLocationId);
    expect($newLocation->city)->toBe('Paris')
        ->and($newLocation->country)->toBe('France');
});

it('searches and persists location_id in single component session', function () {
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
                'postcode' => '8001',
            ],
        ]], 200),
    ]);

    Cache::flush();

    Log::shouldReceive('info')->andReturn(null);

    $component = Livewire::actingAs($user)
        ->test(Show::class)
        ->assertSet('locationId', null)
        ->set('locationSearch', 'Zurich')
        ->call('searchLocation');

    $resolvedLocationId = $component->get('locationId');
    expect($resolvedLocationId)->not->toBeNull();

    // Save to persist
    $component->call('saveProfile');
    expect($user->fresh()->location_id)->toBe($resolvedLocationId);

    // Verify the Location record
    $location = \App\Models\Location::find($resolvedLocationId);
    expect($location->city)->toBe('Zurich')
        ->and($location->country)->toBe('Switzerland')
        ->and($location->source)->toBe('profile');
});

// ── User Model: location() relationship ───────────────

it('user linkedLocation() relationship returns the linked Location', function () {
    $location = \App\Models\Location::factory()->create([
        'name' => 'Test City',
        'city' => 'Test City',
        'country' => 'Test Country',
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
        'country' => 'Germany',
    ]);
    $user = User::factory()->create(['location_id' => $location->id]);

    expect($user->location_id)->toBe($location->id)
        ->and($user->linkedLocation->id)->toBe($location->id);
});
