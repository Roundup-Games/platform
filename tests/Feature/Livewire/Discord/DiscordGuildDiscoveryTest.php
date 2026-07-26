<?php

namespace Tests\Feature\Livewire\Discord;

use App\Livewire\Discord\OrganizerGuilds;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Services\Discord\DiscordGuildDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T07 (M057/D119): organizer auto-discovery and per-guild opt-in.
 *
 * Pins the four plan-required contract points for
 * `php artisan test --filter=DiscordGuildDiscovery`:
 *   1. organizer in a roundup-enabled guild sees it surfaced,
 *   2. organizer in no roundup-enabled guild sees an empty state,
 *   3. opt-in creates a discord_guild_organizers row with publish_enabled=true,
 *   4. opt-out flips publish_enabled to false.
 *
 * Plus the discovery edge cases (no Discord link, failed guild fetch, multiple
 * matches), the consent-audit contract (first opt-in timestamp preserved
 * across opt-out cycles), the discovery logging contract (surfaced / claimed /
 * unclaimed), and the Livewire surface wiring.
 */
class DiscordGuildDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private const GUILD_SNOWFLAKE_A = '111000111000111000';

    private const GUILD_SNOWFLAKE_B = '222000222000222000';

    private const GUILD_SNOWFLAKE_OTHER = '999000999000999000';

    /**
     * An organizer: a roundup user with a profile-complete, verified account.
     */
    private function organizer(): User
    {
        return User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Link an organizer's Discord account with a membership snapshot
     * (provider_meta.guilds), matching what the guilds OAuth scope persists (T02).
     *
     * @param  list<string>  $guildSnowflakes  Discord guilds the organizer is in
     */
    private function linkDiscord(User $organizer, array $guildSnowflakes): LinkedAccount
    {
        $guilds = array_map(static fn (string $id) => ['id' => $id, 'name' => "Guild {$id}", 'icon' => null], $guildSnowflakes);

        return LinkedAccount::create([
            'user_id' => $organizer->id,
            'provider' => 'discord',
            'provider_user_id' => (string) mt_rand(100000000000000000, 999999999999999999),
            'token' => 'tok',
            'provider_meta' => ['nickname' => null, 'avatar' => null, 'guilds' => $guilds],
        ]);
    }

    // ── Contract point 1: organizer in a roundup-enabled guild → surfaced ──

    #[Test]
    public function organizer_in_a_roundup_enabled_guild_sees_it_surfaced(): void
    {
        $organizer = $this->organizer();
        $guild = DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE_A,
            'name' => 'Dice Tower Den',
        ]);
        $this->linkDiscord($organizer, [self::GUILD_SNOWFLAKE_A]);

        $discovered = app(DiscordGuildDiscoveryService::class)->discoverFor($organizer);

        $this->assertCount(1, $discovered);
        $this->assertSame($guild->id, $discovered[0]->guild->id);
        $this->assertSame('Dice Tower Den', $discovered[0]->guild->name);
        // Not opted in yet → discovery surfaces it as off.
        $this->assertFalse($discovered[0]->publishEnabled);
        $this->assertNull($discovered[0]->optedInAt);
    }

    #[Test]
    public function discovery_only_surfaces_guilds_the_organizer_is_actually_in(): void
    {
        $organizer = $this->organizer();
        DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE_A,
            'name' => 'In Server',
        ]);
        DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE_OTHER,
            'name' => 'Other Server',
        ]);
        // Organizer is a member of A but NOT OTHER — only A surfaces.
        $this->linkDiscord($organizer, [self::GUILD_SNOWFLAKE_A]);

        $discovered = app(DiscordGuildDiscoveryService::class)->discoverFor($organizer);

        $this->assertCount(1, $discovered);
        $this->assertSame(self::GUILD_SNOWFLAKE_A, $discovered[0]->guild->guild_id);
    }

    #[Test]
    public function discovery_reflects_an_existing_opt_in_state(): void
    {
        $organizer = $this->organizer();
        $guild = DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);
        DiscordGuildOrganizer::factory()->optedIn()->create([
            'guild_id' => $guild->id,
            'user_id' => $organizer->id,
        ]);
        $this->linkDiscord($organizer, [self::GUILD_SNOWFLAKE_A]);

        $discovered = app(DiscordGuildDiscoveryService::class)->discoverFor($organizer);

        $this->assertCount(1, $discovered);
        $this->assertTrue($discovered[0]->publishEnabled);
        $this->assertNotNull($discovered[0]->optedInAt);
    }

    #[Test]
    public function discovery_surfaces_multiple_matches_ordered_by_name(): void
    {
        $organizer = $this->organizer();
        DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A, 'name' => 'Zeta Guild']);
        DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_B, 'name' => 'Alpha Guild']);
        $this->linkDiscord($organizer, [self::GUILD_SNOWFLAKE_A, self::GUILD_SNOWFLAKE_B]);

        $discovered = app(DiscordGuildDiscoveryService::class)->discoverFor($organizer);

        $this->assertCount(2, $discovered);
        $this->assertSame('Alpha Guild', $discovered[0]->guild->name);
        $this->assertSame('Zeta Guild', $discovered[1]->guild->name);
    }

    // ── Contract point 2: organizer in no roundup-enabled guild → empty ──

    #[Test]
    public function organizer_in_no_roundup_enabled_guild_sees_empty_state(): void
    {
        $organizer = $this->organizer();
        // The organizer is in a guild, but roundup is not installed there.
        $this->linkDiscord($organizer, [self::GUILD_SNOWFLAKE_OTHER]);

        $discovered = app(DiscordGuildDiscoveryService::class)->discoverFor($organizer);

        $this->assertSame([], $discovered);
        $this->assertTrue(app(DiscordGuildDiscoveryService::class)->hasDiscordLink($organizer));
    }

    #[Test]
    public function organizer_with_no_discord_link_has_empty_discovery(): void
    {
        $organizer = $this->organizer();
        DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);

        $service = app(DiscordGuildDiscoveryService::class);

        $this->assertSame([], $service->discoverFor($organizer));
        $this->assertFalse($service->hasDiscordLink($organizer));
    }

    #[Test]
    public function organizer_whose_guild_fetch_failed_has_empty_discovery(): void
    {
        $organizer = $this->organizer();
        DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);
        // Discord link exists but the best-effort guild fetch omitted guilds.
        $this->linkDiscord($organizer, []);

        $service = app(DiscordGuildDiscoveryService::class);

        $this->assertSame([], $service->discoverFor($organizer));
        $this->assertTrue($service->hasDiscordLink($organizer));
    }

    // ── Contract point 3: opt-in creates row with publish_enabled=true ──

    #[Test]
    public function opt_in_creates_row_with_publish_enabled_true(): void
    {
        $organizer = $this->organizer();
        $guild = DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);

        $optIn = app(DiscordGuildDiscoveryService::class)->optIn($organizer, $guild);

        $this->assertTrue($optIn->publish_enabled);
        $this->assertNotNull($optIn->opted_in_at);

        $this->assertDatabaseHas('discord_guild_organizers', [
            'guild_id' => $guild->id,
            'user_id' => $organizer->id,
            'publish_enabled' => true,
        ]);
    }

    #[Test]
    public function opt_in_is_idempotent_and_preserves_first_consent_timestamp(): void
    {
        $organizer = $this->organizer();
        $guild = DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);
        $service = app(DiscordGuildDiscoveryService::class);

        $first = $service->optIn($organizer, $guild);
        $firstStamp = $first->opted_in_at;
        $this->assertNotNull($firstStamp);

        // Re-opt-in: stays enabled, keeps the first-consent timestamp.
        $again = $service->optIn($organizer, $guild);

        $this->assertTrue($again->publish_enabled);
        $this->assertEquals($firstStamp, $again->opted_in_at);
        $this->assertDatabaseCount('discord_guild_organizers', 1);
    }

    // ── Contract point 4: opt-out flips publish_enabled to false ──

    #[Test]
    public function opt_out_flips_publish_enabled_to_false(): void
    {
        $organizer = $this->organizer();
        $guild = DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);
        $service = app(DiscordGuildDiscoveryService::class);
        $service->optIn($organizer, $guild);

        $optOut = $service->optOut($organizer, $guild);

        $this->assertFalse($optOut->publish_enabled);
        $this->assertDatabaseHas('discord_guild_organizers', [
            'guild_id' => $guild->id,
            'user_id' => $organizer->id,
            'publish_enabled' => false,
        ]);
    }

    #[Test]
    public function opt_out_preserves_the_row_and_first_consent_timestamp(): void
    {
        $organizer = $this->organizer();
        $guild = DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);
        $service = app(DiscordGuildDiscoveryService::class);

        $optIn = $service->optIn($organizer, $guild);
        $firstStamp = $optIn->opted_in_at;

        $service->optOut($organizer, $guild);

        // Row kept (audit), publish off, opted_in_at preserved.
        $this->assertDatabaseCount('discord_guild_organizers', 1);
        $row = DiscordGuildOrganizer::where('guild_id', $guild->id)
            ->where('user_id', $organizer->id)
            ->first();
        $this->assertFalse($row->publish_enabled);
        $this->assertEquals($firstStamp, $row->opted_in_at);

        // Re-opt-in restores the original first-consent timestamp (not "now").
        $reOpt = $service->optIn($organizer, $guild);
        $this->assertEquals($firstStamp, $reOpt->opted_in_at);
    }

    #[Test]
    public function opt_out_without_an_existing_row_is_a_safe_noop(): void
    {
        $organizer = $this->organizer();
        $guild = DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);

        $result = app(DiscordGuildDiscoveryService::class)->optOut($organizer, $guild);

        $this->assertNull($result);
        $this->assertDatabaseCount('discord_guild_organizers', 0);
    }

    // ── Logging contract: surfaced / claimed / unclaimed ──

    #[Test]
    public function surfaced_guilds_are_logged_with_organizer_and_guild_ids(): void
    {
        Log::spy();

        $organizer = $this->organizer();
        DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);
        $this->linkDiscord($organizer, [self::GUILD_SNOWFLAKE_A]);

        app(DiscordGuildDiscoveryService::class)->discoverFor($organizer);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $ctx) => $message === 'discord_discovery.guild_surfaced'
                && ($ctx['organizer_id'] ?? null) === $organizer->id
                && ($ctx['guild_id'] ?? null) === self::GUILD_SNOWFLAKE_A
                && ($ctx['status'] ?? null) === 'surfaced')
            ->atLeast()
            ->once();
    }

    #[Test]
    public function opt_in_is_logged_as_claimed_and_opt_out_as_unclaimed(): void
    {
        Log::spy();

        $organizer = $this->organizer();
        $guild = DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);
        $service = app(DiscordGuildDiscoveryService::class);

        $service->optIn($organizer, $guild);
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $ctx) => $message === 'discord_discovery.guild_opted_in'
                && ($ctx['status'] ?? null) === 'claimed'
                && ($ctx['organizer_id'] ?? null) === $organizer->id
                && ($ctx['guild_id'] ?? null) === self::GUILD_SNOWFLAKE_A)
            ->atLeast()
            ->once();

        $service->optOut($organizer, $guild);
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $ctx) => $message === 'discord_discovery.guild_opted_out'
                && ($ctx['status'] ?? null) === 'unclaimed')
            ->atLeast()
            ->once();
    }

    // ── Livewire surface wiring ──

    #[Test]
    public function component_loads_surfaced_guilds_on_mount(): void
    {
        $organizer = $this->organizer();
        $guild = DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE_A,
            'name' => 'Dice Tower Den',
        ]);
        $this->linkDiscord($organizer, [self::GUILD_SNOWFLAKE_A]);

        $component = Livewire::actingAs($organizer)
            ->test(OrganizerGuilds::class);

        $surfaced = $component->get('surfaced');
        $this->assertCount(1, $surfaced);
        $this->assertSame($guild->id, $surfaced[0]['id']);
        $this->assertTrue($component->get('hasDiscordLink'));
    }

    #[Test]
    public function component_opt_in_persists_a_publish_enabled_row(): void
    {
        $organizer = $this->organizer();
        $guild = DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);
        $this->linkDiscord($organizer, [self::GUILD_SNOWFLAKE_A]);

        Livewire::actingAs($organizer)
            ->test(OrganizerGuilds::class)
            ->call('optIn', $guild->id)
            ->assertSet('lastAction', 'opted_in');

        $this->assertDatabaseHas('discord_guild_organizers', [
            'guild_id' => $guild->id,
            'user_id' => $organizer->id,
            'publish_enabled' => true,
        ]);
    }

    #[Test]
    public function component_opt_out_flips_publish_enabled_to_false(): void
    {
        $organizer = $this->organizer();
        $guild = DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);
        DiscordGuildOrganizer::factory()->optedIn()->create([
            'guild_id' => $guild->id,
            'user_id' => $organizer->id,
        ]);
        $this->linkDiscord($organizer, [self::GUILD_SNOWFLAKE_A]);

        Livewire::actingAs($organizer)
            ->test(OrganizerGuilds::class)
            ->call('optOut', $guild->id)
            ->assertSet('lastAction', 'opted_out');

        $this->assertDatabaseHas('discord_guild_organizers', [
            'guild_id' => $guild->id,
            'user_id' => $organizer->id,
            'publish_enabled' => false,
        ]);
    }

    #[Test]
    public function component_renders_empty_state_when_not_linked(): void
    {
        $organizer = $this->organizer();

        Livewire::actingAs($organizer)
            ->test(OrganizerGuilds::class)
            ->assertSet('hasDiscordLink', false)
            ->assertSeeHtml('Link your Discord account');
    }

    #[Test]
    public function component_renders_empty_state_when_linked_but_no_roundup_servers(): void
    {
        $organizer = $this->organizer();
        $this->linkDiscord($organizer, [self::GUILD_SNOWFLAKE_OTHER]);

        Livewire::actingAs($organizer)
            ->test(OrganizerGuilds::class)
            ->assertSet('hasDiscordLink', true)
            ->assertSeeHtml('No roundup servers yet');
    }

    #[Test]
    public function component_renders_the_surfaced_guild_name(): void
    {
        $organizer = $this->organizer();
        DiscordGuild::factory()->create([
            'guild_id' => self::GUILD_SNOWFLAKE_A,
            'name' => 'Dice Tower Den',
        ]);
        $this->linkDiscord($organizer, [self::GUILD_SNOWFLAKE_A]);

        Livewire::actingAs($organizer)
            ->test(OrganizerGuilds::class)
            ->assertSee('Dice Tower Den');
    }

    #[Test]
    public function component_refresh_reruns_discovery(): void
    {
        $organizer = $this->organizer();
        $this->linkDiscord($organizer, [self::GUILD_SNOWFLAKE_A]);

        $component = Livewire::actingAs($organizer)->test(OrganizerGuilds::class);
        $this->assertCount(0, $component->get('surfaced'));

        // roundup is installed in the organizer's guild after mount.
        DiscordGuild::factory()->create(['guild_id' => self::GUILD_SNOWFLAKE_A]);

        $component->call('refresh')->assertSet('hasDiscordLink', true);
        $this->assertCount(1, $component->get('surfaced'));
    }

    // ── Route access ──

    #[Test]
    public function guest_is_redirected_from_organizer_guilds_page(): void
    {
        $this->get('/en/discord/organizer-guilds')->assertRedirect(route('login'));
    }
}
