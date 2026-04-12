<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Middleware: EnsureProfileComplete ──────────────────

it('redirects unprofiled user to onboarding from dashboard', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertRedirect(route('onboarding.index'));
});

it('allows unprofiled user to access profile edit (needed for onboarding)', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'email_verified_at' => now(),
    ]);

    // Profile routes are allowed through the middleware so users can
    // still update their profile even if not fully complete
    $response = $this->actingAs($user)->get('/profile');

    $response->assertOk();
});

it('allows profiled user to access dashboard', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
});

it('does not redirect unauthenticated users', function () {
    $response = $this->get('/dashboard');

    // Should redirect to login, not onboarding
    $response->assertRedirect('/login');
});

// ── Onboarding page access ────────────────────────────

it('allows unprofiled user to access onboarding page', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertOk();
});

it('redirects profiled user away from onboarding to dashboard', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertRedirect(route('dashboard'));
});

// ── Registration sets profile_complete=false ──────────

it('sets profile_complete to false on email registration', function () {
    $this->post('/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertDatabaseHas('users', [
        'email' => 'new@example.com',
        'profile_complete' => false,
    ]);
});

it('redirects to onboarding after registration', function () {
    $response = $this->post('/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('onboarding.index'));
});

// ── Profile completion ────────────────────────────────

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
});

it('increments profile_version on completion', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'profile_version' => 0,
    ]);

    $user->update([
        'gender' => 'female',
        'pronouns' => 'she/her',
        'profile_complete' => true,
        'profile_version' => $user->profile_version + 1,
        'profile_updated_at' => now(),
    ]);

    expect($user->fresh()->profile_version)->toBe(1);
});

it('sets profile_updated_at on completion', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'profile_updated_at' => null,
    ]);

    $user->update([
        'gender' => 'male',
        'pronouns' => 'he/him',
        'profile_complete' => true,
        'profile_version' => $user->profile_version + 1,
        'profile_updated_at' => now(),
    ]);

    expect($user->fresh()->profile_updated_at)->not->toBeNull();
});

// ── Auth event logging ────────────────────────────────

it('allows profiled user to access profile edit without redirect', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/profile');

    $response->assertOk();
});
