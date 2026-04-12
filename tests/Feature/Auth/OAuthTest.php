<?php

use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ── Redirect ────────────────────────────────────────────

it('redirects to Google for authentication', function () {
    Socialite::shouldReceive('driver->redirect')
        ->once()
        ->andReturn(redirect('https://accounts.google.com/oauth/authorize'));

    $response = $this->get('/auth/google/redirect');

    $response->assertRedirect();
});

it('rejects unsupported OAuth providers on redirect', function () {
    $response = $this->get('/auth/facebook/redirect');
    $response->assertRedirect(route('login'));
});

it('rejects unsupported OAuth providers on callback', function () {
    $response = $this->get('/auth/facebook/callback');
    $response->assertRedirect(route('login'));
});

// ── New user registration via OAuth ─────────────────────

it('creates a new user and linked account from Google OAuth', function () {
    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn('12345');
    $socialiteUser->shouldReceive('getEmail')->andReturn('test@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Test User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn('https://avatar.url/photo.jpg');
    $socialiteUser->token = 'access-token';
    $socialiteUser->refreshToken = 'refresh-token';

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    // User created with correct attributes
    $this->assertDatabaseHas('users', [
        'email' => 'test@google.com',
        'name' => 'Test User',
        'avatar_url' => 'https://avatar.url/photo.jpg',
        'profile_complete' => false,
    ]);

    // Linked account created
    $user = User::where('email', 'test@google.com')->first();
    $this->assertDatabaseHas('linked_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => '12345',
        'token' => 'access-token',
        'refresh_token' => 'refresh-token',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.index'));
});

it('marks email as verified for OAuth registered users', function () {
    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn('99999');
    $socialiteUser->shouldReceive('getEmail')->andReturn('new@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('New User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/google/callback');

    $user = User::where('email', 'new@google.com')->first();
    expect($user->email_verified_at)->not->toBeNull();
});

// ── Existing user login via OAuth ───────────────────────

it('logs in existing user via linked account', function () {
    $user = User::factory()->create([
        'email' => 'existing@google.com',
        'profile_complete' => true,
    ]);

    LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => '67890',
        'token' => 'old-token',
    ]);

    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn('67890');
    $socialiteUser->shouldReceive('getEmail')->andReturn('existing@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Existing User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new-token';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $this->assertAuthenticatedAs($user);

    // Token refreshed
    $this->assertDatabaseHas('linked_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'token' => 'new-token',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
});

it('creates linked account when logging in existing user by email match', function () {
    $user = User::factory()->create([
        'email' => 'match@google.com',
        'profile_complete' => true,
    ]);

    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn('11111');
    $socialiteUser->shouldReceive('getEmail')->andReturn('match@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Match User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $this->assertAuthenticatedAs($user);

    // Linked account was created for this user
    $this->assertDatabaseHas('linked_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => '11111',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
});

it('updates avatar on OAuth login if user has none', function () {
    $user = User::factory()->create([
        'email' => 'noavatar@google.com',
        'avatar_url' => null,
        'profile_complete' => true,
    ]);

    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn('22222');
    $socialiteUser->shouldReceive('getEmail')->andReturn('noavatar@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Avatar User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn('https://avatar.url/me.jpg');
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/google/callback');

    expect($user->fresh()->avatar_url)->toBe('https://avatar.url/me.jpg');
});

it('does not overwrite existing avatar on OAuth login', function () {
    $user = User::factory()->create([
        'email' => 'hasavatar@google.com',
        'avatar_url' => 'https://existing.avatar/me.jpg',
        'profile_complete' => true,
    ]);

    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn('33333');
    $socialiteUser->shouldReceive('getEmail')->andReturn('hasavatar@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Has Avatar');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn('https://new.avatar/me.jpg');
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/google/callback');

    expect($user->fresh()->avatar_url)->toBe('https://existing.avatar/me.jpg');
});

// ── Account linking (logged-in user) ────────────────────

it('links a Google account to an authenticated user', function () {
    $user = User::factory()->create();

    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn('44444');
    $socialiteUser->shouldReceive('getEmail')->andReturn('linked@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Linked User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://google.com'));
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    // Start linking flow
    $this->actingAs($user)
        ->get('/auth/google/redirect');

    // Callback completes the link
    $response = $this->actingAs($user)
        ->get('/auth/google/callback');

    $this->assertDatabaseHas('linked_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => '44444',
    ]);

    $response->assertRedirect(route('profile.edit'));
    $response->assertSessionHas('status');
});

it('rejects linking a Google account already linked to another user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    LinkedAccount::create([
        'user_id' => $user2->id,
        'provider' => 'google',
        'provider_user_id' => '55555',
        'token' => 'tok',
    ]);

    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn('55555');
    $socialiteUser->shouldReceive('getEmail')->andReturn('taken@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Taken User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://google.com'));
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    // Start linking flow
    $this->actingAs($user1)
        ->get('/auth/google/redirect');

    // Callback should reject
    $response = $this->actingAs($user1)
        ->get('/auth/google/callback');

    // Should NOT be linked to user1
    $this->assertDatabaseMissing('linked_accounts', [
        'user_id' => $user1->id,
        'provider' => 'google',
        'provider_user_id' => '55555',
    ]);

    $response->assertRedirect(route('profile.edit'));
    $response->assertSessionHasErrors('oauth');
});

// ── Error handling ──────────────────────────────────────

it('handles OAuth errors gracefully', function () {
    Socialite::shouldReceive('driver->user')
        ->once()
        ->andThrow(new \Exception('OAuth failed'));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('oauth');
});

// ── Observability: event logging ────────────────────────

it('logs OAuth registration events', function () {
    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn('77777');
    $socialiteUser->shouldReceive('getEmail')->andReturn('log-new@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Log New');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context) => (
            $message === 'OAuth registration — new user created'
            && $context['provider'] === 'google'
        ));

    $this->get('/auth/google/callback');
});

it('logs OAuth login events', function () {
    $user = User::factory()->create([
        'email' => 'log-login@google.com',
        'profile_complete' => true,
    ]);

    LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => '88888',
        'token' => 'old',
    ]);

    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getId')->andReturn('88888');
    $socialiteUser->shouldReceive('getEmail')->andReturn('log-login@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Log Login');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context) => (
            $message === 'OAuth login via linked account'
            && $context['provider'] === 'google'
            && $context['user_id'] === $user->id
        ));

    $this->get('/auth/google/callback');
});

it('logs OAuth callback failures', function () {
    Socialite::shouldReceive('driver->user')
        ->once()
        ->andThrow(new \Exception('Token expired'));

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => (
            $message === 'OAuth callback failed'
            && $context['provider'] === 'google'
        ));

    // report() in the controller also triggers the error logger
    Log::shouldReceive('error')->once();

    $this->get('/auth/google/callback');
});
