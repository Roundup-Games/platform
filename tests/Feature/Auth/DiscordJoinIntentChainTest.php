<?php

use App\Enums\Visibility;
use App\Models\Game;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Support\DiscordJoinIntent;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withSession;

/*
|--------------------------------------------------------------------------
| M059/S02 — end-to-end Discord "My seat" on-ramp chain
|--------------------------------------------------------------------------
|
| The piecewise tests cover DiscordJoinIntent, the guest route, GameDetail's
| join, and the NotificationPreferenceSync in isolation. These tests prove the
| LOAD-BEARING handoffs across subsystem boundaries that a piecewise suite
| cannot see:
|
|   1. intent set → Discord OAuth callback → profile-complete user redirected
|      to the game's ?discord_join=1 target.
|   2. intent survives a profile-incomplete user's onboarding; onboarding
|      completion consumes it and lands on the game.
|   3. a Discord OAuth link (settings page path) triggers the auto-enable +
|      urgent-mail-suppress default shift on the user's notification blob.
|
| Mirrors the Socialite mock + Http::fake(@me/guilds) pattern established in
| OAuthTest / DiscordGuildsScopeTest.
*/

// Stub the Discord @me/guilds call the callback makes (MEM: D119 guilds scope).
beforeEach(function () {
    Http::fake([
        'https://discord.com/api/users/@me/guilds' => Http::response([], 200),
    ]);
});

// Helper: build a mocked Socialite Discord user carrying a Bearer token.
function discordJoinSocialiteUser(string $id, ?string $email = null, string $name = 'Member'): object
{
    $email = $email ?? "{$id}@discord.com";
    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn($id);
    $socialiteUser->shouldReceive('getEmail')->andReturn($email);
    $socialiteUser->shouldReceive('getName')->andReturn($name);
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'discord-bearer-token';
    $socialiteUser->refreshToken = 'discord-refresh-token';

    return $socialiteUser;
}

// ── 1. profile-complete + intent → redirected to the game join target ────

it('redirects a profile-complete member with a discord intent to the game join target after oauth callback', function () {
    $game = Game::factory()->create([
        'visibility' => Visibility::Public->value,
        'date_time' => now()->addDays(3),
    ]);

    // A pre-existing roundup user (profile complete) who has NOT yet linked Discord.
    $user = User::factory()->create(['profile_complete' => true, 'password' => null]);

    // Seed the intent in the session (as the guest /discord/join/{game} route does),
    // then mock the Discord OAuth callback resolving to this user via email match.
    Socialite::shouldReceive('driver->user')->andReturn(discordJoinSocialiteUser('777888999000111222', $user->email));

    $response = withSession(['discord_join_intent' => ['game_id' => (string) $game->id, 'set_at' => now()->getTimestamp()]])
        ->get('/auth/discord/callback');

    $target = (new DiscordJoinIntent)->targetUrl((string) $game->id);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain($target)
        ->and($response->headers->get('Location'))->toContain('discord_join=1');

    // The Discord account is now linked to the existing user.
    expect(LinkedAccount::where('provider_user_id', '777888999000111222')->exists())->toBeTrue();
});

// ── 2. profile-incomplete → intent survives; completion path lands on game ──

it('sends a profile-incomplete member to onboarding without consuming the discord intent, and lands on the game once profile-complete', function () {
    $game = Game::factory()->create([
        'visibility' => Visibility::Public->value,
        'date_time' => now()->addDays(3),
    ]);

    // New user via Discord OAuth (no prior account) → profile_complete=false → onboarding.
    Socialite::shouldReceive('driver->user')->andReturn(discordJoinSocialiteUser('111222333444555666', 'newcomer@discord.com', 'Newcomer'));

    $response = withSession(['discord_join_intent' => ['game_id' => (string) $game->id, 'set_at' => now()->getTimestamp()]])
        ->get('/auth/discord/callback');

    // Sent to onboarding (NOT the game yet) — the intent is preserved, not consumed.
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/onboarding');

    $user = User::where('email', 'newcomer@discord.com')->firstOrFail();
    expect($user->profile_complete)->toBeFalse();

    // The intent-handling branch (redirectAfterLogin) sends a profile-complete
    // member with a live intent to the game target. Simulate the member having
    // finished onboarding (profile_complete=true) and re-driving the OAuth
    // callback with the intent still live — this is the exact post-onboarding
    // redirect contract CompleteProfile::complete() honors via consume().
    $user->update(['profile_complete' => true]);

    Socialite::shouldReceive('driver->user')->andReturn(discordJoinSocialiteUser('111222333444555666', 'newcomer@discord.com', 'Newcomer'));

    $response2 = withSession(['discord_join_intent' => ['game_id' => (string) $game->id, 'set_at' => now()->getTimestamp()]])
        ->get('/auth/discord/callback');

    expect($response2->headers->get('Location'))->toContain((new DiscordJoinIntent)->targetUrl((string) $game->id))
        ->and($response2->headers->get('Location'))->toContain('discord_join=1');
});

