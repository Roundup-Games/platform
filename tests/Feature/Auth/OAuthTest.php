<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

it('redirects to Google for authentication', function () {
    Socialite::shouldReceive('driver->redirect')
        ->once()
        ->andReturn(redirect('https://accounts.google.com/oauth/authorize'));

    $response = $this->get('/auth/google/redirect');

    $response->assertRedirect();
});

it('creates a new user from Google OAuth', function () {
    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getEmail')->andReturn('test@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Test User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn('https://avatar.url/photo.jpg');

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $this->assertDatabaseHas('users', [
        'email' => 'test@google.com',
        'name' => 'Test User',
        'avatar_url' => 'https://avatar.url/photo.jpg',
        'profile_complete' => false,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.index'));
});

it('logs in existing user via Google OAuth', function () {
    $user = User::factory()->create([
        'email' => 'existing@google.com',
        'profile_complete' => true,
    ]);

    $socialiteUser = Mockery::mock();
    $socialiteUser->shouldReceive('getEmail')->andReturn('existing@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Existing User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
});

it('rejects unsupported OAuth providers', function () {
    $response = $this->get('/auth/facebook/redirect');
    $response->assertRedirect(route('login'));
});

it('handles OAuth errors gracefully', function () {
    Socialite::shouldReceive('driver->user')
        ->once()
        ->andThrow(new \Exception('OAuth failed'));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('oauth');
});
