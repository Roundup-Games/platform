<?php

namespace Tests\Feature\Models;

use App\Models\DiscordCardMessage;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DiscordGuildModelTest extends TestCase
{
    use DatabaseTransactions;

    // ── DiscordGuild basics ──────────────────────────────────

    public function test_can_create_guild_via_factory(): void
    {
        $guild = DiscordGuild::factory()->create();

        $this->assertInstanceOf(DiscordGuild::class, $guild);
        $this->assertNotEmpty($guild->id);
        $this->assertNotEmpty($guild->guild_id);
    }

    public function test_auto_generates_uuid_on_creation(): void
    {
        $guild = DiscordGuild::create([
            'guild_id' => (string) random_int(100000000000000000, 999999999999999999),
            'name' => 'Test Server',
            'owner_user_id' => User::factory()->create()->id,
        ]);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $guild->id);
    }

    public function test_uuid_key_type_is_string_and_not_incrementing(): void
    {
        $guild = DiscordGuild::factory()->create();

        $this->assertFalse($guild->incrementing);
        $this->assertSame('string', $guild->getKeyType());
    }

    public function test_paused_casts_to_boolean(): void
    {
        $guild = DiscordGuild::factory()->create(['paused' => true]);

        $this->assertTrue($guild->paused);
        $this->assertTrue($guild->is_paused);
    }

    public function test_defaults_are_paused_false_and_mode_open(): void
    {
        $guild = DiscordGuild::factory()->create();

        $this->assertFalse($guild->paused);
        $this->assertSame('open', $guild->moderation_mode);
        $this->assertNull($guild->calendar_channel_id);
        $this->assertNull($guild->games_channel_id);
    }

    public function test_configured_factory_state_sets_both_channels(): void
    {
        $guild = DiscordGuild::factory()->configured()->create();

        $this->assertNotNull($guild->calendar_channel_id);
        $this->assertNotNull($guild->games_channel_id);
    }

    // ── DiscordGuild relationships ───────────────────────────

    public function test_guild_belongs_to_owner_user(): void
    {
        $owner = User::factory()->create();
        $guild = DiscordGuild::factory()->create(['owner_user_id' => $owner->id]);

        $this->assertTrue($guild->owner->is($owner));
    }

    public function test_guild_has_many_organizers(): void
    {
        $guild = DiscordGuild::factory()->create();
        $organizer = DiscordGuildOrganizer::factory()
            ->for($guild, 'guild')
            ->for(User::factory())
            ->create();

        $this->assertTrue($guild->organizers->contains($organizer));
        $this->assertCount(1, $guild->organizers);
    }

    public function test_guild_has_many_card_messages(): void
    {
        $guild = DiscordGuild::factory()->create();
        $card = DiscordCardMessage::create([
            'game_id' => Game::factory()->create()->id,
            'guild_id' => $guild->id,
            'channel_id' => (string) random_int(100000000000000000, 999999999999999999),
            'message_id' => (string) random_int(100000000000000000, 999999999999999999),
        ]);

        $this->assertTrue($guild->cardMessages->contains($card));
    }

    // ── DiscordGuildOrganizer basics + relationships ─────────

    public function test_organizer_factory_defaults_to_not_publishing(): void
    {
        $organizer = DiscordGuildOrganizer::factory()->create();

        $this->assertFalse($organizer->publish_enabled);
        $this->assertNull($organizer->opted_in_at);
    }

    public function test_opted_in_factory_state_sets_publish_enabled_and_timestamp(): void
    {
        $organizer = DiscordGuildOrganizer::factory()->optedIn()->create();

        $this->assertTrue($organizer->publish_enabled);
        $this->assertNotNull($organizer->opted_in_at);
    }

    public function test_organizer_belongs_to_guild_and_user(): void
    {
        $guild = DiscordGuild::factory()->create();
        $user = User::factory()->create();
        $organizer = DiscordGuildOrganizer::factory()
            ->for($guild, 'guild')
            ->for($user)
            ->create();

        $this->assertTrue($organizer->guild->is($guild));
        $this->assertTrue($organizer->user->is($user));
    }

    // ── DiscordCardMessage relationships ─────────────────────

    public function test_card_message_belongs_to_game_and_guild(): void
    {
        $game = Game::factory()->create();
        $guild = DiscordGuild::factory()->create();
        $card = DiscordCardMessage::create([
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => '999',
            'message_id' => '888',
        ]);

        $this->assertTrue($card->game->is($game));
        $this->assertTrue($card->guild->is($guild));
    }

    public function test_card_message_auto_generates_uuid(): void
    {
        $card = DiscordCardMessage::create([
            'game_id' => Game::factory()->create()->id,
            'guild_id' => DiscordGuild::factory()->create()->id,
            'channel_id' => '1',
            'message_id' => '2',
        ]);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $card->id);
    }

    // ── Negative: unique constraints ─────────────────────────

    public function test_discord_guild_id_is_unique(): void
    {
        $guildId = (string) random_int(100000000000000000, 999999999999999999);
        DiscordGuild::factory()->create(['guild_id' => $guildId]);

        $this->expectException(QueryException::class);

        DiscordGuild::factory()->create(['guild_id' => $guildId]);
    }

    public function test_organizer_guild_user_pair_is_unique(): void
    {
        $guild = DiscordGuild::factory()->create();
        $user = User::factory()->create();
        DiscordGuildOrganizer::factory()->for($guild, 'guild')->for($user)->create();

        $this->expectException(QueryException::class);

        DiscordGuildOrganizer::factory()->for($guild, 'guild')->for($user)->create();
    }

    public function test_card_message_game_guild_pair_is_unique(): void
    {
        $game = Game::factory()->create();
        $guild = DiscordGuild::factory()->create();
        DiscordCardMessage::create([
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => '1',
            'message_id' => '1',
        ]);

        $this->expectException(QueryException::class);

        DiscordCardMessage::create([
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => '1',
            'message_id' => '2',
        ]);
    }

    // ── Negative: cascade-on-delete ──────────────────────────

    public function test_guild_deletion_cascades_to_organizers_and_cards(): void
    {
        $guild = DiscordGuild::factory()->create();
        $organizer = DiscordGuildOrganizer::factory()
            ->for($guild, 'guild')
            ->for(User::factory())
            ->create();
        $card = DiscordCardMessage::create([
            'game_id' => Game::factory()->create()->id,
            'guild_id' => $guild->id,
            'channel_id' => '1',
            'message_id' => '1',
        ]);

        $guild->delete();

        $this->assertDatabaseMissing('discord_guild_organizers', ['id' => $organizer->id]);
        $this->assertDatabaseMissing('discord_card_messages', ['id' => $card->id]);
    }

    public function test_owner_user_deletion_cascades_to_guild(): void
    {
        $owner = User::factory()->create();
        $guild = DiscordGuild::factory()->create(['owner_user_id' => $owner->id]);

        $owner->delete();

        $this->assertDatabaseMissing('discord_guilds', ['id' => $guild->id]);
    }

    public function test_game_deletion_cascades_to_card_message(): void
    {
        $game = Game::factory()->create();
        $guild = DiscordGuild::factory()->create();
        $card = DiscordCardMessage::create([
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => '1',
            'message_id' => '1',
        ]);

        $game->delete();

        $this->assertDatabaseMissing('discord_card_messages', ['id' => $card->id]);
    }

    public function test_organizer_user_deletion_cascades_to_organizer_row(): void
    {
        $user = User::factory()->create();
        $organizer = DiscordGuildOrganizer::factory()
            ->for(DiscordGuild::factory(), 'guild')
            ->for($user)
            ->create();

        $user->delete();

        $this->assertDatabaseMissing('discord_guild_organizers', ['id' => $organizer->id]);
    }

    // ── Negative: opt-out preserves audit timestamp ──────────

    public function test_opt_out_flips_publish_enabled_without_clearing_first_opt_in(): void
    {
        $organizer = DiscordGuildOrganizer::factory()->optedIn()->create();
        $firstOptIn = $organizer->opted_in_at;

        $organizer->update(['publish_enabled' => false]);

        $this->assertFalse($organizer->fresh()->publish_enabled);
        $this->assertEquals($firstOptIn, $organizer->fresh()->opted_in_at);
    }
}
