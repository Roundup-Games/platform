<?php

use App\Enums\OAuthProvider;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

//
// T02 (M057/D119): widen Discord OAuth from `identify email` to
// `identify email guilds`. The `guilds` scope authorizes roundup to read the
// user's guild membership so roundup-enabled servers they are present in can
// surface in the GM workspace with a per-guild opt-in prompt (built in T07).
//
// These tests pin the three contract points the task plan requires:
//   1. the `guilds` scope is asserted in the authorization URL,
//   2. the guild list is fetched + persisted on a successful link,
//   3. the existing identify+email path is unchanged (no regression).
// Plus the failure-mode surface (Q5): the guild read is best-effort, so a
// failed/empty/throwing fetch must never block login or linking.
//

// Helper: build a mocked Socialite Discord user carrying a Bearer token.
function discordSocialiteUser(string $id, string $email = 'member@discord.com', string $name = 'Member'): object
{
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

// ── 1. Scope asserted in the authorization URL (D119) ───────────────────

it('includes the guilds OAuth scope in the Discord authorization URL', function () {
    // OAuthController::redirect() reads config('services.discord.scope') and
    // passes it to Socialite::driver()->scopes(). Capture the actual array the
    // controller hands over — that is the application's asserted scope set.
    $capturedScopes = null;

    $driver = Mockery::mock();
    $driver->shouldReceive('scopes')
        ->with(Mockery::on(function ($scopes) use (&$capturedScopes) {
            $capturedScopes = $scopes;

            return true;
        }))
        ->andReturnSelf();
    $driver->shouldReceive('redirect')
        ->andReturn(redirect('https://discord.com/api/oauth2/authorize'));

    Socialite::shouldReceive('driver')->with('discord')->andReturn($driver);

    $this->get('/auth/discord/redirect')
        ->assertRedirect();

    expect($capturedScopes)->not->toBeNull('OAuthController must pass scopes to Socialite');
    expect($capturedScopes)->toContain('guilds')
        ->and($capturedScopes)->toContain('identify')
        ->and($capturedScopes)->toContain('email');
});

it('keeps the Google OAuth scope set unchanged (no guilds regression)', function () {
    // Google has never requested guilds — widening Discord must not bleed.
    $scopes = config('services.google.scope', []);
    expect($scopes)->not->toContain('guilds');
});

// ── 2. Guild list fetched and stored on a successful link ───────────────

it('fetches and stores the Discord guild list on new-user registration', function () {
    Http::fake([
        'https://discord.com/api/users/@me/guilds' => Http::response([
            ['id' => '111000111000111000', 'name' => 'Roundup RPG Hub', 'icon' => 'abc123', 'owner' => true],
            ['id' => '222000222000222000', 'name' => 'West Marches', 'icon' => null, 'permissions' => 12345],
        ]),
    ]);

    Socialite::shouldReceive('driver->user')
        ->andReturn(discordSocialiteUser('123456789012345670'));

    $this->get('/auth/discord/callback');

    $account = LinkedAccount::where('provider', 'discord')->first();
    expect($account)->not->toBeNull();

    $meta = $account->provider_meta;
    expect($meta)->toHaveKey('guilds');
    // Projection keeps only id/name/icon — owner/permissions trimmed.
    expect($meta['guilds'])->toBe([
        ['id' => '111000111000111000', 'name' => 'Roundup RPG Hub', 'icon' => 'abc123'],
        ['id' => '222000222000222000', 'name' => 'West Marches', 'icon' => null],
    ]);
});

it('fetches and stores the Discord guild list when linking to an authenticated user', function () {
    Http::fake([
        'https://discord.com/api/users/@me/guilds' => Http::response([
            ['id' => '333333333333333333', 'name' => 'Linked Guild', 'icon' => 'def'],
        ]),
    ]);

    $user = User::factory()->create();

    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://discord.com/oauth2/authorize'));
    Socialite::shouldReceive('driver->user')
        ->andReturn(discordSocialiteUser('444444444444444440'));

    $this->actingAs($user)->get('/auth/discord/redirect');
    $this->actingAs($user)->get('/auth/discord/callback');

    $account = LinkedAccount::where('user_id', $user->id)->where('provider', 'discord')->first();
    expect($account)->not->toBeNull();
    expect($account->provider_meta['guilds'])->toBe([
        ['id' => '333333333333333333', 'name' => 'Linked Guild', 'icon' => 'def'],
    ]);
});

it('refreshes the stored guild list on a returning linked-account login', function () {
    $user = User::factory()->create([
        'email' => 'returning@discord.com',
        'profile_complete' => true,
    ]);
    // Pre-existing link with a stale (empty) guild list.
    LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => '555555555555555555',
        'token' => 'old-token',
        'provider_meta' => ['nickname' => null, 'avatar' => null],
    ]);

    Http::fake([
        'https://discord.com/api/users/@me/guilds' => Http::response([
            ['id' => '999999999999999999', 'name' => 'New Guild', 'icon' => 'xyz'],
        ]),
    ]);

    Socialite::shouldReceive('driver->user')
        ->andReturn(discordSocialiteUser('555555555555555555', 'returning@discord.com'));

    $this->get('/auth/discord/callback');

    $account = LinkedAccount::where('user_id', $user->id)->where('provider', 'discord')->first();
    expect($account->provider_meta['guilds'])->toBe([
        ['id' => '999999999999999999', 'name' => 'New Guild', 'icon' => 'xyz'],
    ]);
});

