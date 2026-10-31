<?php

use App\Models\LinkedAccount;
use App\Models\User;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

//
// R089 — device-timezone capture on the OAuth path: the login page decorates
// the provider buttons with ?timezone=…, redirect() stashes the canonicalized
// value in the session, and the login-completing callback applies the SAME
// capture contract as form login (no write over a manual override, no write
// when unchanged, invalid values silently dropped).
//

function oauthTzSocialiteUser(string $email, string $providerUserId): SocialiteUser
{
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn($providerUserId);
    $socialiteUser->shouldReceive('getEmail')->andReturn($email);
    $socialiteUser->shouldReceive('getName')->andReturn('OAuth Tz User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    return $socialiteUser;
}

/**
 * Full driver mock: redirect() chains scopes() (configured scope set) and
 * the callback calls user() — one mock owns the whole round-trip.
 */
function oauthTzDriver(?SocialiteUser $user = null): AbstractProvider
{
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('scopes')->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturn(redirect()->away('https://provider.test/auth'));
    if ($user !== null) {
        $driver->shouldReceive('user')->andReturn($user);
    }
    Socialite::shouldReceive('driver')->andReturn($driver);

    return $driver;
}

it('captures the stashed device timezone on OAuth registration', function () {
    oauthTzDriver(oauthTzSocialiteUser('oauth-tz-register@google.com', 'tz-register-1'));

    // Login page decorated the provider link with ?timezone=; the redirect
    // stashes it (canonicalized) for the round-trip.
    $this->get('/auth/google/redirect?timezone=America/New_York');
    expect(session('oauth_timezone'))->toBe('America/New_York');

    $this->get('/auth/google/callback');

    $user = User::where('email', 'oauth-tz-register@google.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->timezone)->toBe('America/New_York');
});

it('captures the stashed device timezone on OAuth login via a linked account', function () {
    $user = User::factory()->create([
        'email' => 'oauthtzlogin@google.com',
        'timezone' => null,
    ]);
    LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'tz-linked-1',
        'token' => 'tok',
        'refresh_token' => null,
    ]);

    oauthTzDriver(oauthTzSocialiteUser('oauthtzlogin@google.com', 'tz-linked-1'));

    $this->get('/auth/google/redirect?timezone=Asia/Calcutta');
    $this->get('/auth/google/callback');

    // Map-first alias resolution stores the canonical IANA target (R089).
    expect($user->fresh()->timezone)->toBe('Asia/Kolkata');
});

it('never overwrites a manually-set timezone on OAuth login', function () {
    $user = User::factory()->create([
        'email' => 'oauth-tz-manual@google.com',
        'timezone' => 'Europe/Berlin',
        'timezone_manually_set' => true,
        'profile_complete' => true,
    ]);
    LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'tz-manual-1',
        'token' => 'tok',
        'refresh_token' => null,
    ]);

    oauthTzDriver(oauthTzSocialiteUser('oauth-tz-manual@google.com', 'tz-manual-1'));

    $this->get('/auth/google/redirect?timezone=America/New_York');
    $this->get('/auth/google/callback');

    expect($user->fresh()->timezone)->toBe('Europe/Berlin')
        ->and($user->fresh()->timezone_manually_set)->toBeTrue();
});

it('drops an invalid stashed timezone silently', function () {
    oauthTzDriver(oauthTzSocialiteUser('oauth-tz-invalid@google.com', 'tz-invalid-1'));

    $this->get('/auth/google/redirect?timezone=Etc/Unknown');
    expect(session('oauth_timezone'))->toBeNull();

    $this->get('/auth/google/callback');

    $user = User::where('email', 'oauth-tz-invalid@google.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->timezone)->toBeNull();
});

it('stores nothing when the round-trip carried no timezone', function () {
    oauthTzDriver(oauthTzSocialiteUser('oauth-tz-none@google.com', 'tz-none-1'));

    $this->get('/auth/google/redirect');
    $this->get('/auth/google/callback');

    $user = User::where('email', 'oauth-tz-none@google.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->timezone)->toBeNull();
});
