<?php

namespace Tests\Feature\Livewire\Discord;

use App\Enums\DiscordModerationMode;
use App\Livewire\Discord\GuildSettings;
use App\Models\DiscordGuild;
use App\Models\User;
use App\Services\Discord\DiscordBotInstallService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the locale-setting contract for the landlord guild-configuration
 * surface (/discord/guilds/{guild}):
 *
 *   1. the locale picker loads the guild's current locale,
 *   2. saving persists a valid locale to the guild row,
 *   3. an invalid locale is rejected by validation,
 *   4. a non-owner is 403'd.
 *
 * The locale drives every Discord-side text render (card labels, digest
 * footer, thread starter, dates) — see DiscordCardRenderer /
 * DiscordDigestRenderer locale tests.
 */
class GuildSettingsLocaleTest extends TestCase
{
    use DatabaseTransactions;

    private const GUILD_SNOWFLAKE = '888000888000888000';

    #[Test]
    public function loads_the_guilds_current_locale_into_the_picker()
    {
        $owner = $this->owner();
        $guild = $this->guild($owner, ['locale' => 'de']);

        Livewire::actingAs($owner)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE])
            ->assertSet('locale', 'de');
    }

    #[Test]
    public function saving_persists_a_valid_locale_to_the_guild_row()
    {
        $owner = $this->owner();
        $guild = $this->guild($owner, ['locale' => null]);

        Livewire::actingAs($owner)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE])
            ->set('locale', 'de')
            ->call('saveLocale')
            ->assertHasNoErrors();

        $this->assertSame('de', $guild->fresh()->locale, 'locale persisted to the guild row');
    }

    #[Test]
    public function saving_with_a_null_locale_clears_the_stored_value()
    {
        $owner = $this->owner();
        $guild = $this->guild($owner, ['locale' => 'de']);

        Livewire::actingAs($owner)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE])
            ->set('locale', '')
            ->call('saveLocale')
            ->assertHasNoErrors();

        $this->assertNull($guild->fresh()->locale, 'empty locale clears the stored value (falls back to app default)');
    }

    #[Test]
    public function an_invalid_locale_is_rejected_by_validation()
    {
        $owner = $this->owner();
        $guild = $this->guild($owner, ['locale' => null]);

        Livewire::actingAs($owner)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE])
            ->set('locale', 'fr')
            ->call('saveLocale')
            ->assertHasErrors(['locale']);

        $this->assertNull($guild->fresh()->locale, 'invalid locale was not persisted');
    }

    #[Test]
    public function a_non_owner_is_forbidden_from_configuring_the_guild()
    {
        $owner = $this->owner();
        $this->guild($owner, ['locale' => null]);

        $intruder = User::factory()->create(['profile_complete' => true]);

        Livewire::actingAs($intruder)
            ->test(GuildSettings::class, ['guild' => self::GUILD_SNOWFLAKE])
            ->assertForbidden();
    }

    // ── Fixtures ─────────────────────────────────────────

    private function owner(): User
    {
        return User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function guild(User $owner, array $overrides = []): DiscordGuild
    {
        return DiscordGuild::factory()->create(array_merge([
            'guild_id' => self::GUILD_SNOWFLAKE,
            'owner_user_id' => $owner->id,
            'locale' => null,
            'moderation_mode' => DiscordModerationMode::Open,
        ], $overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The channel list is fetched from Discord on mount; stub the service
        // so mount() resolves an empty channel list without hitting the API.
        $mock = $this->mock(DiscordBotInstallService::class);
        $mock->shouldReceive('listChannels')->andReturn([]);
    }
}
