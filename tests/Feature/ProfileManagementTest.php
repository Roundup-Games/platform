<?php

use App\Livewire\Profile\Edit;
use App\Livewire\Profile\Show;
use App\Models\GameSystem;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── Show Component ────────────────────────────────────

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
        ->call('saveProfile')
        ->assertSet('saved', true);

    expect($user->fresh())
        ->name->toBe('Updated Name')
        ->email->toBe('updated@example.com')
        ->gender->toBe('non-binary')
        ->pronouns->toBe('they/them');
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

it('validates required name and email', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
    ]);

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

it('can change password with correct current password', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', 'password')
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'newpassword123')
        ->call('changePassword');

    expect(Hash::check('newpassword123', $user->fresh()->password))->toBeTrue();
});

it('rejects password change with incorrect current password', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', 'wrongpassword')
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'newpassword123')
        ->call('changePassword')
        ->assertHasErrors(['current_password']);
});

it('validates new password confirmation', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', 'password')
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'different')
        ->call('changePassword')
        ->assertHasErrors(['password']);
});

it('logs profile update event', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
    ]);

    Log::shouldReceive('info')
        ->with('Profile updated', \Mockery::type('array'))
        ->once();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('name', 'New Name')
        ->call('saveProfile');
});

it('logs password change event', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
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

it('shows linked accounts in render data', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    LinkedAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-123',
    ]);

    $component = Livewire::actingAs($user)
        ->test(Show::class);

    $component->assertViewHas('linkedAccounts');
    $linkedAccounts = $component->viewData('linkedAccounts');
    expect($linkedAccounts)->toHaveCount(1);
    expect($linkedAccounts->first()->provider)->toBe('google');
});

it('shows game system preferences in render data', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    $gameSystem = GameSystem::factory()->create(['name' => 'D&D 5e']);
    $user->gameSystemPreferences()->attach($gameSystem, ['preference_type' => 'favorite']);

    $component = Livewire::actingAs($user)
        ->test(Show::class);

    $component->assertViewHas('gameSystemPreferences');
    $prefs = $component->viewData('gameSystemPreferences');
    expect($prefs)->toHaveCount(1);
});

// ── Edit Component ────────────────────────────────────

it('displays profile edit page', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/profile/edit')
        ->assertOk()
        ->assertSee('Edit Profile');
});

it('loads user data and game preferences on mount', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'gender' => 'male',
        'pronouns' => 'he/him',
    ]);
    $gameSystem = GameSystem::factory()->create();
    $user->gameSystemPreferences()->attach($gameSystem, ['preference_type' => 'favorite']);

    $component = Livewire::actingAs($user)
        ->test(Edit::class);

    $component->assertSet('gender', 'male')
        ->assertSet('pronouns', 'he/him');

    $ids = $component->get('favoriteGameSystemIds');
    expect($ids)->toContain($gameSystem->id);
});

it('can save profile with game preferences', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
    $gameSystem = GameSystem::factory()->create();

    Livewire::actingAs($user)
        ->test(Edit::class)
        ->set('name', 'Updated')
        ->set('favoriteGameSystemIds', [$gameSystem->id])
        ->call('save')
        ->assertSet('saved', true);

    expect($user->fresh()->gameSystemPreferences()->count())->toBe(1);
});

it('syncs game system preferences idempotently', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    $gs1 = GameSystem::factory()->create();
    $gs2 = GameSystem::factory()->create();

    // First save with gs1
    Livewire::actingAs($user)
        ->test(Edit::class)
        ->set('favoriteGameSystemIds', [$gs1->id])
        ->call('save');

    expect($user->fresh()->gameSystemPreferences()->pluck('game_systems.id')->toArray())->toContain($gs1->id);

    // Second save with gs2 (replaces gs1)
    Livewire::actingAs($user)
        ->test(Edit::class)
        ->set('favoriteGameSystemIds', [$gs2->id])
        ->call('save');

    $prefs = $user->fresh()->gameSystemPreferences()->pluck('game_systems.id')->toArray();
    expect($prefs)->toContain($gs2->id);
    expect($prefs)->not->toContain($gs1->id);
});

it('logs profile edit event', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Log::shouldReceive('info')
        ->with('Profile edited', \Mockery::type('array'))
        ->once();

    Livewire::actingAs($user)
        ->test(Edit::class)
        ->set('name', 'New Name')
        ->call('save');
});

// ── Avatar management ─────────────────────────────────

it('can remove avatar', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Log::shouldReceive('info')
        ->with('Avatar removed', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $user->id))
        ->once();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->call('removeAvatar');
});

// ── Password change: additional validation ────────────

it('validates new password minimum length', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', 'password')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('changePassword')
        ->assertHasErrors(['password']);
});

it('validates current password is required for password change', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('current_password', '')
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'newpassword123')
        ->call('changePassword')
        ->assertHasErrors(['current_password']);
});

it('logs warning on incorrect password change attempt', function () {
    $user = User::factory()->create(['profile_complete' => true]);

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

it('resets password form fields after successful change', function () {
    $user = User::factory()->create(['profile_complete' => true]);

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

// ── Profile show: observability ───────────────────────

it('logs email change event in profile show', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    Log::shouldReceive('info')
        ->with('Profile email changed', \Mockery::on(fn ($ctx) => (
            $ctx['user_id'] === $user->id && isset($ctx['new_email'])
        )))
        ->once();

    // Also expect the general profile update log
    Log::shouldReceive('info')
        ->with('Profile updated', \Mockery::type('array'))
        ->once();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('email', 'changed@example.com')
        ->call('saveProfile');
});

// ── Game system validation (M2) ───────────────────────

it('rejects invalid game system IDs on profile edit', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
    $gs = GameSystem::factory()->create();

    Livewire::actingAs($user)
        ->test(Edit::class)
        ->set('favoriteGameSystemIds', [$gs->id, 999999])
        ->call('save')
        ->assertHasErrors(['favoriteGameSystemIds.1']);
});

it('rejects all non-existent game system IDs on profile edit', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Edit::class)
        ->set('favoriteGameSystemIds', [888888, 999999])
        ->call('save')
        ->assertHasErrors(['favoriteGameSystemIds.0', 'favoriteGameSystemIds.1']);
});

it('accepts valid game system IDs on profile edit', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
    $gs1 = GameSystem::factory()->create();
    $gs2 = GameSystem::factory()->create();

    Livewire::actingAs($user)
        ->test(Edit::class)
        ->set('favoriteGameSystemIds', [$gs1->id, $gs2->id])
        ->call('save')
        ->assertSet('saved', true);

    $fresh = $user->fresh();
    expect($fresh->gameSystemPreferences()->pluck('game_systems.id')->sort()->values()->toArray())
        ->toBe([$gs1->id, $gs2->id]);
});
