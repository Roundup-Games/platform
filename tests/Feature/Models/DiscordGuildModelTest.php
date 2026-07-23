<?php

namespace Tests\Feature\Models;

use App\Enums\DiscordCardStatus;
use App\Enums\DiscordModerationMode;
use App\Models\DiscordCardMessage;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
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
        $this->assertSame(DiscordModerationMode::Open, $guild->moderation_mode);
        $this->assertNull($guild->calendar_channel_id);
        $this->assertNull($guild->games_channel_id);
    }

    public function test_configured_factory_state_sets_both_channels(): void
    {
        $guild = DiscordGuild::factory()->configured()->create();

        $this->assertNotNull($guild->calendar_channel_id);
        $this->assertNotNull($guild->games_channel_id);
    }

    // ── DiscordGuild digest tracking (M057/S02) ──────────────

    public function test_digest_tracking_columns_default_to_null(): void
    {
        $guild = DiscordGuild::factory()->create();

        $this->assertNull($guild->digest_message_id);
        $this->assertNull($guild->digest_channel_id);
    }

    public function test_digest_tracking_columns_are_mass_assignable(): void
    {
        $guild = DiscordGuild::factory()->create([
            'digest_message_id' => '999000111222333444',
            'digest_channel_id' => '555666777888',
        ]);

        $fresh = $guild->fresh();
        $this->assertSame('999000111222333444', $fresh->digest_message_id);
        $this->assertSame('555666777888', $fresh->digest_channel_id);
    }

    public function test_digest_message_id_can_be_updated_in_place(): void
    {
        $guild = DiscordGuild::factory()->create();
        $this->assertNull($guild->digest_message_id);

        $guild->update([
            'digest_message_id' => '123456789012345678',
            'digest_channel_id' => '876543210987654321',
        ]);

        $fresh = $guild->fresh();
        $this->assertSame('123456789012345678', $fresh->digest_message_id);
        $this->assertSame('876543210987654321', $fresh->digest_channel_id);
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

    // ── User->discordGuildOrganizers relation (M057/S02) ─────

    public function test_user_has_many_discord_guild_organizer_rows(): void
    {
        $user = User::factory()->create();
        $guild = DiscordGuild::factory()->create();
        $organizer = DiscordGuildOrganizer::factory()
            ->for($guild, 'guild')
            ->for($user)
            ->create();

        $this->assertTrue($user->discordGuildOrganizers->contains($organizer));
        $this->assertCount(1, $user->discordGuildOrganizers);
    }

    public function test_user_discord_guild_organizers_scoped_to_the_user_only(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $guild = DiscordGuild::factory()->create();
        DiscordGuildOrganizer::factory()->for($guild, 'guild')->for($owner)->create();
        DiscordGuildOrganizer::factory()->for($guild, 'guild')->for($other)->create();

        $this->assertCount(1, $owner->fresh()->discordGuildOrganizers);
        $this->assertCount(1, $other->fresh()->discordGuildOrganizers);
    }

    public function test_digest_eligibility_wherehas_traverses_owner_organizers(): void
    {
        // Mirrors the digest publisher's eligibility query shape:
        // games whose owner has a publish_enabled=true row for THIS guild.
        $guild = DiscordGuild::factory()->create();
        $optedInOwner = User::factory()->create();
        $optedOutOwner = User::factory()->create();
        DiscordGuildOrganizer::factory()->optedIn()->for($guild, 'guild')->for($optedInOwner)->create();
        DiscordGuildOrganizer::factory()->optedOut()->for($guild, 'guild')->for($optedOutOwner)->create();
        $eligibleGame = Game::factory()->create(['owner_id' => $optedInOwner->id]);
        $ineligibleGame = Game::factory()->create(['owner_id' => $optedOutOwner->id]);

        $found = Game::query()
            ->whereHas('owner.discordGuildOrganizers', fn ($q) => $q
                ->where('guild_id', $guild->id)
                ->where('publish_enabled', true))
            ->pluck('id');

        $this->assertContains($eligibleGame->id, $found);
        $this->assertNotContains($ineligibleGame->id, $found);
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

    // ── M057/S07: moderation-flex enums ──────────────────────

    public function test_discord_moderation_mode_enum_values(): void
    {
        $this->assertSame('open', DiscordModerationMode::Open->value);
        $this->assertSame('review', DiscordModerationMode::Review->value);
        $this->assertSame(['open', 'review'], DiscordModerationMode::values());
    }

    public function test_discord_card_status_enum_values(): void
    {
        $this->assertSame('posted', DiscordCardStatus::Posted->value);
        $this->assertSame('pending', DiscordCardStatus::Pending->value);
        $this->assertSame('rejected', DiscordCardStatus::Rejected->value);
        $this->assertSame('expired', DiscordCardStatus::Expired->value);
        $this->assertSame(
            ['posted', 'pending', 'rejected', 'expired'],
            DiscordCardStatus::values(),
        );
    }

    // ── M057/S07: DiscordGuild moderation_mode cast ──────────

    public function test_moderation_mode_is_cast_to_enum(): void
    {
        $guild = DiscordGuild::factory()->create();

        $this->assertInstanceOf(DiscordModerationMode::class, $guild->moderation_mode);
        $this->assertSame(DiscordModerationMode::Open, $guild->moderation_mode);
    }

    public function test_moderation_mode_round_trips_through_database_as_string(): void
    {
        // The column is a plain string; the cast serializes the enum value.
        $guild = DiscordGuild::factory()->create(['moderation_mode' => DiscordModerationMode::Review]);

        $this->assertSame(DiscordModerationMode::Review, $guild->fresh()->moderation_mode);
        $this->assertDatabaseHas('discord_guilds', [
            'id' => $guild->id,
            'moderation_mode' => 'review',
        ]);
    }

    public function test_moderation_mode_assignable_from_string_value(): void
    {
        $guild = DiscordGuild::factory()->create(['moderation_mode' => 'review']);

        $this->assertSame(DiscordModerationMode::Review, $guild->fresh()->moderation_mode);
    }

    public function test_guild_factory_review_state_sets_review_mode(): void
    {
        $guild = DiscordGuild::factory()->review()->create();

        $this->assertSame(DiscordModerationMode::Review, $guild->moderation_mode);
    }

    // ── M057/S07: DiscordCardMessage lifecycle ───────────────

    public function test_card_message_status_casts_to_posted_by_default(): void
    {
        $card = DiscordCardMessage::create([
            'game_id' => Game::factory()->create()->id,
            'guild_id' => DiscordGuild::factory()->create()->id,
            'channel_id' => '1',
            'message_id' => '2',
        ]);

        // No status passed: DB default 'posted' applies, cast resolves to enum.
        $fresh = $card->fresh();
        $this->assertInstanceOf(DiscordCardStatus::class, $fresh->status);
        $this->assertSame(DiscordCardStatus::Posted, $fresh->status);
    }

    public function test_card_message_lifecycle_columns_default_to_null(): void
    {
        $card = DiscordCardMessage::create([
            'game_id' => Game::factory()->create()->id,
            'guild_id' => DiscordGuild::factory()->create()->id,
            'channel_id' => '1',
            'message_id' => '2',
        ]);

        $fresh = $card->fresh();
        $this->assertNull($fresh->moderator_user_id);
        $this->assertNull($fresh->moderated_at);
        $this->assertNull($fresh->expires_at);
    }

    public function test_card_message_status_is_mass_assignable(): void
    {
        $card = DiscordCardMessage::factory()->create([
            'status' => DiscordCardStatus::Pending,
        ]);

        $this->assertSame(DiscordCardStatus::Pending, $card->fresh()->status);
        $this->assertDatabaseHas('discord_card_messages', [
            'id' => $card->id,
            'status' => 'pending',
        ]);
    }

    public function test_card_message_belongs_to_moderator_user(): void
    {
        $moderator = User::factory()->create();
        $card = DiscordCardMessage::factory()->create([
            'moderator_user_id' => $moderator->id,
        ]);

        $this->assertTrue($card->moderator->is($moderator));
    }

    public function test_card_message_moderator_relation_is_null_by_default(): void
    {
        $card = DiscordCardMessage::factory()->create();

        $this->assertNull($card->moderator);
    }

    // ── M057/S07: factory states ─────────────────────────────

    public function test_card_message_factory_defaults_to_posted_with_message_id(): void
    {
        $card = DiscordCardMessage::factory()->create();

        $this->assertSame(DiscordCardStatus::Posted, $card->status);
        $this->assertNotNull($card->message_id);
    }

    public function test_card_message_factory_pending_state_sets_pending_and_null_message_id(): void
    {
        $card = DiscordCardMessage::factory()->pending()->create();

        $this->assertSame(DiscordCardStatus::Pending, $card->status);
        $this->assertNull($card->message_id);
        $this->assertNotNull($card->expires_at);
    }

    // ── M057/S07: migration shape (schema introspection) ─────

    public function test_migration_makes_message_id_nullable(): void
    {
        $column = $this->column('discord_card_messages', 'message_id');

        $this->assertTrue($column['nullable'], 'message_id should be nullable after the S07 migration');
    }

    public function test_migration_adds_status_column_defaulted_to_posted(): void
    {
        $column = $this->column('discord_card_messages', 'status');

        $this->assertNotNull($column, 'status column should exist');
        // DBAL may quote string defaults ('posted') depending on driver; normalize.
        $this->assertSame('posted', $this->normalizeDefault($column['default']), "status column should default to 'posted'");
    }

    public function test_migration_adds_nullable_lifecycle_columns(): void
    {
        foreach (['moderator_user_id', 'moderated_at', 'expires_at'] as $name) {
            $column = $this->column('discord_card_messages', $name);

            $this->assertNotNull($column, "{$name} column should exist");
            $this->assertTrue($column['nullable'], "{$name} should be nullable");
            $this->assertNull($column['default'], "{$name} should have no default (NULL in v1)");
        }
    }

    public function test_migration_adds_moderator_user_id_foreign_key_to_users(): void
    {
        $toUsers = collect(Schema::getForeignKeys('discord_card_messages'))
            ->firstWhere('columns', ['moderator_user_id']);

        $this->assertNotNull($toUsers, 'moderator_user_id should have a foreign key');
        $this->assertSame(['id'], $toUsers['foreign_columns']);
        $this->assertSame('users', $toUsers['foreign_table']);
        $this->assertSame('set null', $toUsers['on_delete']);
    }

    public function test_migration_preserves_unique_game_guild_index(): void
    {
        $indexes = Schema::getIndexes('discord_card_messages');
        $uniquePair = collect($indexes)->first(fn ($i) => ($i['type'] === 'unique' || ! empty($i['unique']))
            && $i['columns'] === ['game_id', 'guild_id']);

        $this->assertNotNull(
            $uniquePair,
            'unique(game_id, guild_id) index must be preserved — it keys the per-(game,guild) card slot',
        );
    }

    /**
     * Backfill-safety: a card created with only the S01 columns lands at the
     * v1 default state (status=posted, lifecycle NULL) without losing message_id.
     */
    public function test_backfill_safe_open_path_row_has_posted_status_and_message_id(): void
    {
        $card = DiscordCardMessage::create([
            'game_id' => Game::factory()->create()->id,
            'guild_id' => DiscordGuild::factory()->create()->id,
            'channel_id' => '111',
            'message_id' => '222',
        ]);

        $row = $card->fresh();
        $this->assertSame(DiscordCardStatus::Posted, $row->status);
        $this->assertSame('222', $row->message_id);
        $this->assertNull($row->moderator_user_id);
        $this->assertNull($row->moderated_at);
        $this->assertNull($row->expires_at);
    }

    // ── M057/S07: negative — moderator_user_id cascade nullOnDelete ──

    public function test_deleting_moderator_nulls_card_moderator_user_id(): void
    {
        $moderator = User::factory()->create();
        $card = DiscordCardMessage::factory()->create([
            'moderator_user_id' => $moderator->id,
        ]);

        $moderator->delete();

        $this->assertNull($card->fresh()->moderator_user_id);
    }

    /**
     * @return array{name: string, type: string, nullable: bool, default: mixed}
     */
    private function column(string $table, string $name): array
    {
        return collect(Schema::getColumns($table))->firstWhere('name', $name);
    }

    /**
     * Normalize a DBAL column default to its bare value.
     *
     * Postgres casts string defaults like 'posted'::character varying (quoted
     * value + a ::type suffix); other drivers return the bare quoted value.
     * Strip the cast suffix first, then the surrounding quotes.
     */
    private function normalizeDefault(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }

        $default = (string) $default;

        // Postgres casts string defaults like 'posted'::character varying —
        // drop the ::type suffix before stripping surrounding quotes.
        if (str_contains($default, '::')) {
            $default = explode('::', $default, 2)[0];
        }

        return trim($default, "'\"");
    }
}
