<?php

use App\Enums\ContentLanguage;
use App\Livewire\Profile\Show;
use App\Models\GameSystem;
use App\Models\User;
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

// ── Language Display ──────────────────────────────────

it('loads preferred_language and location on mount', function () {
    $location = \App\Models\Location::factory()->create([
        'name' => 'Berlin',
        'city' => 'Berlin',
        'country' => 'DE',
    ]);
    $user = User::factory()->create([
        'profile_complete' => true,
        'preferred_language' => ContentLanguage::De,
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

    expect($user->fresh()->preferred_language)->toBe(ContentLanguage::De);
});

it('validates preferred_language must be a valid ContentLanguage value', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('preferredLanguage', 'invalid-lang')
        ->call('saveProfile')
        ->assertHasErrors(['preferredLanguage']);
});

// ── Bio Field ─────────────────────────────────────────

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