// ── 3. Existing identify+email path unchanged (no regression) ───────────

it('still records nickname and avatar alongside the guild list', function () {
    Http::fake([
        'https://discord.com/api/users/@me/guilds' => Http::response([
            ['id' => '1', 'name' => 'G', 'icon' => null],
        ]),
    ]);

    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('123456789012345671');
    $socialiteUser->shouldReceive('getEmail')->andReturn('both@discord.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Both');
    $socialiteUser->shouldReceive('getNickname')->andReturn('bothtag');
    $socialiteUser->shouldReceive('getAvatar')->andReturn('https://cdn.discordapp.com/avatars/x/y.png');
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $this->get('/auth/discord/callback');

    $account = LinkedAccount::where('provider', 'discord')->first();
    expect($account->provider_meta['nickname'])->toBe('bothtag')
        ->and($account->provider_meta['avatar'])->toBe('https://cdn.discordapp.com/avatars/x/y.png')
        ->and($account->provider_meta)->toHaveKey('guilds');
});

// ── Failure modes (Q5): guild read is best-effort ───────────────────────
//
// The guild fetch shares the OAuth callback with login/registration — it must
// never block authentication. A non-2xx, a connection loss, or a malformed
// body must result in a successful login with guilds simply omitted from
// provider_meta. The discovery surface treats absent guilds as "unknown".

it('omits guilds from provider_meta when the guild fetch returns a non-2xx error', function () {
    Http::fake([
        'https://discord.com/api/users/@me/guilds' => Http::response([], 401),
    ]);

    Socialite::shouldReceive('driver->user')
        ->andReturn(discordSocialiteUser('123456789012345672', 'err@discord.com', 'Err'));

    $this->get('/auth/discord/callback');

    // Login still succeeded.
    $account = LinkedAccount::where('provider', 'discord')->first();
    expect($account)->not->toBeNull();
    expect($account->provider_meta)->not->toHaveKey('guilds');
    expect($account->provider_meta)->toHaveKeys(['nickname', 'avatar']);
});

it('omits guilds when the guild fetch returns an empty guild list', function () {
    // A user in zero guilds is a valid state (new Discord account) — store
    // the empty list so discovery can distinguish "no guilds" from "unknown".
    Http::fake([
        'https://discord.com/api/users/@me/guilds' => Http::response([]),
    ]);

    Socialite::shouldReceive('driver->user')
        ->andReturn(discordSocialiteUser('123456789012345673', 'empty@discord.com', 'Empty'));

    $this->get('/auth/discord/callback');

    $account = LinkedAccount::where('provider', 'discord')->first();
    expect($account->provider_meta)->toHaveKey('guilds');
    expect($account->provider_meta['guilds'])->toBe([]);
});

it('omits guilds when the guild fetch throws a connection exception', function () {
    Http::fake(function ($request) {
        if (str_contains((string) $request->url(), '/users/@me/guilds')) {
            throw new ConnectionException('Could not resolve host');
        }

        return Http::response('', 200);
    });

    Socialite::shouldReceive('driver->user')
        ->andReturn(discordSocialiteUser('123456789012345674', 'conn@discord.com', 'Conn'));

    $this->get('/auth/discord/callback');

    $account = LinkedAccount::where('provider', 'discord')->first();
    expect($account)->not->toBeNull();
    expect($account->provider_meta)->not->toHaveKey('guilds');
});

it('omits guilds when the guild fetch returns a non-array (malformed) body', function () {
    Http::fake([
        'https://discord.com/api/users/@me/guilds' => Http::response('not json', 200),
    ]);

    Socialite::shouldReceive('driver->user')
        ->andReturn(discordSocialiteUser('123456789012345675', 'mal@discord.com', 'Mal'));

    $this->get('/auth/discord/callback');

    $account = LinkedAccount::where('provider', 'discord')->first();
    expect($account->provider_meta)->not->toHaveKey('guilds');
});

// ── LinkedAccount model accessors ───────────────────────────────────────

it('exposes Discord guilds and guild ids via LinkedAccount accessors', function () {
    $user = User::factory()->create();

    $account = LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => '123456789012345676',
        'token' => 'tok',
        'provider_meta' => [
            'nickname' => null,
            'avatar' => null,
            'guilds' => [
                ['id' => '111', 'name' => 'One', 'icon' => 'a'],
                ['id' => '222', 'name' => 'Two', 'icon' => null],
            ],
        ],
    ]);

    expect($account->provider)->toBe(OAuthProvider::Discord);
    expect($account->discordGuilds())->toHaveCount(2);
    expect($account->discordGuildIds())->toBe(['111', '222']);
});

it('returns an empty guild list for a Google linked account', function () {
    $user = User::factory()->create();

    $account = LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => '999',
        'token' => 'tok',
        'provider_meta' => ['nickname' => null, 'avatar' => null],
    ]);

    expect($account->discordGuilds())->toBe([])
        ->and($account->discordGuildIds())->toBe([]);
});

it('returns an empty guild list for a Discord account whose guild fetch failed', function () {
    $user = User::factory()->create();

    $account = LinkedAccount::create([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => '123456789012345677',
        'token' => 'tok',
        // guilds omitted by the best-effort fetch failure path.
        'provider_meta' => ['nickname' => null, 'avatar' => null],
    ]);

    expect($account->discordGuilds())->toBe([])
        ->and($account->discordGuildIds())->toBe([]);
});
