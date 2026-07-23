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
 * The live-guild UAT surfaced a real bug: Discord's STANDARD bot-install flow
 * (the one roundup's installUrl() generates, where the landlord picks the guild
 * in Discord's UI) returns the chosen `guild_id` in the CALLBACK URL query
 * string — NOT in the access-token response body. The original code read it
 * from the token body and threw "missing guild_id in token response" on every
 * real install. These tests pin the correct contract: guild_id arrives on the
 * callback, the token exchange is validation-only.
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

    /** The real-Discord shape: token response carries NO guild_id; it arrives
     *  on the callback URL and is passed in explicitly. Install must succeed. */
    public function test_complete_install_uses_guild_id_from_callback_not_token_body(): void
    {
        Http::fake([
            // Token exchange succeeds (200) but — like real Discord — omits
            // guild_id from the body. The old code threw here.
            self::TOKEN_URL => Http::response([
                'access_token' => 'fake-bot-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 604800,
                'scope' => 'bot applications.commands',
                // NOTE: no 'guild_id' key — this is the bug's trigger.
            ], 200),

            // Guild detail fetch (used for stored name/icon/locale).
            self::BASE_URL.'/guilds/'.self::GUILD_ID => Http::response([
                'id' => self::GUILD_ID,
                'name' => 'Berlin Boardgame Guild',
                'icon' => 'abc123',
                'preferred_locale' => 'en-US',
                // No system_channel_id → onboarding is skipped (kept out of
                // this regression's scope; exercised separately downstream).
            ], 200),
        ]);

        $landlord = User::factory()->create();

        $guild = $this->makeService()->completeInstall($landlord, 'valid-oauth-code', self::GUILD_ID);

        $this->assertSame(self::GUILD_ID, $guild->guild_id);
        $this->assertSame('Berlin Boardgame Guild', $guild->name);
        $this->assertSame('abc123', $guild->icon);
        $this->assertSame($landlord->id, $guild->owner_user_id);
        $this->assertDatabaseHas('discord_guilds', ['guild_id' => self::GUILD_ID]);

        // The token exchange must still fire (validates the OAuth code).
        Http::assertSent(fn (Request $r): bool => $r->url() === self::TOKEN_URL);
    }

    public function test_complete_install_throws_when_guild_id_is_missing(): void
    {
        $landlord = User::factory()->create();

        try {
            $this->makeService()->completeInstall($landlord, 'some-code', '');
            $this->fail('Expected DiscordBotInstallException for missing guild_id.');
        } catch (DiscordBotInstallException $e) {
            $this->assertStringContainsString('missing guild_id', $e->getMessage());
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
        // Mirrors the replayed-code failure seen in the live logs: a reused
        // OAuth code returns 400 invalid_grant.
        Http::fake([
            self::TOKEN_URL => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid "code" in request.',
            ], 400),
        ]);

        $landlord = User::factory()->create();

        try {
            $this->makeService()->completeInstall($landlord, 'replayed-code', self::GUILD_ID);
            $this->fail('Expected tokenExchangeFailed.');
        } catch (DiscordBotInstallException $e) {
            $this->assertStringContainsString('status 400', $e->getMessage());
            $this->assertStringContainsString('invalid_grant', $e->getMessage());
        }

        $this->assertDatabaseMissing('discord_guilds', ['guild_id' => self::GUILD_ID]);
    }

    public function test_complete_install_throws_when_guild_detail_fetch_fails(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'ok'], 200),
            self::BASE_URL.'/guilds/'.self::GUILD_ID => Http::response([], 404),
        ]);

        $landlord = User::factory()->create();

        try {
            $this->makeService()->completeInstall($landlord, 'valid-code', self::GUILD_ID);
            $this->fail('Expected guildFetchFailed.');
        } catch (DiscordBotInstallException $e) {
            $this->assertStringContainsString('could not fetch guild', $e->getMessage());
        }

        // No partial row when the guild detail fetch fails.
        $this->assertDatabaseMissing('discord_guilds', ['guild_id' => self::GUILD_ID]);
    }

    /** Re-installing into an existing guild updates the row (updateOrCreate)
     *  rather than duplicating or erroring. */
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
}
