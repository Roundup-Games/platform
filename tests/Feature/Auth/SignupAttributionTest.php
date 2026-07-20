<?php

use App\Http\Middleware\CaptureFirstTouch;
use App\Models\LinkedAccount;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

// Write-once signup-attribution persistence (S02/T03).
//
// Pins the contract that the five signup-attribution columns on users
// (signup_oauth_provider, first_touch_referer_domain, first_touch_path,
// signup_content_type, signup_content_slug) are populated exactly once — at
// the User::create() call in each signup path — and are NEVER overwritten on a
// subsequent login. The derivations reuse the pure FirstTouch helpers so the
// persisted signal matches PostHogAnalytics::identifyFirstTouch byte for byte.
//
// Scenarios mirror the T03 verify contract:
//   (a) new Discord OAuth signup → all 5 columns set correctly
//   (b) new email signup          → signup_oauth_provider='email'
//   (c) existing OAuth login      → all 5 columns UNCHANGED (write-once holds)
//   (d) signup with no first-touch  -> the four first_touch / signup_content
//                                   columns are null but signup_oauth_provider
//                                   is still set.

// ── (a) New Discord OAuth signup → all 5 columns set correctly ───────────

it('persists all five signup-attribution columns on a new Discord OAuth signup', function () {
    // Simulate the CaptureFirstTouch middleware having recorded the landing
    // page + referer when the guest first arrived, plus Laravel's auth
    // middleware storing url.intended for the protected apply page they were
    // bounced from.
    $this->withSession([
        CaptureFirstTouch::PATH_KEY => '/en/discovery',
        CaptureFirstTouch::REFERER_KEY => 'https://google.com/search?q=dnd',
        CaptureFirstTouch::CAPTURED_KEY => true,
        'url.intended' => 'https://roundup.games/en/games/apply/curse-of-strahd',
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('123456789012345678');
    $socialiteUser->shouldReceive('getEmail')->andReturn('guildmaster@discord.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Guild Master');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/discord/callback');

    $this->assertDatabaseHas('users', [
        'email' => 'guildmaster@discord.com',
        'signup_oauth_provider' => 'discord',
        'first_touch_referer_domain' => 'google.com',
        'first_touch_path' => '/en/discovery',
        // Content context is derived from url.intended (the protected apply
        // page the guest was bounced from), NOT the entry path — matching
        // PostHogAnalytics::identifyFirstTouch's SEO content detection.
        'signup_content_type' => 'game',
        'signup_content_slug' => 'curse-of-strahd',
    ]);
})->group('smoke');

// ── (a-variant) Content context falls back to entry path when no intended URL ─

it('derives signup content context from the first-touch path when no url.intended is set', function () {
    $this->withSession([
        CaptureFirstTouch::PATH_KEY => '/en/campaigns/curse-of-strahd',
        CaptureFirstTouch::REFERER_KEY => 'https://google.com',
        CaptureFirstTouch::CAPTURED_KEY => true,
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('222222222222222222');
    $socialiteUser->shouldReceive('getEmail')->andReturn('player@discord.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Player');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/discord/callback');

    $this->assertDatabaseHas('users', [
        'email' => 'player@discord.com',
        'signup_oauth_provider' => 'discord',
        'first_touch_path' => '/en/campaigns/curse-of-strahd',
        'signup_content_type' => 'campaign',
        'signup_content_slug' => 'curse-of-strahd',
    ]);
});

// ── (a-variant) Google OAuth records the google provider ─────────────────

it('records the google provider on a new Google OAuth signup', function () {
    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('55555');
    $socialiteUser->shouldReceive('getEmail')->andReturn('new@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Google User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/google/callback');

    $this->assertDatabaseHas('users', [
        'email' => 'new@google.com',
        'signup_oauth_provider' => 'google',
    ]);
});

// ── (b) New email signup → signup_oauth_provider='email' ─────────────────

it('persists signup_oauth_provider=email on a new email registration', function () {
    $this->withSession([
        CaptureFirstTouch::PATH_KEY => '/en/games/dnd-5e-one-shot',
        CaptureFirstTouch::REFERER_KEY => 'https://reddit.com/r/dnd',
        CaptureFirstTouch::CAPTURED_KEY => true,
    ]);

    $response = $this->post('/en/register', [
        'name' => 'Email Signup',
        'email' => 'email@example.com',
        'password' => 'Sup3rSecret!pass',
        'password_confirmation' => 'Sup3rSecret!pass',
    ]);

    $this->assertDatabaseHas('users', [
        'email' => 'email@example.com',
        'signup_oauth_provider' => 'email',
        'first_touch_referer_domain' => 'reddit.com',
        'first_touch_path' => '/en/games/dnd-5e-one-shot',
        // No url.intended set → content context derived from the entry path.
        'signup_content_type' => 'game',
        'signup_content_slug' => 'dnd-5e-one-shot',
    ]);

    $response->assertRedirect(route('onboarding.index'));
})->group('smoke');

// ── (c) Existing user logging in via OAuth → all 5 columns UNCHANGED ──────

it('leaves the five signup-attribution columns unchanged when an existing user logs in via OAuth', function () {
    // A pre-existing user who already has attribution set from their original
    // signup. The write-once contract requires these values to survive every
    // subsequent login untouched.
    $user = User::factory()->create([
        'email' => 'returning@discord.com',
        'profile_complete' => true,
        'signup_oauth_provider' => 'discord',
        'first_touch_referer_domain' => 'original-referer.example.com',
        'first_touch_path' => '/en/original-landing',
        'signup_content_type' => 'campaign',
        'signup_content_slug' => 'original-campaign',
    ]);

    LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => '987654321098765432',
        'token' => 'old-token',
    ]);

    // The login request carries a DIFFERENT first-touch session — if the
    // write-once contract were broken, these new values would overwrite the
    // originals. Asserting they do not is the load-bearing check.
    $this->withSession([
        CaptureFirstTouch::PATH_KEY => '/en/different-landing',
        CaptureFirstTouch::REFERER_KEY => 'https://different-referer.example.com',
        CaptureFirstTouch::CAPTURED_KEY => true,
        'url.intended' => 'https://roundup.games/en/games/apply/different-game',
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('987654321098765432');
    $socialiteUser->shouldReceive('getEmail')->andReturn('returning@discord.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Returning');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new-token';
    $socialiteUser->refreshToken = null;
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/discord/callback');

    // Reload fresh from the DB — the original signup attribution must survive.
    $user->refresh();
    expect($user->signup_oauth_provider)->toBe('discord')
        ->and($user->first_touch_referer_domain)->toBe('original-referer.example.com')
        ->and($user->first_touch_path)->toBe('/en/original-landing')
        ->and($user->signup_content_type)->toBe('campaign')
        ->and($user->signup_content_slug)->toBe('original-campaign');
})->group('smoke');

// ── (c-variant) Existing user by email-match → columns UNCHANGED ──────────

it('leaves attribution columns unchanged when an existing user is matched by email on OAuth callback', function () {
    // The OAuthController also matches by email when no linked account exists —
    // that path must also honor write-once (it creates a linked account but
    // must not touch the user's attribution row).
    $user = User::factory()->create([
        'email' => 'matched@discord.com',
        'profile_complete' => true,
        'signup_oauth_provider' => 'email',
        'first_touch_referer_domain' => 'signup-day-referer.example.com',
        'first_touch_path' => '/en/signup-day-landing',
        'signup_content_type' => 'game',
        'signup_content_slug' => 'signup-day-game',
    ]);

    $this->withSession([
        CaptureFirstTouch::PATH_KEY => '/en/new-landing',
        CaptureFirstTouch::REFERER_KEY => 'https://new-referer.example.com',
        CaptureFirstTouch::CAPTURED_KEY => true,
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('777777777777777777');
    $socialiteUser->shouldReceive('getEmail')->andReturn('matched@discord.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Matched');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/discord/callback');

    $user->refresh();
    expect($user->signup_oauth_provider)->toBe('email')
        ->and($user->first_touch_referer_domain)->toBe('signup-day-referer.example.com')
        ->and($user->first_touch_path)->toBe('/en/signup-day-landing')
        ->and($user->signup_content_type)->toBe('game')
        ->and($user->signup_content_slug)->toBe('signup-day-game');
});

// ── (d) Signup with no captured first-touch → null fields, provider set ───

it('sets signup_oauth_provider but leaves first-touch and content fields null when no first-touch was captured', function () {
    // No CaptureFirstTouch session keys, no url.intended — a direct deep-link
    // straight to the auth flow. Provider must still be recorded; the other
    // four columns are nullable and should stay null.
    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('333333333333333333');
    $socialiteUser->shouldReceive('getEmail')->andReturn('direct@discord.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Direct');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/discord/callback');

    $user = User::where('email', 'direct@discord.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->signup_oauth_provider)->toBe('discord')
        ->and($user->first_touch_referer_domain)->toBeNull()
        ->and($user->first_touch_path)->toBeNull()
        ->and($user->signup_content_type)->toBeNull()
        ->and($user->signup_content_slug)->toBeNull();
});

it('sets signup_oauth_provider=email but leaves first-touch and content fields null on a direct email registration', function () {
    // No first-touch session, no url.intended — direct POST to /en/register.
    $this->post('/en/register', [
        'name' => 'Direct Email',
        'email' => 'direct-email@example.com',
        'password' => 'Sup3rSecret!pass',
        'password_confirmation' => 'Sup3rSecret!pass',
    ]);

    $user = User::where('email', 'direct-email@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->signup_oauth_provider)->toBe('email')
        ->and($user->first_touch_referer_domain)->toBeNull()
        ->and($user->first_touch_path)->toBeNull()
        ->and($user->signup_content_type)->toBeNull()
        ->and($user->signup_content_slug)->toBeNull();
});

// ── Write-once enforcement at the model boundary (M056 review follow-up) ──
//
// The six signup-attribution columns are documented as write-once but were
// only conventional — they sat in $fillable, so any update() across the
// codebase that accidentally threaded one of these keys would silently
// corrupt the S04 funnel / Signup Attribution Report. The User model now
// restores the original value (with a structured warning log) on any update
// that dirties one of these columns when the original is non-null.

describe('write-once enforcement at the model boundary', function () {
    it('restores the original signup_oauth_provider when an update attempts to overwrite it', function () {
        $user = User::factory()->create([
            'signup_oauth_provider' => 'discord',
            'first_touch_path' => '/en/games/foo',
        ]);

        $user->update([
            'signup_oauth_provider' => 'google',
            'name' => 'Renamed',
        ]);

        // The write-once column is preserved.
        expect($user->fresh()->signup_oauth_provider)->toBe('discord');
        // Non-write-once columns update normally.
        expect($user->fresh()->name)->toBe('Renamed');
    });

    it('protects all six write-once columns from accidental overwrite', function () {
        $user = User::factory()->create([
            'signup_oauth_provider' => 'email',
            'first_touch_referer_domain' => 'google.com',
            'first_touch_path' => '/en/register',
            'signup_content_type' => 'game',
            'signup_content_slug' => 'my-game',
        ]);

        $user->update([
            'signup_oauth_provider' => 'discord',
            'first_touch_referer_domain' => 'evil.com',
            'first_touch_path' => '/en/games/attacker',
            'signup_content_type' => 'campaign',
            'signup_content_slug' => 'attacker-campaign',
        ]);

        $fresh = $user->fresh();
        expect($fresh->signup_oauth_provider)->toBe('email')
            ->and($fresh->first_touch_referer_domain)->toBe('google.com')
            ->and($fresh->first_touch_path)->toBe('/en/register')
            ->and($fresh->signup_content_type)->toBe('game')
            ->and($fresh->signup_content_slug)->toBe('my-game');
    });

    it('allows a null original to be backfilled (legacy users pre-M056)', function () {
        // Legacy user — no attribution columns populated at signup.
        $user = User::factory()->create([
            'signup_oauth_provider' => null,
        ]);

        // A backfill script can still set the value once because the
        // original is null — the listener treats null as writable.
        $user->update(['signup_oauth_provider' => 'email']);
        expect($user->fresh()->signup_oauth_provider)->toBe('email');
    });

    it('does not interfere with unrelated profile updates (Profile\Show::saveProfile path)', function () {
        $user = User::factory()->create([
            'signup_oauth_provider' => 'discord',
            'name' => 'Original Name',
            'bio' => 'original bio',
        ]);

        $user->update([
            'name' => 'New Name',
            'bio' => 'new bio',
        ]);

        $fresh = $user->fresh();
        expect($fresh->name)->toBe('New Name')
            ->and($fresh->bio)->toBe('new bio')
            ->and($fresh->signup_oauth_provider)->toBe('discord');
    });
});
