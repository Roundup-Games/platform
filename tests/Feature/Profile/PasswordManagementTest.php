<?php

use App\Livewire\Settings\Show as SettingsShow;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

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
