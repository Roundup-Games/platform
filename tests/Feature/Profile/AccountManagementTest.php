<?php

use App\Livewire\Settings\Show as SettingsShow;
use App\Models\User;
use Livewire\Livewire;

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
