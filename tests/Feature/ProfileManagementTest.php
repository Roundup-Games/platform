<?php

use App\Livewire\Profile\Show;
use App\Models\GameSystem;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        ->get('/profile')
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

it('loads game systems list in render data', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    $component = Livewire::actingAs($user)->test(Show::class);

    $component->assertViewHas('gameSystems');
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
