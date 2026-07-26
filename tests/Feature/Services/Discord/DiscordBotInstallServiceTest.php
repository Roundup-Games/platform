<?php

namespace Tests\Feature\Services\Discord;

use App\Exceptions\DiscordBotInstallException;
use App\Models\DiscordGuild;
use App\Models\User;
use App\Services\Discord\DiscordBotInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression coverage for the M057 bot-install OAuth callback.
 *
 * Security contract (post-review): the callback `guild_id` is attacker-
 * controllable, so recording the installer as a guild's roundup owner requires
 * proving they actually administer that guild. The install scopes include
 * `identify guilds`, which makes the code exchange return a USER access_token;
 * that token authorizes a /users/@me/guilds lookup that must show the installer
 * owns or holds MANAGE_GUILD on the claimed guild. The PKCE verifier binds the
 * code to the install session; the state token binds the callback to the
 * landlord who started it.
 *
 * Discord's standard bot-install flow returns the chosen `guild_id` on the
 * CALLBACK URL (NOT in the token body), so the service still reads it from the
 * callback argument — it just no longer trusts it without verification.
 */
class DiscordBotInstallServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://discord.com/api/oauth2/token';

    private const BASE_URL = 'https://discord.com/api/v10';

    private const GUILD_ID = '1529766387369775144';

    private function makeService(): DiscordBotInstallService
    {
        return new DiscordBotInstallService(
            baseUrl: self::BASE_URL,
            botToken: 'test-bot-token',
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            redirectUri: 'https://roundup.example/discord/install/callback',
        );
    }

    /** A landlord who owns the claimed guild installs successfully. */
    public function test_complete_install_succeeds_when_installer_owns_the_guild(): void
    {
        Http::fake([
            // Token exchange returns a user access_token (identify+guilds grant).
            self::TOKEN_URL => Http::response([
                'access_token' => 'user-bearer-token',
                'token_type' => 'Bearer',
                'scope' => 'identify guilds bot applications.commands',
            ], 200),
            // The installer's guild membership: they OWN the target guild.
            self::BASE_URL.'/users/@me/guilds' => Http::response([
                ['id' => self::GUILD_ID, 'name' => 'Berlin Boardgame Guild', 'owner' => true, 'permissions' => '0'],
                ['id' => '999999999999999999', 'name' => 'Other Server', 'owner' => false, 'permissions' => '0'],
            ], 200),
            self::BASE_URL.'/guilds/'.self::GUILD_ID => Http::response([
                'id' => self::GUILD_ID,
                'name' => 'Berlin Boardgame Guild',
                'icon' => 'abc123',
                'preferred_locale' => 'en-US',
            ], 200),
        ]);

        $landlord = User::factory()->create();

        $guild = $this->makeService()->completeInstall($landlord, 'valid-oauth-code', self::GUILD_ID, 'pkce-verifier');

        $this->assertSame(self::GUILD_ID, $guild->guild_id);
        $this->assertSame('Berlin Boardgame Guild', $guild->name);
        $this->assertSame('abc123', $guild->icon);
        $this->assertSame($landlord->id, $guild->owner_user_id);
        $this->assertDatabaseHas('discord_guilds', ['guild_id' => self::GUILD_ID]);

        // The code exchange sent the PKCE verifier.
        Http::assertSent(function (Request $r): bool {
            return $r->url() === self::TOKEN_URL
                && str_contains($r->body(), 'code_verifier=pkce-verifier');
        });
    }

    /** A landlord with MANAGE_GUILD (but not owner) may also install. */
    public function test_complete_install_succeeds_when_installer_has_manage_guild(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'user-bearer-token'], 200),
            // MANAGE_GUILD = 0x20 = 32.
            self::BASE_URL.'/users/@me/guilds' => Http::response([
                ['id' => self::GUILD_ID, 'owner' => false, 'permissions' => '32'],
            ], 200),
            self::BASE_URL.'/guilds/'.self::GUILD_ID => Http::response([
                'id' => self::GUILD_ID, 'name' => 'Managed Guild',
            ], 200),
        ]);

        $landlord = User::factory()->create();

        $guild = $this->makeService()->completeInstall($landlord, 'code', self::GUILD_ID);

        $this->assertSame($landlord->id, $guild->owner_user_id);
    }

    public function test_complete_install_throws_when_guild_id_is_malformed(): void
    {
        $landlord = User::factory()->create();

        try {
            $this->makeService()->completeInstall($landlord, 'some-code', 'not-a-snowflake');
            $this->fail('Expected DiscordBotInstallException for malformed guild_id.');
        } catch (DiscordBotInstallException $e) {
            $this->assertStringContainsString('guild_id', $e->getMessage());
        }

        // No token exchange should fire when the callback is malformed.
        Http::assertNothingSent();
    }

    public function test_complete_install_throws_on_empty_authorization_code(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'x'], 200)]);

        $landlord = User::factory()->create();

        $this->expectException(DiscordBotInstallException::class);
        $this->expectExceptionMessage('empty authorization code');

        $this->makeService()->completeInstall($landlord, '', self::GUILD_ID);
    }

    public function test_complete_install_throws_when_token_exchange_fails(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid "code" in request.',
            ], 400),
        ]);

        $landlord = User::factory()->create();

        try {
            $this->makeService()->completeInstall($landlord, 'replayed-code', self::GUILD_ID, 'pkce-verifier');
            $this->fail('Expected tokenExchangeFailed.');
        } catch (DiscordBotInstallException $e) {
            $this->assertStringContainsString('status 400', $e->getMessage());
            $this->assertStringContainsString('invalid_grant', $e->getMessage());
        }

        $this->assertDatabaseMissing('discord_guilds', ['guild_id' => self::GUILD_ID]);
    }

    /**
     * The takeover vector: a valid code from an attacker-owned guild is
     * replayed with a VICTIM guild_id. The ownership check must refuse it —
     * the victim guild is absent from the attacker's membership, so no row is
     * created and the attacker is never recorded as owner.
     */
    public function test_complete_install_refuses_a_guild_the_installer_does_not_administer(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'attacker-bearer'], 200),
            // The attacker is a member of OTHER guilds, but NOT the victim.
            self::BASE_URL.'/users/@me/guilds' => Http::response([
                ['id' => '111111111111111111', 'owner' => true, 'permissions' => '0'],
            ], 200),
            self::BASE_URL.'/guilds/'.self::GUILD_ID => Http::response([
                'id' => self::GUILD_ID, 'name' => 'Victim Guild',
            ], 200),
        ]);

        $attacker = User::factory()->create();

        try {
            $this->makeService()->completeInstall($attacker, 'valid-code-from-throwaway-guild', self::GUILD_ID, 'pkce-verifier');
            $this->fail('Expected guildNotOwned.');
        } catch (DiscordBotInstallException $e) {
            $this->assertStringContainsString('do not own', $e->getMessage());
        }

        // No takeover: no guild row, attacker never recorded as owner.
        $this->assertDatabaseMissing('discord_guilds', ['guild_id' => self::GUILD_ID]);
    }

    /** Ownership check fails closed when the token carries no user access_token. */
    public function test_complete_install_refuses_install_when_user_token_is_absent(): void
    {
        Http::fake([
            // No access_token (e.g. the bot scopes only — a misconfigured install).
            self::TOKEN_URL => Http::response(['token_type' => 'Bearer'], 200),
        ]);

        $landlord = User::factory()->create();

        $this->expectException(DiscordBotInstallException::class);
        $this->expectExceptionMessage('do not own');

        $this->makeService()->completeInstall($landlord, 'code', self::GUILD_ID);
    }

    public function test_complete_install_throws_when_guild_detail_fetch_fails(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'ok'], 200),
            self::BASE_URL.'/users/@me/guilds' => Http::response([
                ['id' => self::GUILD_ID, 'owner' => true, 'permissions' => '0'],
            ], 200),
            self::BASE_URL.'/guilds/'.self::GUILD_ID => Http::response([], 404),
        ]);

        $landlord = User::factory()->create();

        try {
            $this->makeService()->completeInstall($landlord, 'valid-code', self::GUILD_ID);
            $this->fail('Expected guildFetchFailed.');
        } catch (DiscordBotInstallException $e) {
            $this->assertStringContainsString('could not fetch guild', $e->getMessage());
        }

        $this->assertDatabaseMissing('discord_guilds', ['guild_id' => self::GUILD_ID]);
    }

    /** Re-installing into an existing guild updates the row (updateOrCreate). */
    public function test_complete_install_updates_existing_guild_on_reinstall(): void
    {
        $originalOwner = User::factory()->create();
        DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_ID,
            'name' => 'Old Name',
            'owner_user_id' => $originalOwner->id,
        ]);

        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'ok'], 200),
            self::BASE_URL.'/users/@me/guilds' => Http::response([
                ['id' => self::GUILD_ID, 'owner' => true, 'permissions' => '0'],
            ], 200),
            self::BASE_URL.'/guilds/'.self::GUILD_ID => Http::response([
                'id' => self::GUILD_ID,
                'name' => 'Renamed Server',
                'icon' => 'newicon',
            ], 200),
        ]);

        $newOwner = User::factory()->create();

        $guild = $this->makeService()->completeInstall($newOwner, 'fresh-code', self::GUILD_ID);

        $this->assertSame('Renamed Server', $guild->name);
        $this->assertSame('newicon', $guild->icon);
        $this->assertSame($newOwner->id, $guild->owner_user_id);
        $this->assertDatabaseCount('discord_guilds', 1); // updated, not duplicated
    }

    /** The install URL requests the guilds scope, state, and PKCE challenge. */
    public function test_install_url_requests_guilds_scope_state_and_pkce(): void
    {
        $url = $this->makeService()->installUrl(state: 'opaque-state', codeChallenge: 'challenge-value');

        $this->assertStringContainsString('scope=bot+applications.commands+identify+guilds', $url);
        $this->assertStringContainsString('state=opaque-state', $url);
        $this->assertStringContainsString('code_challenge=challenge-value', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    /** PKCE code_challenge is base64url(sha256(verifier)). */
    public function test_pkce_code_challenge_is_s256_of_verifier(): void
    {
        $verifier = 'a-reasonably-long-random-verifier-string';

        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->assertSame($expected, DiscordBotInstallService::codeChallenge($verifier));
    }
}
