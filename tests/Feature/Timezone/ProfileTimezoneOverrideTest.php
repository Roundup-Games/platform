<?php

use App\Livewire\Profile\Show;
use App\Models\User;
use Livewire\Livewire;

//
// R089 — the manual override on the profile: picking a zone stores the
// canonicalized identifier and pins it (device capture stops overwriting),
// clearing the field returns the user to device-derived capture, and the
// alias-tolerant rule governs validation.
//

it('stores a manually chosen timezone and pins it against device capture', function () {
    $user = User::factory()->create(['timezone' => 'America/New_York']);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->set('timezone', 'Europe/Berlin')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($user->fresh()->timezone)->toBe('Europe/Berlin')
        ->and($user->fresh()->timezone_manually_set)->toBeTrue();
});

it('accepts a tzdata alias on the profile select and stores it as-is', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->set('timezone', 'Asia/Calcutta')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($user->fresh()->timezone)->toBe('Asia/Calcutta')
        ->and($user->fresh()->timezone_manually_set)->toBeTrue();
});

it('rejects unknown timezone identifiers', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->set('timezone', 'Mars/Olympus_Mons')
        ->call('saveProfile')
        ->assertHasErrors(['timezone']);
});

it('clearing the timezone returns the user to device-derived capture', function () {
    $user = User::factory()->create([
        'timezone' => 'Europe/Berlin',
        'timezone_manually_set' => true,
        'profile_complete' => true,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->set('timezone', '')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($user->fresh()->timezone)->toBeNull()
        ->and($user->fresh()->timezone_manually_set)->toBeFalse();

    // Device capture resumes: the next login writes again. Logout first —
    // actingAs() is still authenticated and the guest-gated login route
    // would redirect without authenticating.
    auth()->logout();

    $this->post('/en/login', [
        'email' => $user->email,
        'password' => 'password',
        'timezone' => 'America/New_York',
    ])->assertRedirect();

    expect($user->fresh()->timezone)->toBe('America/New_York')
        ->and($user->fresh()->timezone_manually_set)->toBeFalse();
});

it('shows the selected zone as an option even when it is a tzdata alias', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Calcutta']);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->assertSet('timezone', 'Asia/Calcutta')
        ->assertSeeHtml('<option value="Asia/Calcutta">Asia/Calcutta</option>');
});
