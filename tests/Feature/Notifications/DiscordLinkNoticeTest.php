<?php

use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

// M080/S05 (D282 companion): the post-link default-shift notice. OAuthController
// shifts notification defaults (Discord DM on for actionable categories,
// urgency-tier email off) at TWO sites that call
// NotificationPreferenceSync::enableDiscordDefaultsFor — the authed-link
// completion path and linked-account creation (new-user + email-match Discord
// logins). Every site must flash the notice (rendered by the app layout) and
// log a discord_link.notice_shown impression next to
// notification.discord_auto_enabled.

// OAuthController fetches the Discord guild list via the Http facade on every
// Discord callback — stub it so no test makes a real network call (inert for
// the Google case, which never hits the URL).
beforeEach(function () {
    Http::fake([
        'https://discord.com/api/users/@me/guilds' => Http::response([]),
    ]);
});

function discordNoticeSocialiteUser(string $providerUserId, string $email): Laravel\Socialite\Two\User
{
    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn($providerUserId);
    $socialiteUser->shouldReceive('getEmail')->andReturn($email);
    $socialiteUser->shouldReceive('getName')->andReturn('Notice Test');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'discord-tok';
    $socialiteUser->refreshToken = null;

    return $socialiteUser;
}

// ── Authed-link completion path (linkAccount) ───────────────────────────────

it('flashes the default-shift notice on the authed Discord link path and logs the impression alongside the shift', function () {
    Log::spy();

    $user = User::factory()->create(['profile_complete' => true]);

    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://discord.com/oauth2/authorize'));
    Socialite::shouldReceive('driver->user')->andReturn(discordNoticeSocialiteUser('111111111111111111', 'linked@discord.com'));

    $this->actingAs($user)->get('/auth/discord/redirect');
    $response = $this->actingAs($user)->get('/auth/discord/callback');

    $response->assertSessionHas('discord_default_shift_notice', true);

    // The impression is logged alongside the shift itself (slice verification).
    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($user) {
            return $message === 'notification.discord_auto_enabled'
                && ($context['user_id'] ?? null) === $user->id;
        })
        ->once();

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($user) {
            return $message === 'discord_link.notice_shown'
                && ($context['user_id'] ?? null) === $user->id;
        })
        ->once();
});

it('renders the notice banner with the settings deep link on the post-link landing page (en)', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://discord.com/oauth2/authorize'));
    Socialite::shouldReceive('driver->user')->andReturn(discordNoticeSocialiteUser('222222222222222222', 'linked-en@discord.com'));

    $this->actingAs($user)->get('/auth/discord/redirect');
    $response = $this->actingAs($user)->get('/auth/discord/callback');

    $response->assertRedirect(route('profile.edit'));

    // Follow the redirect: flash data is present exactly for this request, so
    // the app layout must render the banner on the landing page.
    $landing = $this->actingAs($user)->get($response->headers->get('Location'));

    $landing->assertOk();
    // Wording-agnostic pin (K004): compare against the same trans call.
    $landing->assertSee(__('notifications.flash_discord_link_default_shift', [], 'en'), false);
    $landing->assertSee(__('notifications.action_discord_notification_settings', [], 'en'), false);
    // Deep link to the notification settings tab anchor.
    $landing->assertSee('/en/settings#notifications');

    // One-shot: a second load of the same page no longer shows the banner.
    $again = $this->actingAs($user)->get($response->headers->get('Location'));
    $again->assertDontSee('/en/settings#notifications');
});

it('renders the notice banner localized when the session locale is de', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://discord.com/oauth2/authorize'));
    Socialite::shouldReceive('driver->user')->andReturn(discordNoticeSocialiteUser('333333333333333333', 'linked-de@discord.com'));

    // localeUrl() and the landing page both follow the session locale.
    $this->actingAs($user)->withSession(['locale' => 'de'])->get('/auth/discord/redirect');
    $response = $this->actingAs($user)->withSession(['locale' => 'de'])->get('/auth/discord/callback');

    $response->assertRedirect('/de/profile/view');

    $landing = $this->actingAs($user)->get('/de/profile/view');

    $landing->assertOk();
    $landing->assertSee(__('notifications.flash_discord_link_default_shift', [], 'de'), false);
    $landing->assertSee(__('notifications.action_discord_notification_settings', [], 'de'), false);
    $landing->assertSee('/de/settings#notifications');
});

// ── Linked-account creation path (email-match + new-user Discord logins) ────

it('flashes the default-shift notice when an email-match Discord login creates the linked account', function () {
    Log::spy();

    $user = User::factory()->create([
        'email' => 'matched@discord.com',
        'profile_complete' => true,
    ]);

    Socialite::shouldReceive('driver->user')->andReturn(discordNoticeSocialiteUser('444444444444444444', 'matched@discord.com'));

    $response = $this->get('/auth/discord/callback');

    $this->assertAuthenticatedAs($user);
    $response->assertSessionHas('discord_default_shift_notice', true);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($user) {
            return $message === 'discord_link.notice_shown'
                && ($context['user_id'] ?? null) === $user->id;
        })
        ->once();
});

it('flashes the default-shift notice for a brand-new Discord signup', function () {
    $user = null;

    Socialite::shouldReceive('driver->user')->andReturn(discordNoticeSocialiteUser('555555555555555555', 'fresh@discord.com'));

    $response = $this->get('/auth/discord/callback');

    $user = User::where('email', 'fresh@discord.com')->first();
    expect($user)->not->toBeNull();
    expect(LinkedAccount::where('user_id', $user->id)->where('provider', 'discord')->exists())->toBeTrue();

    $response->assertSessionHas('discord_default_shift_notice', true);
});

// ── Negative: non-Discord providers never shift defaults ────────────────────

it('never flashes the notice when linking a non-Discord provider', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    $googleUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $googleUser->shouldReceive('getId')->andReturn('98765');
    $googleUser->shouldReceive('getEmail')->andReturn('google@link.com');
    $googleUser->shouldReceive('getName')->andReturn('Google User');
    $googleUser->shouldReceive('getNickname')->andReturn(null);
    $googleUser->shouldReceive('getAvatar')->andReturn(null);
    $googleUser->token = 'google-tok';
    $googleUser->refreshToken = null;

    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
    Socialite::shouldReceive('driver->user')->andReturn($googleUser);

    $this->actingAs($user)->get('/auth/google/redirect');
    $response = $this->actingAs($user)->get('/auth/google/callback');

    // The link itself succeeded — but no default shift, so no notice.
    $response->assertRedirect(route('profile.edit'));
    $response->assertSessionHas('status');
    $response->assertSessionMissing('discord_default_shift_notice');
});

// ── Copy resolves in both locales (wording-agnostic, K004) ──────────────────

it('resolves the notice copy keys in both locales', function () {
    foreach (['en', 'de'] as $locale) {
        foreach (['flash_discord_link_default_shift', 'action_discord_notification_settings'] as $key) {
            $resolved = __("notifications.{$key}", [], $locale);

            expect($resolved)->not->toBe("notifications.{$key}")
                ->and($resolved)->not->toBe('');
        }
    }
});
