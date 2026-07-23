<?php

namespace Tests\Feature\Livewire\Discord;

use App\Livewire\Discord\GuildSettings;
use App\Models\DiscordGuild;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the landlord bot-install flow and guild config surface (T06).
 *
 * Covers the four plan-required contract points for `--filter=GuildSettings`:
 *  1. install callback creates a discord_guild with owner_user_id
 *  2. channel picker persists calendar+games channel IDs
 *  3. pause toggle flips the paused column
 *  4. onboarding message posts on install
 *
 * Plus the authorization gate (non-owner gets 403), channel-list filtering
 * (only postable channel types), and the failure surfaces (install failure
 * redirect, channel-list-load failure degradation).
 *
 * All Discord I/O is intercepted via Http::fake(); no real network call.
 */
class GuildSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://discord.com/api/v10';

    private const GUILD_SNOWFLAKE = '111222333444555666';

    private const SYSTEM_CHANNEL = '222333444555666777';

    /**
     * A landlord: roundup-authenticated, verified, profile-complete (so both
     * the install callback and the guild-settings page admit them).
     */
    private function landlord(): User
    {
        return User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    }

    // ── Contract point 1: install callback creates discord_guild ──

    #[Test]
    public function install_callback_creates_discord_guild_with_owner_user_id()
    {
        $landlord = $this->landlord();

        Http::fake([
            // OAuth2 code exchange → returns the chosen guild_id.
            'discord.com/api/oauth2/token' => Http::response([
                'guild_id' => self::GUILD_SNOWFLAKE,
                'access_token' => 'bot-access-token',
            ], 200),
            // Guild detail → name/icon/locale/system channel.
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE => Http::response([
                'id' => self::GUILD_SNOWFLAKE,
                'name' => 'Dice Tower Den',
                'icon' => 'abc123',
                'preferred_locale' => 'en-US',
                'system_channel_id' => self::SYSTEM_CHANNEL,
            ], 200),
            // Onboarding message post → returns a message id.
            self::BASE_URL.'/channels/'.self::SYSTEM_CHANNEL.'/messages' => Http::response([
                'id' => '333444555666777888',
            ], 200),
        ]);

        $response = $this->actingAs($landlord)
            ->get('/discord/install/callback?code=test-auth-code');

        $response->assertRedirect();

        // The guild row exists, owned by the landlord who clicked install.
        $this->assertDatabaseHas('discord_guilds', [
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $landlord->id,
            'name' => 'Dice Tower Den',
            'icon' => 'abc123',
            'locale' => 'en-US',
            'paused' => false,
        ]);

        // Channels are null until the landlord picks them.
        $guild = DiscordGuild::where('guild_id', self::GUILD_SNOWFLAKE)->first();
        $this->assertNull($guild->calendar_channel_id);
        $this->assertNull($guild->games_channel_id);
    }

    #[Test]
    public function install_callback_redirects_to_guild_settings_page()
    {
        $landlord = $this->landlord();

        Http::fake($this->fullInstallFakes());

        $response = $this->actingAs($landlord)
            ->withSession(['locale' => 'en'])
            ->get('/discord/install/callback?code=test-auth-code');

        $response->assertRedirect('/en/discord/guilds/'.self::GUILD_SNOWFLAKE);
    }

    // ── Contract point 4: onboarding message posts on install ──

    #[Test]
    public function onboarding_message_posts_to_system_channel_on_install()
    {
        $landlord = $this->landlord();
        $posted = false;

        Http::fake([
            'discord.com/api/oauth2/token' => Http::response(['guild_id' => self::GUILD_SNOWFLAKE], 200),
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE => Http::response([
                'name' => 'Dice Tower Den',
                'system_channel_id' => self::SYSTEM_CHANNEL,
            ], 200),
            self::BASE_URL.'/channels/'.self::SYSTEM_CHANNEL.'/messages' => function () use (&$posted) {
                $posted = true;

                return Http::response(['id' => '333444555666777888'], 200);
            },
        ]);

        $this->actingAs($landlord)->get('/discord/install/callback?code=test-auth-code');

        $this->assertTrue($posted, 'onboarding message was posted to the system channel');
    }

    #[Test]
    public function onboarding_skipped_when_guild_has_no_system_channel()
    {
        $landlord = $this->landlord();

        Http::fake([
            'discord.com/api/oauth2/token' => Http::response(['guild_id' => self::GUILD_SNOWFLAKE], 200),
            // No system_channel_id → onboarding is skipped.
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE => Http::response([
                'name' => 'Quiet Server',
                'system_channel_id' => null,
            ], 200),
        ]);

        // Install still succeeds (guild row created) even with no system channel.
        $this->actingAs($landlord)->get('/discord/install/callback?code=test-auth-code');

        $this->assertDatabaseHas('discord_guilds', [
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $landlord->id,
            'name' => 'Quiet Server',
        ]);

        // The token exchange + guild fetch were legitimate, but the message
        // (onboarding) endpoint must NOT have been hit — no system channel.
        // assertNotSent takes a callable (Request → bool), not a URL string.
        Http::assertNotSent(fn (Request $request) => str_contains(
            $request->url(), '/channels/'.self::SYSTEM_CHANNEL.'/messages'
        ));
    }

    // ── Install re-install updates an existing guild ──

    #[Test]
    public function reinstalling_updates_existing_guild_name_and_owner()
    {
        $originalOwner = $this->landlord();
        $guild = DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE,
            'name' => 'Old Name',
            'owner_user_id' => $originalOwner->id,
        ]);

        $newOwner = $this->landlord();

        Http::fake([
            'discord.com/api/oauth2/token' => Http::response(['guild_id' => self::GUILD_SNOWFLAKE], 200),
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE => Http::response([
                'name' => 'New Name',
                'system_channel_id' => null,
            ], 200),
        ]);

        $this->actingAs($newOwner)->get('/discord/install/callback?code=test-auth-code');

        $guild->refresh();
        $this->assertSame('New Name', $guild->name);
        $this->assertSame($newOwner->id, $guild->owner_user_id);
    }

    // ── Install failure surfaces an error ──

    #[Test]
    public function install_failure_redirects_to_dashboard_with_error()
    {
        $landlord = $this->landlord();

        Http::fake([
            // Token exchange fails.
            'discord.com/api/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $response = $this->actingAs($landlord)
            ->withSession(['locale' => 'en'])
            ->get('/discord/install/callback?code=bad-code');

        $response->assertRedirect('/en/dashboard');
        $response->assertSessionHas('error');

        // No guild row created.
        $this->assertDatabaseMissing('discord_guilds', [
            'owner_user_id' => $landlord->id,
        ]);
    }

    #[Test]
    public function install_cancelled_redirects_to_dashboard_without_calling_discord()
    {
        $landlord = $this->landlord();

        Http::fake();

        $response = $this->actingAs($landlord)
            ->withSession(['locale' => 'en'])
            ->get('/discord/install/callback?error=access_denied');

        $response->assertRedirect('/en/dashboard');
        $response->assertSessionHas('error');

        Http::assertNothingSent();
    }

    // ── Contract point 2: channel picker persists channel IDs ──

    #[Test]
    public function channel_picker_persists_calendar_and_games_channel_ids()
    {
        $landlord = $this->landlord();
        $guild = DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $landlord->id,
            'games_channel_id' => null,
            'calendar_channel_id' => null,
        ]);

        Http::fake([
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE.'/channels' => Http::response([
                ['id' => '444555666777888000', 'name' => 'games-room', 'type' => 0],
                ['id' => '555666777888999111', 'name' => 'calendar-feed', 'type' => 0],
                ['id' => '666777888999000222', 'name' => 'General Voice', 'type' => 2],
            ], 200),
        ]);

        Livewire::actingAs($landlord)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE])
            ->set('games_channel_id', '444555666777888000')
            ->set('calendar_channel_id', '555666777888999111')
            ->call('save')
            ->assertSet('saved', true);

        $guild->refresh();
        $this->assertSame('444555666777888000', $guild->games_channel_id);
        $this->assertSame('555666777888999111', $guild->calendar_channel_id);
    }

    #[Test]
    public function channel_picker_clears_channel_when_set_to_empty()
    {
        $landlord = $this->landlord();
        $guild = DiscordGuild::factory()->configured()->create([
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $landlord->id,
        ]);

        Http::fake([
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE.'/channels' => Http::response([
                ['id' => '444555666777888000', 'name' => 'games-room', 'type' => 0],
            ], 200),
        ]);

        Livewire::actingAs($landlord)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE])
            ->set('games_channel_id', '')
            ->set('calendar_channel_id', '')
            ->call('save');

        $guild->refresh();
        $this->assertNull($guild->games_channel_id);
        $this->assertNull($guild->calendar_channel_id);
    }

    #[Test]
    public function channel_list_loads_only_postable_channel_types()
    {
        $landlord = $this->landlord();
        DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $landlord->id,
        ]);

        Http::fake([
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE.'/channels' => Http::response([
                ['id' => '1', 'name' => 'text-channel', 'type' => 0],       // text — postable
                ['id' => '2', 'name' => 'voice-channel', 'type' => 2],      // voice — excluded
                ['id' => '3', 'name' => 'category', 'type' => 4],           // category — excluded
                ['id' => '4', 'name' => 'announcements', 'type' => 5],      // announcement — postable
                ['id' => '5', 'name' => 'stage', 'type' => 13],             // stage — excluded
                ['id' => '6', 'name' => 'forum-board', 'type' => 15],       // forum — postable
            ], 200),
        ]);

        $component = Livewire::actingAs($landlord)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE]);

        $channels = collect($component->get('channels'));

        $this->assertCount(3, $channels);
        $ids = $channels->pluck('id')->all();
        $this->assertEqualsCanonicalizing(['1', '4', '6'], $ids);
        // Voice/category/stage are filtered out.
        $this->assertNotContains('2', $ids);
        $this->assertNotContains('3', $ids);
        $this->assertNotContains('5', $ids);
    }

    // ── Contract point 3: pause toggle flips the paused column ──

    #[Test]
    public function pause_toggle_flips_paused_column_to_true()
    {
        $landlord = $this->landlord();
        $guild = DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $landlord->id,
            'paused' => false,
        ]);

        Http::fake([
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE.'/channels' => Http::response([], 200),
        ]);

        Livewire::actingAs($landlord)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE])
            ->assertSet('paused', false)
            ->call('togglePaused')
            ->assertSet('paused', true)
            ->assertSet('pausedChanged', true);

        $guild->refresh();
        $this->assertTrue($guild->paused);
    }

    #[Test]
    public function pause_toggle_resumes_posting()
    {
        $landlord = $this->landlord();
        $guild = DiscordGuild::factory()->paused()->create([
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $landlord->id,
        ]);

        Http::fake([
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE.'/channels' => Http::response([], 200),
        ]);

        Livewire::actingAs($landlord)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE])
            ->assertSet('paused', true)
            ->call('togglePaused')
            ->assertSet('paused', false);

        $guild->refresh();
        $this->assertFalse($guild->paused);
    }

    #[Test]
    public function pause_and_resume_actions_are_logged()
    {
        Log::spy();

        $landlord = $this->landlord();
        DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $landlord->id,
            'paused' => false,
        ]);

        Http::fake([
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE.'/channels' => Http::response([], 200),
        ]);

        Livewire::actingAs($landlord)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE])
            ->call('togglePaused');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $ctx) => $message === 'discord_guild.pause_toggled'
                && ($ctx['action'] ?? null) === 'paused')
            ->atLeast()
            ->once();
    }

    // ── Authorization gate ──

    #[Test]
    public function non_owner_is_forbidden_from_viewing_guild_settings()
    {
        $owner = $this->landlord();
        $intruder = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $owner->id,
        ]);

        Http::fake([
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE.'/channels' => Http::response([], 200),
        ]);

        $this->actingAs($intruder)
            ->get('/en/discord/guilds/'.self::GUILD_SNOWFLAKE)
            ->assertForbidden();
    }

    #[Test]
    public function non_owner_cannot_save_channels()
    {
        $owner = $this->landlord();
        $intruder = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);

        $guild = DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $owner->id,
            'games_channel_id' => null,
        ]);

        Http::fake([
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE.'/channels' => Http::response([
                ['id' => '999', 'name' => 'games', 'type' => 0],
            ], 200),
        ]);

        // mount() already aborts 403 for non-owners, so the component can't
        // even be tested directly. The route-level 403 is the gate.
        $this->actingAs($intruder)
            ->get('/en/discord/guilds/'.self::GUILD_SNOWFLAKE)
            ->assertForbidden();

        // State unchanged.
        $this->assertNull($guild->fresh()->games_channel_id);
    }

    #[Test]
    public function unknown_guild_returns_404()
    {
        $landlord = $this->landlord();

        $this->actingAs($landlord)
            ->get('/en/discord/guilds/000000000000000000')
            ->assertNotFound();
    }

    // ── Channel-list-load failure degrades gracefully ──

    #[Test]
    public function channel_list_load_failure_shows_error_and_keeps_pause_working()
    {
        $landlord = $this->landlord();
        DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $landlord->id,
        ]);

        Http::fake([
            // Channel list fails (e.g. bot lost View Channels permission).
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE.'/channels' => Http::response(['message' => 'Missing Access'], 403),
        ]);

        $component = Livewire::actingAs($landlord)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE]);

        $component
            ->assertSet('channelsLoadFailed', true)
            ->assertSet('channels', []);

        // The landlord can still pause even with no channel list.
        $component->call('togglePaused')->assertSet('paused', true);
    }

    #[Test]
    public function refresh_channels_retries_the_channel_list()
    {
        $landlord = $this->landlord();
        DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $landlord->id,
        ]);

        Http::fake([
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE.'/channels' => Http::response([
                ['id' => '1', 'name' => 'games', 'type' => 0],
            ], 200),
        ]);

        Livewire::actingAs($landlord)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE])
            ->call('refreshChannels')
            ->assertSet('channelsLoadFailed', false);
    }

    // ── Guest access ──

    #[Test]
    public function guest_is_redirected_from_guild_settings_page()
    {
        DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE]);

        $this->get('/en/discord/guilds/'.self::GUILD_SNOWFLAKE)
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function guest_is_redirected_from_install_callback()
    {
        $this->get('/discord/install/callback?code=x')
            ->assertRedirect(route('login'));
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * A complete set of Http::fake responses for a successful install round-trip.
     *
     * @return array<string, Response>
     */
    private function fullInstallFakes(): array
    {
        return [
            'discord.com/api/oauth2/token' => Http::response(['guild_id' => self::GUILD_SNOWFLAKE], 200),
            self::BASE_URL.'/guilds/'.self::GUILD_SNOWFLAKE => Http::response([
                'name' => 'Dice Tower Den',
                'system_channel_id' => self::SYSTEM_CHANNEL,
            ], 200),
            self::BASE_URL.'/channels/'.self::SYSTEM_CHANNEL.'/messages' => Http::response(['id' => '333444555666777888'], 200),
        ];
    }
}
