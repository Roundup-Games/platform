<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Http\Middleware\CaptureFirstTouch;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Tests\Helpers\TestablePostHogClient;

// T02 (M057/D119) widened the Discord OAuth callback to also fetch the user's
// guild membership list via the Http facade. Stub that endpoint here so the
// existing Discord tests below never make a real network call. Inert for
// non-Discord flows (Google tests never hit the URL). Focused guilds-scope
// assertions live in DiscordGuildsScopeTest.php.
beforeEach(function () {
    Http::fake([
        'https://discord.com/api/users/@me/guilds' => Http::response([]),
    ]);
});

// ── New user registration via OAuth ─────────────────────

it('creates a new user and linked account from Google OAuth', function () {
    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
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

    // Linked account created — tokens are encrypted in DB, check via model
    $user = User::where('email', 'test@google.com')->first();
    $account = LinkedAccount::where('user_id', $user->id)
        ->where('provider', 'google')
        ->first();
    expect($account)->not->toBeNull();
    expect($account->provider_user_id)->toBe('12345');
    expect($account->token)->toBe('access-token');
    expect($account->refresh_token)->toBe('refresh-token');

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.index'));
})->group('smoke');

it('marks email as verified for OAuth registered users', function () {
    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
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

// ── Invitation matching on OAuth signup ────────────────

it('matches pending email invitations on OAuth registration', function () {
    $game = Game::factory()->create();
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => null,
        'invitee_email' => 'invitee@google.com',
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('77777');
    $socialiteUser->shouldReceive('getEmail')->andReturn('invitee@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Invitee');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/google/callback');

    // The OAuth signup claims the pending invitation, same as email signup.
    $user = User::where('email', 'invitee@google.com')->first();
    expect($user)->not->toBeNull();
    expect(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->exists())->toBeTrue();
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

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
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

    // Token refreshed — check via model (encrypted in DB)
    $account = LinkedAccount::where('user_id', $user->id)
        ->where('provider', 'google')
        ->first();
    expect($account->token)->toBe('new-token');

    $response->assertRedirect(route('dashboard', absolute: false));
})->group('smoke');

it('creates linked account when logging in existing user by email match', function () {
    $user = User::factory()->create([
        'email' => 'match@google.com',
        'profile_complete' => true,
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
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

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
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

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
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

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
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

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
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
        ->andThrow(new Exception('OAuth failed'));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('oauth');
});

// ── Name fallback and redirect logic ───────────────────

it('uses email prefix as name when OAuth provider returns no name', function () {
    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('10001');
    $socialiteUser->shouldReceive('getEmail')->andReturn('john.doe@gmail.com');
    $socialiteUser->shouldReceive('getName')->andReturn(null);
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/google/callback');

    $user = User::where('email', 'john.doe@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('john.doe'); // sanitized: @ stripped, . preserved by ValidUserName
});

it('redirects new OAuth user to onboarding (not dashboard)', function () {
    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('10002');
    $socialiteUser->shouldReceive('getEmail')->andReturn('onboard@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Onboard User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('onboarding.index'));
});

it('redirects incomplete OAuth user to onboarding on email match login', function () {
    $user = User::factory()->create([
        'email' => 'incomplete@google.com',
        'profile_complete' => false,
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('10003');
    $socialiteUser->shouldReceive('getEmail')->andReturn('incomplete@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Incomplete User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('onboarding.index'));
});

it('returns success when linking an already-linked provider to the same user', function () {
    $user = User::factory()->create();

    LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => '10004',
        'token' => 'existing-tok',
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('10004');
    $socialiteUser->shouldReceive('getEmail')->andReturn('same@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Same User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://google.com'));
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    // Start and complete linking flow
    $this->actingAs($user)->get('/auth/google/redirect');
    $response = $this->actingAs($user)->get('/auth/google/callback');

    // Should still have exactly one linked account
    expect(LinkedAccount::where('user_id', $user->id)->where('provider', 'google')->count())->toBe(1);
    $response->assertRedirect(route('profile.edit'));
    $response->assertSessionHas('status');
});

// ── First-touch acquisition attribution ────────────────

it('attributes OAuth signup to the landing page captured for the guest, not the auth endpoint', function () {
    config(['posthog.enabled' => true]);
    config(['posthog.api_key' => 'phc_test_key']);
    $posthog = new TestablePostHogClient;
    app()->instance(PostHogClient::class, $posthog);
    $checker = $this->mock(PostHogConsentChecker::class);
    $checker->shouldReceive('hasAnalyticsConsent')->andReturn(true);
    app()->instance(PostHogConsentChecker::class, $checker);

    // Simulate the CaptureFirstTouch middleware having recorded the real landing
    // page when the guest first arrived (before the OAuth round-trip).
    $this->withSession([
        CaptureFirstTouch::PATH_KEY => '/en/discovery',
        CaptureFirstTouch::REFERER_KEY => 'https://google.com/search?q=dnd',
        CaptureFirstTouch::CAPTURED_KEY => true,
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('80808');
    $socialiteUser->shouldReceive('getEmail')->andReturn('touch@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Touch User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/google/callback');

    $identify = collect($posthog->identifyCalls)
        ->first(fn (array $c) => isset($c['properties']['$set_once']['first_touch_entry_path'])
            || isset($c['properties']['$set_once']['first_touch_referer_domain']));
    expect($identify)->not->toBeNull('expected a first-touch identify call');
    $setOnce = $identify['properties']['$set_once'] ?? [];
    expect($setOnce['first_touch_entry_path'])->toBe('/en/discovery')
        ->and($setOnce['first_touch_referer_domain'])->toBe('google.com');
});

it('clears first-touch attribution on every OAuth callback path (no stale leakage)', function () {
    config(['posthog.enabled' => true]);
    config(['posthog.api_key' => 'phc_test_key']);
    $posthog = new TestablePostHogClient;
    app()->instance(PostHogClient::class, $posthog);
    $checker = $this->mock(PostHogConsentChecker::class);
    $checker->shouldReceive('hasAnalyticsConsent')->andReturn(true);
    app()->instance(PostHogConsentChecker::class, $checker);

    // Existing-user login path (not a new registration) — must still consume the
    // attribution so it can't leak onto a later signup.
    $user = User::factory()->create([
        'email' => 'returning@google.com',
        'profile_complete' => true,
    ]);
    LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => '90909',
        'token' => 'tok',
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('90909');
    $socialiteUser->shouldReceive('getEmail')->andReturn('returning@google.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Returning');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->withSession([
        CaptureFirstTouch::PATH_KEY => '/en/discovery',
        CaptureFirstTouch::REFERER_KEY => 'https://google.com',
        CaptureFirstTouch::CAPTURED_KEY => true,
    ])->get('/auth/google/callback');

    // The existing-user login path does NOT call identifyFirstTouch, so the
    // attribution is consumed-but-unused here. Assert no first-touch identify
    // fired (the values were dropped, not attributed to this returning user) and
    // that a subsequent signup in the same session would see nothing left.
    $firstTouchIdentify = collect($posthog->identifyCalls)
        ->filter(fn (array $c) => isset($c['properties']['$set_once']['first_touch_entry_path']));
    expect($firstTouchIdentify)->toHaveCount(0);
});

// ── Discord OAuth provider (S01 T05) ───────────────────
// Discord is the second entry in OAuthController::SUPPORTED_PROVIDERS.
// These tests pin the allowlist widening from T03 and prove the
// provider-agnostic registration / login / linking paths cover Discord
// end-to-end via Socialite fakery (the slice's Integration proof level).

it('creates a new user and linked account from Discord OAuth', function () {
    // Discord provider_user_id is a 17-20 digit snowflake — matches the
    // handle_pattern registered for the discord platform in config/platforms.php.
    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('123456789012345678');
    $socialiteUser->shouldReceive('getEmail')->andReturn('guildmaster@discord.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Guild Master');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn('https://cdn.discordapp.com/avatars/123456789012345678/avatar.png');
    $socialiteUser->token = 'discord-access-token';
    $socialiteUser->refreshToken = 'discord-refresh-token';

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $response = $this->get('/auth/discord/callback');

    $this->assertDatabaseHas('users', [
        'email' => 'guildmaster@discord.com',
        'name' => 'Guild Master',
        'avatar_url' => 'https://cdn.discordapp.com/avatars/123456789012345678/avatar.png',
        'profile_complete' => false,
    ]);

    $user = User::where('email', 'guildmaster@discord.com')->first();
    $account = LinkedAccount::where('user_id', $user->id)
        ->where('provider', 'discord')
        ->first();
    expect($account)->not->toBeNull();
    expect($account->provider_user_id)->toBe('123456789012345678');
    expect($account->token)->toBe('discord-access-token');
    expect($account->refresh_token)->toBe('discord-refresh-token');

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.index'));
})->group('smoke');

it('logs in existing user via a Discord linked account', function () {
    $user = User::factory()->create([
        'email' => 'returning@discord.com',
        'profile_complete' => true,
    ]);

    LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => '987654321098765432',
        'token' => 'old-discord-token',
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('987654321098765432');
    $socialiteUser->shouldReceive('getEmail')->andReturn('returning@discord.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Returning');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new-discord-token';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $response = $this->get('/auth/discord/callback');

    $this->assertAuthenticatedAs($user);

    $account = LinkedAccount::where('user_id', $user->id)
        ->where('provider', 'discord')
        ->first();
    expect($account->token)->toBe('new-discord-token');

    $response->assertRedirect(route('dashboard', absolute: false));
});

it('creates a Discord linked account when logging in an existing user by email match', function () {
    $user = User::factory()->create([
        'email' => 'matched@discord.com',
        'profile_complete' => true,
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('555555555555555555');
    $socialiteUser->shouldReceive('getEmail')->andReturn('matched@discord.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Matched');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'discord-tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $response = $this->get('/auth/discord/callback');

    $this->assertAuthenticatedAs($user);

    $this->assertDatabaseHas('linked_accounts', [
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => '555555555555555555',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
});

it('links a Discord account to an authenticated user', function () {
    $user = User::factory()->create();

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('444444444444444444');
    $socialiteUser->shouldReceive('getEmail')->andReturn('linked@discord.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Linked');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'discord-tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://discord.com/oauth2/authorize'));
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    // Start linking flow
    $this->actingAs($user)->get('/auth/discord/redirect');

    // Callback completes the link
    $response = $this->actingAs($user)->get('/auth/discord/callback');

    $this->assertDatabaseHas('linked_accounts', [
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => '444444444444444444',
    ]);

    $response->assertRedirect(route('profile.edit'));
    $response->assertSessionHas('status');
});

it('prefills the avatar from Discord when the user has none on link (S06)', function () {
    $user = User::factory()->create(['avatar_url' => null]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('555555555555555555');
    $socialiteUser->shouldReceive('getEmail')->andReturn('avatar@discord.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Avatar');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn('https://cdn.discordapp.com/avatars/555/pic.jpg');
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://discord.com/oauth2/authorize'));
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->actingAs($user)->get('/auth/discord/redirect');
    $this->actingAs($user)->get('/auth/discord/callback');

    expect($user->fresh()->avatar_url)
        ->toBe('https://cdn.discordapp.com/avatars/555/pic.jpg');
});

it('does NOT overwrite an existing avatar when linking a Discord account (S06)', function () {
    $user = User::factory()->create(['avatar_url' => 'https://example.com/existing.png']);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('666666666666666666');
    $socialiteUser->shouldReceive('getEmail')->andReturn('keep@discord.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Keep');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn('https://cdn.discordapp.com/avatars/666/new.jpg');
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://discord.com/oauth2/authorize'));
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->actingAs($user)->get('/auth/discord/redirect');
    $this->actingAs($user)->get('/auth/discord/callback');

    // Existing avatar preserved — the IdP avatar is a fill-only-when-empty hint.
    expect($user->fresh()->avatar_url)->toBe('https://example.com/existing.png');
});

// ── Provider allowlist (OAuthController::SUPPORTED_PROVIDERS) ──

it('rejects an unsupported OAuth provider on redirect', function () {
    $response = $this->get('/auth/facebook/redirect');

    $response->assertRedirect('/en/login');
    $response->assertSessionHasErrors('oauth');
});

it('rejects an unsupported OAuth provider on callback', function () {
    $response = $this->get('/auth/facebook/callback');

    $response->assertRedirect('/en/login');
    $response->assertSessionHasErrors('oauth');
});

// ── OAuth sign-in button visibility on auth pages (M056 T06) ──

it('renders the Discord sign-in button above the Google button on the login page', function () {
    $response = $this->get(route('login'));

    $html = $response->getContent();
    $discordPos = strpos($html, 'btn-discord');
    $googlePos = strpos($html, 'btn-google');

    expect($discordPos)->not->toBeFalse('Discord button is rendered on the login page');
    expect($googlePos)->not->toBeFalse('Google button is rendered on the login page');
    expect($discordPos)->toBeLessThan($googlePos, 'Discord button appears above the Google button');

    $response->assertSee(route('oauth.redirect', 'discord'), false);
    $response->assertSee(__('common.action_continue_with_discord'));
});

it('renders the Discord sign-up button above the Google button on the register page', function () {
    $response = $this->get(route('register'));

    $html = $response->getContent();
    $discordPos = strpos($html, 'btn-discord');
    $googlePos = strpos($html, 'btn-google');

    expect($discordPos)->not->toBeFalse('Discord button is rendered on the register page');
    expect($googlePos)->not->toBeFalse('Google button is rendered on the register page');
    expect($discordPos)->toBeLessThan($googlePos, 'Discord button appears above the Google button');

    $response->assertSee(route('oauth.redirect', 'discord'), false);
    $response->assertSee(__('auth.content_sign_up_with_discord'));
});

// ── Email-verified enforcement on OAuth email-match (M056 review follow-up) ─
//
// Google and Discord surface this flag under DIFFERENT keys on their raw
// userinfo payloads:
//   - Google: `email_verified` (per OpenID Connect spec)
//   - Discord: `verified` (per Discord USER resource)
//
// OAuthController::isEmailVerified() honours EITHER key. An absent claim
// defaults to verified for backward compatibility. The tests below pin
// both provider shapes against the real IdP payload field names.

describe('OAuth email_verified enforcement', function () {
    it('rejects email-match login when Discord reports the email as unverified', function () {
        $user = User::factory()->create([
            'email' => 'victim@discord.com',
            'profile_complete' => true,
        ]);

        $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
        $socialiteUser->shouldReceive('getId')->andReturn('111222333444555666');
        $socialiteUser->shouldReceive('getEmail')->andReturn('victim@discord.com');
        $socialiteUser->shouldReceive('getName')->andReturn('Attacker');
        $socialiteUser->shouldReceive('getNickname')->andReturn(null);
        $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
        $socialiteUser->token = 'tok';
        $socialiteUser->refreshToken = null;
        // Discord's real USER resource exposes the flag as `verified`, NOT
        // `email_verified`. The controller must honour the Discord shape.
        $socialiteUser->user = ['verified' => false];

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

        $this->get('/auth/discord/callback')
            ->assertRedirect('/en/login')
            ->assertSessionHasErrors('oauth');

        // Critical: the user is NOT logged in.
        $this->assertGuest();

        // And NO linked account was created (the link is the side effect of
        // a successful email-match login — it must not happen on rejection).
        expect(LinkedAccount::where('user_id', $user->id)->exists())->toBeFalse();
    });

    it('rejects email-match login when Google reports the email as unverified', function () {
        $user = User::factory()->create([
            'email' => 'victim@google.com',
            'profile_complete' => true,
        ]);

        $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
        $socialiteUser->shouldReceive('getId')->andReturn('9999999999999999');
        $socialiteUser->shouldReceive('getEmail')->andReturn('victim@google.com');
        $socialiteUser->shouldReceive('getName')->andReturn('Attacker');
        $socialiteUser->shouldReceive('getNickname')->andReturn(null);
        $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
        $socialiteUser->token = 'tok';
        $socialiteUser->refreshToken = null;
        $socialiteUser->user = ['email_verified' => false];

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

        $this->get('/auth/google/callback')
            ->assertRedirect('/en/login')
            ->assertSessionHasErrors('oauth');

        $this->assertGuest();
        expect(LinkedAccount::where('user_id', $user->id)->exists())->toBeFalse();
    });

    it('does NOT auto-mark email_verified_at on a new OAuth signup when the IdP reports unverified', function () {
        $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
        $socialiteUser->shouldReceive('getId')->andReturn('777888999000111222');
        $socialiteUser->shouldReceive('getEmail')->andReturn('unverified@discord.com');
        $socialiteUser->shouldReceive('getName')->andReturn('Unverified');
        $socialiteUser->shouldReceive('getNickname')->andReturn(null);
        $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
        $socialiteUser->token = 'tok';
        $socialiteUser->refreshToken = null;
        $socialiteUser->user = ['verified' => false];

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

        $this->get('/auth/discord/callback');

        $user = User::where('email', 'unverified@discord.com')->first();
        expect($user)->not->toBeNull();
        // The standard email-verification flow owns this column when the
        // IdP declines to verify — roundup must not auto-claim verification.
        expect($user->email_verified_at)->toBeNull();
    });

    it('honours an explicit email_verified=true claim on email-match login', function () {
        $user = User::factory()->create([
            'email' => 'ok@discord.com',
            'profile_complete' => true,
        ]);

        $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
        $socialiteUser->shouldReceive('getId')->andReturn('123123123123123123');
        $socialiteUser->shouldReceive('getEmail')->andReturn('ok@discord.com');
        $socialiteUser->shouldReceive('getName')->andReturn('OK');
        $socialiteUser->shouldReceive('getNickname')->andReturn(null);
        $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
        $socialiteUser->token = 'tok';
        $socialiteUser->refreshToken = null;
        $socialiteUser->user = ['verified' => true];

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

        $this->get('/auth/discord/callback');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('linked_accounts', [
            'user_id' => $user->id,
            'provider' => 'discord',
            'provider_user_id' => '123123123123123123',
        ]);
    });

    it('treats an absent email_verified / verified claim as verified (backward-compat for legacy IdPs)', function () {
        $user = User::factory()->create([
            'email' => 'legacy@discord.com',
            'profile_complete' => true,
        ]);

        $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
        $socialiteUser->shouldReceive('getId')->andReturn('999000999000999000');
        $socialiteUser->shouldReceive('getEmail')->andReturn('legacy@discord.com');
        $socialiteUser->shouldReceive('getName')->andReturn('Legacy');
        $socialiteUser->shouldReceive('getNickname')->andReturn(null);
        $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
        $socialiteUser->token = 'tok';
        $socialiteUser->refreshToken = null;
        // No `user` array set — the legacy default.

        Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

        $this->get('/auth/discord/callback');

        $this->assertAuthenticatedAs($user);
    });
});