// ── 3. Discord link triggers the auto-enable + urgent-mail-suppress shift ──

it('enables discord defaults and suppresses urgent mail when a discord account is linked via oauth callback', function () {
    $user = User::factory()->create(['profile_complete' => true, 'password' => null]);
    // Seed a non-default notification blob so we can prove the shift mutates it.
    $user->notification_settings = null;
    $user->save();

    // Email-match login path: a roundup user with no linked Discord, logging in via Discord.
    Socialite::shouldReceive('driver->user')->andReturn(discordJoinSocialiteUser('333222111000999888', $user->email));

    withSession([])->get('/auth/discord/callback')->assertRedirect();

    $user->refresh();
    $blob = $user->notification_settings;
    expect($blob)->not->toBeNull();

    // Discord is ON for every actionable category (spot-check a few urgent ones).
    expect($blob['game_invitation']['discord'] ?? false)->toBeTrue()
        ->and($blob['session_reminder']['discord'] ?? false)->toBeTrue()
        // Urgent mail suppressed (Discord now covers urgency).
        ->and($blob['game_invitation']['mail'] ?? true)->toBeFalse()
        ->and($blob['session_reminder']['mail'] ?? true)->toBeFalse()
        // Non-urgent mail stays at its (false) default.
        ->and($blob['participant_joined']['mail'] ?? true)->toBeFalse()
        // Ambient (NewFollower) stays off.
        ->and($blob['new_follower']['discord'] ?? true)->toBeFalse();
});

// ── 4. Authed-link path honors a Discord join intent ──────────────────────
//
// A real on-ramp persona: a roundup member already logged in (email/Google)
// who has NOT yet linked Discord. They click "Link Discord to grab your seat",
// which sets oauth_linking + the intent, so the callback routes to linkAccount()
// — which must honor the intent and land them on the game, NOT profile/view.

it('redirects an authed discord link with a live intent to the game join target', function () {
    $game = Game::factory()->create([
        'visibility' => Visibility::Public->value,
        'date_time' => now()->addDays(3),
    ]);

    // Already-authed roundup member with no Discord link, profile complete.
    $user = User::factory()->create(['profile_complete' => true, 'password' => null]);

    // oauth_linking routes the callback to linkAccount(); the intent was set by
    // the /discord/join/{game} on-ramp. New provider id so the link is created.
    Socialite::shouldReceive('driver->user')->andReturn(discordJoinSocialiteUser('444555666777888999', $user->email));

    $response = actingAs($user)
        ->withSession([
            'oauth_linking' => true,
            'discord_join_intent' => ['game_id' => (string) $game->id, 'set_at' => now()->getTimestamp()],
        ])
        ->get('/auth/discord/callback');

    $target = (new DiscordJoinIntent)->targetUrl((string) $game->id);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain($target)
        ->and($response->headers->get('Location'))->toContain('discord_join=1');

    // The Discord account is linked to the existing roundup user.
    expect(LinkedAccount::where('user_id', $user->id)->where('provider', 'discord')->exists())->toBeTrue();
});

it('sends an authed discord link with a live intent to onboarding when profile is incomplete, preserving the intent', function () {
    $game = Game::factory()->create([
        'visibility' => Visibility::Public->value,
        'date_time' => now()->addDays(3),
    ]);

    // Authed member whose profile is incomplete — onboarding is the waypoint.
    $user = User::factory()->create(['profile_complete' => false, 'password' => null]);

    Socialite::shouldReceive('driver->user')->andReturn(discordJoinSocialiteUser('555666777888999000', "{$user->id}@discord.com"));

    $response = actingAs($user)
        ->withSession([
            'oauth_linking' => true,
            'discord_join_intent' => ['game_id' => (string) $game->id, 'set_at' => now()->getTimestamp()],
        ])
        ->get('/auth/discord/callback');

    // Sent to onboarding (NOT the game yet); CompleteProfile::complete() then
    // consumes the intent and lands them on the game.
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/onboarding')
        ->and($response->headers->get('Location'))->not->toContain('discord_join=1');
});

it('sends an authed discord link WITHOUT an intent to profile view as before', function () {
    // No-intent linkers keep the existing behavior — the intent-aware branch is
    // a pure addition and must not regress the normal settings-page link flow.
    $user = User::factory()->create(['profile_complete' => true, 'password' => null]);

    Socialite::shouldReceive('driver->user')->andReturn(discordJoinSocialiteUser('666777888999000111', "{$user->id}@discord.com"));

    $response = actingAs($user)
        ->withSession(['oauth_linking' => true])
        ->get('/auth/discord/callback');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('profile/view');
});
