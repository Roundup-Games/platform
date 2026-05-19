<?php

use App\Livewire\Profile\Show;
use App\Livewire\Settings\Show as SettingsShow;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;


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
        ->set('gender_consent', true)
        ->set('pronouns', 'they/them')
        ->set('phone', '+15559876543')
        ->call('saveProfile')
        ->assertSet('saved', true);

    $fresh = $user->fresh();
    expect($fresh)
        ->name->toBe('Updated Name')
        ->email->toBe('updated@example.com')
        ->gender->toBe('non-binary')
        ->gender_consent->toBeTrue()
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

it('resets email verification when email changes', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('email', 'newemail@example.com')
        ->call('saveProfile')
        ->assertHasNoErrors();

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

// ── Password Change: Users With Passwords ─────────────

it('can change password with correct current password', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(SettingsShow::class)
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
        ->test(SettingsShow::class)
        ->set('current_password', 'wrongpassword')
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'newpassword123')
        ->call('changePassword')
        ->assertHasErrors(['current_password']);
});

// ── Password Set: OAuth Users (No Password) ───────────

it('OAuth user can set a password without confirming current', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(SettingsShow::class)
        ->assertSet('userHasPassword', false)
        ->set('password', 'mynewpassword1')
        ->set('password_confirmation', 'mynewpassword1')
        ->call('changePassword');

    $fresh = $user->fresh();
    expect(Hash::check('mynewpassword1', $fresh->password))->toBeTrue();
    expect($fresh->password_set_at)->not->toBeNull();
});

it('OAuth user password set updates userHasPassword to true', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    $component = Livewire::actingAs($user)
        ->test(SettingsShow::class)
        ->assertSet('userHasPassword', false)
        ->set('password', 'mynewpassword1')
        ->set('password_confirmation', 'mynewpassword1')
        ->call('changePassword');

    $component->assertSet('userHasPassword', true);
});

// ── Account Deletion: Users With Passwords ────────────

it('user with password can anonymize account with correct password', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(SettingsShow::class)
        ->set('delete_password', 'password')
        ->call('deleteAccount');

    expect($user->fresh()->anonymized_at)->not->toBeNull();
    expect(auth()->check())->toBeFalse();
});

it('user with password cannot delete account with wrong password', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'password_set_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(SettingsShow::class)
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
        ->test(SettingsShow::class)
        ->set('delete_password', '')
        ->call('deleteAccount')
        ->assertHasErrors(['delete_password']);
});

// ── Account Deletion: OAuth Users (No Password) ───────

it('OAuth user can anonymize account by typing DELETE', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(SettingsShow::class)
        ->set('delete_confirmation', 'DELETE')
        ->call('deleteAccount');

    expect($user->fresh()->anonymized_at)->not->toBeNull();
    expect(auth()->check())->toBeFalse();
});

it('OAuth user cannot delete account with wrong confirmation text', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(SettingsShow::class)
        ->set('delete_confirmation', 'delete')
        ->call('deleteAccount')
        ->assertHasErrors(['delete_confirmation']);

    expect($user->fresh())->not->toBeNull();
});

it('OAuth user cannot delete account with empty confirmation', function () {
    $user = User::factory()->oauthUser()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(SettingsShow::class)
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

it('persists preferred_language on save', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'preferred_language' => null,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('preferredLanguage', 'de')
        ->call('saveProfile')
        ->assertHasNoErrors()
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
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    expect($user->fresh()->location_id)->toBe($location->id);
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
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($user->fresh()->location_id)->toBe($resolvedLocationId);
});
// ── User Model: location() relationship ───────────────

// ── Privacy Settings ──────────────────────────────────

describe('Privacy Settings', function () {
    it('loads default privacy settings on mount when none saved', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
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
            ->test(SettingsShow::class)
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
            ->test(SettingsShow::class)
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
            ->test(SettingsShow::class)
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
            ->test(SettingsShow::class)
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
            ->test(SettingsShow::class)
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
            ->test(SettingsShow::class)
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

    it('renders privacy settings section on the settings page', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        $this->actingAs($user)
            ->get(route('settings.show'))
            ->assertOk()
            ->assertSee('Privacy Settings')
            ->assertSee('Everyone')
            ->assertSee('Friends')
            ->assertSee('Nobody');
    });

    it('can save bio field', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('name', 'Valid Test Name')
            ->set('email', $user->email)
            ->set('bio', 'Hello, I am a tabletop gaming enthusiast!')
            ->call('saveProfile')
            ->assertSet('saved', true);

        expect($user->fresh()->bio)->toBe('Hello, I am a tabletop gaming enthusiast!');
    });

    it('strips HTML tags from bio on save', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('name', 'Valid Test Name')
            ->set('email', $user->email)
            ->set('bio', 'Hello <b>world</b> <i>italic</i>')
            ->call('saveProfile')
            ->assertSet('saved', true);

        expect($user->fresh()->bio)->toBe('Hello world italic');
    });

    it('rejects bio exceeding 500 characters', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('name', 'Valid Test Name')
            ->set('email', $user->email)
            ->set('bio', str_repeat('a', 501))
            ->call('saveProfile')
            ->assertHasErrors(['bio' => 'max']);
    });

    it('allows null bio to clear the field', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
            'bio' => 'Old bio text',
        ]);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('name', 'Valid Test Name')
            ->set('email', $user->email)
            ->set('bio', '')
            ->call('saveProfile')
            ->assertSet('saved', true);

        expect($user->fresh()->bio)->toBeNull();
    });

// ── Gender consent management (GDPR Art. 9) ──────────

it('stores gender as null when consent is not given on profile save', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
        'gender_consent' => false,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('gender', 'female')
        ->set('gender_consent', false)
        ->call('saveProfile');

    expect($user->fresh()->gender)->toBeNull()
        ->and($user->fresh()->gender_consent)->toBeFalse();
});

it('stores gender when consent is given on profile save', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
        'gender_consent' => false,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('gender', 'female')
        ->set('gender_consent', true)
        ->call('saveProfile');

    expect($user->fresh()->gender)->toBe('female')
        ->and($user->fresh()->gender_consent)->toBeTrue();
});

it('clears gender when consent is revoked on profile save', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
        'gender' => 'female',
        'gender_consent' => true,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('gender_consent', false)
        ->call('saveProfile');

    expect($user->fresh()->gender)->toBeNull()
        ->and($user->fresh()->gender_consent)->toBeFalse();
});
});

