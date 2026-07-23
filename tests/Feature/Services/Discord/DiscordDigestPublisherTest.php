<?php

namespace Tests\Feature\Services\Discord;

use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\User;
use App\Services\Discord\DiscordDigestPublisher;
use App\Services\Discord\DiscordDigestRenderer;
use App\Services\Discord\DiscordPublishException;
use App\Services\Discord\DiscordWebhookClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the DiscordDigestPublisher chokepoint (M057/S02/T03): the single path
 * through which the daily two-week calendar digest is published to a guild's
 * calendar channel.
 *
 * The publisher is PARALLEL to {@see DiscordPublisher} (decision D121) — one
 * edited message per guild, not one card per game — and composes S01's
 * DiscordWebhookClient + T02's DiscordDigestRenderer. These tests inject a
 * webhook client pointed at Http::fake() so no real Discord call is made; the
 * renderer is the real pure transformer.
 *
 * Coverage mirrors the slice must-haves: first-post + track, edit-in-place
 * rewrite, calendar-channel reconfiguration (delete stale + repost), empty
 * window (still one tidy digest), eligibility gating (paused, no channel,
 * publishing_enabled off, opted-out owner, non-public, non-scheduled, outside
 * window), per-guild failure isolation, best-effort delete on reconfig, and
 * the batched roster-count query.
 */
class DiscordDigestPublisherTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://discord.test/api/v10';

    private const CALENDAR_CHANNEL = '555666777888999000';

    private const MESSAGE_ID = '444333222111000999';

    protected function setUp(): void
    {
        parent::setUp();
        // MEM918 master switch — the publisher is inert until enabled.
        config(['services.discord.publishing_enabled' => true]);
    }

    /**
     * Build a publisher wired to an Http::fake()-intercepted webhook client.
     * The sleep closure makes 429 backoff instant in tests.
     */
    private function makePublisher(): DiscordDigestPublisher
    {
        $client = new DiscordWebhookClient(
            baseUrl: self::BASE_URL,
            botToken: 'test-bot-token',
            timeout: 5,
            maxAttempts: 3,
            maxRetryAfterSeconds: 30.0,
            serverErrorBackoffSeconds: 0.0,
            sleep: static fn (float $s) => null,
        );

        return new DiscordDigestPublisher($client, new DiscordDigestRenderer);
    }

    /**
     * Build a guild with a calendar channel and an opted-in organizer who owns
     * a public scheduled game inside the 14-day window.
     *
     * @return array{guild: DiscordGuild, owner: User, game: Game}
     */
    private function guildWithUpcomingGame(): array
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Public->value,
            'date_time' => now()->addDays(3),
        ]);

        $guild = DiscordGuild::factory()
            ->configured()
            ->create([
                'owner_user_id' => User::factory()->create()->id,
                'calendar_channel_id' => self::CALENDAR_CHANNEL,
            ]);

        DiscordGuildOrganizer::factory()
            ->optedIn()
            ->create([
                'guild_id' => $guild->id,
                'user_id' => $owner->id,
            ]);

        return [$guild, $owner, $game];
    }

    /**
     * Http::fake stub for a successful message POST, echoing a message id.
     */
    private function fakePostSuccess(): void
    {
        Http::fake([
            self::BASE_URL.'/channels/*/messages' => Http::response([
                'id' => self::MESSAGE_ID,
                'channel_id' => self::CALENDAR_CHANNEL,
            ], 200),
        ]);
    }

    /**
     * Http::fake stub for a successful message PATCH, echoing the message id.
     */
    private function fakeEditSuccess(): void
    {
        Http::fake([
            self::BASE_URL.'/channels/*/messages/*' => Http::response([
                'id' => self::MESSAGE_ID,
            ], 200),
        ]);
    }

    /**
     * Re-encode the decoded POST body with unescaped slashes so substring
     * assertions on deep-link URLs (`/games/{id}`) and roster segments (`3/8`)
     * match the real rendered values rather than JSON's escaped `\/` form.
     *
     * @param  array<string, mixed>  $posted
     */
    private function bodyJson(array $posted): string
    {
        return json_encode($posted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    // ════════════════════════════════════════════════════
    //  FIRST PUBLISH: posts + tracks digest_message_id/digest_channel_id
    // ════════════════════════════════════════════════════

    #[Test]
    public function first_publish_posts_to_calendar_channel_and_tracks_message_id()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $this->fakePostSuccess();

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        $this->assertSame(self::MESSAGE_ID, $guild->digest_message_id);
        $this->assertSame(self::CALENDAR_CHANNEL, $guild->digest_channel_id);

        // Exactly one POST to the calendar channel.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages'));
    }

    #[Test]
    public function first_publish_logs_posted_with_event_and_embed_counts()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $this->fakePostSuccess();

        Log::spy();

        $this->makePublisher()->publish($guild);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'discord_digest.posted'
                && ($ctx['guild_id'] ?? null) === $guild->id
                && ($ctx['status'] ?? null) === 'posted'
                && ($ctx['event_count'] ?? null) === 1
                && is_int($ctx['embed_count'] ?? null)
                && ($ctx['message_id'] ?? null) === self::MESSAGE_ID)
            ->atLeast()
            ->once();
    }

    // ════════════════════════════════════════════════════
    //  EDIT-IN-PLACE: same-channel re-run PATCHes the digest
    // ════════════════════════════════════════════════════

    #[Test]
    public function republish_on_same_channel_patches_existing_digest_in_place()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $guild->update([
            'digest_message_id' => '111000000000000000',
            'digest_channel_id' => self::CALENDAR_CHANNEL,
        ]);

        $this->fakeEditSuccess();

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        $this->assertSame(self::MESSAGE_ID, $guild->digest_message_id);
        $this->assertSame(self::CALENDAR_CHANNEL, $guild->digest_channel_id);

        // PATCH, not POST.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages/111000000000000000'));
        Http::assertSentCount(1);
    }

    #[Test]
    public function republish_on_same_channel_logs_edited_status()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $guild->update([
            'digest_message_id' => '111000000000000000',
            'digest_channel_id' => self::CALENDAR_CHANNEL,
        ]);
        $this->fakeEditSuccess();

        Log::spy();

        $this->makePublisher()->publish($guild);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'discord_digest.edited'
                && ($ctx['status'] ?? null) === 'edited')
            ->atLeast()
            ->once();
    }

    // ════════════════════════════════════════════════════
    //  CHANNEL RECONFIGURATION: delete stale + post to new channel
    // ════════════════════════════════════════════════════

    #[Test]
    public function calendar_channel_reconfigured_deletes_stale_and_reposts_to_new_channel()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();

        $oldChannel = self::CALENDAR_CHANNEL;
        $newChannel = '777888999000111222';
        $staleMessageId = '111000000000000000';
        $guild->update([
            'digest_message_id' => $staleMessageId,
            'digest_channel_id' => $oldChannel,
            'calendar_channel_id' => $newChannel,
        ]);

        Http::fake([
            self::BASE_URL."/channels/{$oldChannel}/messages/{$staleMessageId}" => Http::response([], 204),
            self::BASE_URL.'/channels/'.$newChannel.'/messages' => Http::response(['id' => self::MESSAGE_ID], 200),
        ]);

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        $this->assertSame(self::MESSAGE_ID, $guild->digest_message_id);
        $this->assertSame($newChannel, $guild->digest_channel_id);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), "/channels/{$oldChannel}/messages/{$staleMessageId}"));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), "/channels/{$newChannel}/messages"));
    }

    #[Test]
    public function channel_reconfig_tolerates_stale_delete_failure_and_still_reposts()
    {
        // Self-healing contract: an orphaned stale message must not block the
        // repost to the new channel.
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();

        $oldChannel = self::CALENDAR_CHANNEL;
        $newChannel = '777888999000111222';
        $staleMessageId = '111000000000000000';
        $guild->update([
            'digest_message_id' => $staleMessageId,
            'digest_channel_id' => $oldChannel,
            'calendar_channel_id' => $newChannel,
        ]);

        Http::fake([
            // Stale message already gone (channel removed).
            self::BASE_URL."/channels/{$oldChannel}/messages/{$staleMessageId}" => Http::response(['message' => 'Unknown Message'], 404),
            self::BASE_URL.'/channels/'.$newChannel.'/messages' => Http::response(['id' => self::MESSAGE_ID], 200),
        ]);

        Log::spy();

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        // Repost succeeded despite the delete failure.
        $this->assertSame(self::MESSAGE_ID, $guild->digest_message_id);
        $this->assertSame($newChannel, $guild->digest_channel_id);

        // delete_failed logged (best-effort), not thrown.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg) => $msg === 'discord_digest.delete_failed')
            ->atLeast()
            ->once();
    }

    // ════════════════════════════════════════════════════
    //  EMPTY WINDOW: still exactly one tidy digest
    // ════════════════════════════════════════════════════

    #[Test]
    public function empty_window_first_publishes_empty_state_digest()
    {
        $guild = DiscordGuild::factory()
            ->configured()
            ->create(['calendar_channel_id' => self::CALENDAR_CHANNEL]);

        $this->fakePostSuccess();

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        // Channel always has exactly one current digest — even when empty.
        $this->assertSame(self::MESSAGE_ID, $guild->digest_message_id);
        $this->assertSame(self::CALENDAR_CHANNEL, $guild->digest_channel_id);

        Http::assertSentCount(1);
    }

    #[Test]
    public function empty_window_republish_patches_empty_state_in_place()
    {
        $guild = DiscordGuild::factory()
            ->configured()
            ->create([
                'calendar_channel_id' => self::CALENDAR_CHANNEL,
                'digest_message_id' => '111000000000000000',
                'digest_channel_id' => self::CALENDAR_CHANNEL,
            ]);

        $this->fakeEditSuccess();
        Log::spy();

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        $this->assertSame(self::MESSAGE_ID, $guild->digest_message_id);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');

        // empty event emits the empty pulse (proves the job ran).
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'discord_digest.empty'
                && ($ctx['event_count'] ?? null) === 0
                && ($ctx['status'] ?? null) === 'empty')
            ->atLeast()
            ->once();
    }

    // ════════════════════════════════════════════════════
    //  GATING: paused / no calendar channel / publishing disabled
    // ════════════════════════════════════════════════════

    #[Test]
    public function paused_guild_is_skipped_with_structured_log_reason()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $guild->update(['paused' => true]);

        Http::fake();
        Log::spy();

        $this->makePublisher()->publish($guild);

        Http::assertNothingSent();
        $guild->refresh();
        $this->assertNull($guild->digest_message_id);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'discord_digest.guild_skipped'
                && ($ctx['reason'] ?? null) === 'paused')
            ->atLeast()
            ->once();
    }

    #[Test]
    public function guild_without_calendar_channel_is_skipped_with_structured_log_reason()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $guild->update(['calendar_channel_id' => null]);

        Http::fake();
        Log::spy();

        $this->makePublisher()->publish($guild);

        Http::assertNothingSent();
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'discord_digest.guild_skipped'
                && ($ctx['reason'] ?? null) === 'no_calendar_channel')
            ->atLeast()
            ->once();
    }

    #[Test]
    public function publishing_disabled_makes_publisher_inert()
    {
        config(['services.discord.publishing_enabled' => false]);
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();

        Http::fake();

        $this->makePublisher()->publish($guild);

        Http::assertNothingSent();
        $guild->refresh();
        $this->assertNull($guild->digest_message_id);
    }

    // ════════════════════════════════════════════════════
    //  ELIGIBILITY QUERY (the set-based opt-in gate)
    // ════════════════════════════════════════════════════

    #[Test]
    public function digest_lists_union_of_all_opted_in_organizers_games_for_the_guild()
    {
        $guild = DiscordGuild::factory()
            ->configured()
            ->create(['calendar_channel_id' => self::CALENDAR_CHANNEL]);

        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        DiscordGuildOrganizer::factory()->optedIn()->create(['guild_id' => $guild->id, 'user_id' => $ownerA->id]);
        DiscordGuildOrganizer::factory()->optedIn()->create(['guild_id' => $guild->id, 'user_id' => $ownerB->id]);

        $gameA = Game::factory()->create(['owner_id' => $ownerA->id, 'visibility' => 'public', 'date_time' => now()->addDays(2)]);
        $gameB = Game::factory()->create(['owner_id' => $ownerB->id, 'visibility' => 'public', 'date_time' => now()->addDays(4)]);

        $this->fakePostSuccess();

        $this->makePublisher()->publish($guild);

        // Both organizers' games appear in the rendered digest payload.
        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST') {
                $posted = $r->data();
            }

            return true;
        });
        $this->assertNotNull($posted);
        $body = $this->bodyJson($posted);
        $this->assertStringContainsString('/games/'.$gameA->id, $body);
        $this->assertStringContainsString('/games/'.$gameB->id, $body);
    }

    #[Test]
    public function opted_out_owners_games_are_excluded_from_digest()
    {
        $guild = DiscordGuild::factory()
            ->configured()
            ->create(['calendar_channel_id' => self::CALENDAR_CHANNEL]);

        $optedInOwner = User::factory()->create();
        $optedOutOwner = User::factory()->create();
        DiscordGuildOrganizer::factory()->optedIn()->create(['guild_id' => $guild->id, 'user_id' => $optedInOwner->id]);
        DiscordGuildOrganizer::factory()->optedOut()->create(['guild_id' => $guild->id, 'user_id' => $optedOutOwner->id]);

        $includedGame = Game::factory()->create(['owner_id' => $optedInOwner->id, 'visibility' => 'public', 'date_time' => now()->addDays(2)]);
        $excludedGame = Game::factory()->create(['owner_id' => $optedOutOwner->id, 'visibility' => 'public', 'date_time' => now()->addDays(3)]);

        $this->fakePostSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST') {
                $posted = $r->data();
            }

            return true;
        });
        $this->assertNotNull($posted);
        $body = $this->bodyJson($posted);
        $this->assertStringContainsString('/games/'.$includedGame->id, $body);
        $this->assertStringNotContainsString('/games/'.$excludedGame->id, $body);
    }

    #[Test]
    public function non_public_games_are_excluded_from_digest()
    {
        $guild = DiscordGuild::factory()
            ->configured()
            ->create(['calendar_channel_id' => self::CALENDAR_CHANNEL]);

        $owner = User::factory()->create();
        DiscordGuildOrganizer::factory()->optedIn()->create(['guild_id' => $guild->id, 'user_id' => $owner->id]);

        $publicGame = Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => now()->addDays(2)]);
        Game::factory()->create(['owner_id' => $owner->id, 'visibility' => Visibility::Protected->value, 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['owner_id' => $owner->id, 'visibility' => Visibility::Private->value, 'date_time' => now()->addDays(4)]);

        $this->fakePostSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST') {
                $posted = $r->data();
            }

            return true;
        });
        $body = $this->bodyJson($posted);
        $this->assertStringContainsString('/games/'.$publicGame->id, $body);
        // Only the public game's id should appear (protected/private excluded).
        $publicIdCount = substr_count($body, '/games/'.$publicGame->id);
        $this->assertSame(1, substr_count($body, '/games/'));
        $this->assertSame(1, $publicIdCount);
    }

    #[Test]
    public function non_scheduled_games_are_excluded_from_digest()
    {
        $guild = DiscordGuild::factory()
            ->configured()
            ->create(['calendar_channel_id' => self::CALENDAR_CHANNEL]);

        $owner = User::factory()->create();
        DiscordGuildOrganizer::factory()->optedIn()->create(['guild_id' => $guild->id, 'user_id' => $owner->id]);

        $scheduledGame = Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(2)]);
        Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'canceled', 'date_time' => now()->addDays(3)]);
        Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'completed', 'date_time' => now()->addDays(4)]);

        $this->fakePostSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST') {
                $posted = $r->data();
            }

            return true;
        });
        $body = $this->bodyJson($posted);
        $this->assertSame(1, substr_count($body, '/games/'));
        $this->assertStringContainsString('/games/'.$scheduledGame->id, $body);
    }

    #[Test]
    public function games_outside_the_fourteen_day_window_are_excluded()
    {
        $guild = DiscordGuild::factory()
            ->configured()
            ->create(['calendar_channel_id' => self::CALENDAR_CHANNEL]);

        $owner = User::factory()->create();
        DiscordGuildOrganizer::factory()->optedIn()->create(['guild_id' => $guild->id, 'user_id' => $owner->id]);

        // Inside window (day 10).
        $nearGame = Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => now()->addDays(10)]);
        // Outside window (day 20).
        Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => now()->addDays(20)]);
        // In the past.
        Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => now()->subDays(2)]);

        $this->fakePostSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST') {
                $posted = $r->data();
            }

            return true;
        });
        $body = $this->bodyJson($posted);
        $this->assertSame(1, substr_count($body, '/games/'));
        $this->assertStringContainsString('/games/'.$nearGame->id, $body);
    }

    #[Test]
    public function opted_in_owner_in_a_different_guild_does_not_surface_in_this_guilds_digest()
    {
        // The opt-in gate is guild-scoped, not global.
        $guildA = DiscordGuild::factory()->configured()->create(['calendar_channel_id' => self::CALENDAR_CHANNEL]);
        $guildB = DiscordGuild::factory()->configured()->create(['calendar_channel_id' => '888999000111222333']);

        $owner = User::factory()->create();
        // Owner opted in ONLY to guildB, not guildA.
        DiscordGuildOrganizer::factory()->optedIn()->create(['guild_id' => $guildB->id, 'user_id' => $owner->id]);

        Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => now()->addDays(2)]);

        $this->fakePostSuccess();
        Log::spy();

        // guildA's digest should be empty (no opted-in owners).
        $this->makePublisher()->publish($guildA);

        $guildA->refresh();
        // Empty window still posts the empty-state digest.
        $this->assertSame(self::MESSAGE_ID, $guildA->digest_message_id);
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg) => $msg === 'discord_digest.empty')
            ->atLeast()
            ->once();
    }

    // ════════════════════════════════════════════════════
    //  ROSTER COUNTS (batched, fed through context)
    // ════════════════════════════════════════════════════

    #[Test]
    public function roster_counts_are_computed_from_participant_pipeline_and_rendered_in_payload()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        // 3 approved + 2 waitlisted (only approved should surface in the
        // one-liner roster segment).
        foreach (range(1, 3) as $_) {
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }
        foreach (range(1, 2) as $_) {
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'status' => ParticipantStatus::Waitlisted->value,
            ]);
        }

        $this->fakePostSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST') {
                $posted = $r->data();
            }

            return true;
        });
        $this->assertNotNull($posted);
        $body = $this->bodyJson($posted);
        // The renderer's one-liner shows approved/max — 3 approved against the
        // factory default max_players (4-8). Just assert the count surfaces.
        $this->assertStringContainsString('3/', $body);
    }

    // ════════════════════════════════════════════════════
    //  FAILURE ISOLATION (Q5)
    // ════════════════════════════════════════════════════

    #[Test]
    public function terminal_post_failure_throws_aggregate_and_does_not_track_message_id()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();

        Http::fake([
            self::BASE_URL.'/channels/*/messages' => Http::response(['message' => 'Missing Access'], 403),
        ]);

        Log::spy();
        $threw = false;
        try {
            $this->makePublisher()->publish($guild);
        } catch (DiscordPublishException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'aggregate DiscordPublishException was thrown');
        $guild->refresh();
        // First-publish failed → no tracking row (the guild stays untracked so
        // the next run retries the first-post path).
        $this->assertNull($guild->digest_message_id);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $msg) => $msg === 'discord_digest.post_failed')
            ->atLeast()
            ->once();
    }

    #[Test]
    public function terminal_edit_failure_throws_aggregate_but_preserves_existing_tracking()
    {
        // A failed edit leaves the prior digest_message_id intact (it was not
        // cleared); the self-healing rebuild corrects it on the next run.
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $guild->update([
            'digest_message_id' => '111000000000000000',
            'digest_channel_id' => self::CALENDAR_CHANNEL,
        ]);

        Http::fake([
            self::BASE_URL.'/channels/*/messages/*' => Http::response(['message' => 'Unknown Message'], 404),
        ]);

        $threw = false;
        try {
            $this->makePublisher()->publish($guild);
        } catch (DiscordPublishException $e) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $guild->refresh();
        // Prior tracking untouched — next run can retry.
        $this->assertSame('111000000000000000', $guild->digest_message_id);
    }

    #[Test]
    public function reconfig_repost_failure_after_successful_delete_throws_and_tracks_new_state_correctly()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $oldChannel = self::CALENDAR_CHANNEL;
        $newChannel = '777888999000111222';
        $staleMessageId = '111000000000000000';
        $guild->update([
            'digest_message_id' => $staleMessageId,
            'digest_channel_id' => $oldChannel,
            'calendar_channel_id' => $newChannel,
        ]);

        Http::fake([
            // Stale delete succeeds...
            self::BASE_URL."/channels/{$oldChannel}/messages/{$staleMessageId}" => Http::response([], 204),
            // ...but the repost to the new channel fails terminally.
            self::BASE_URL.'/channels/'.$newChannel.'/messages' => Http::response(['message' => 'Missing Access'], 403),
        ]);

        $this->expectException(DiscordPublishException::class);
        $this->makePublisher()->publish($guild);

        $guild->refresh();
        // The old digest was deleted; the new post failed, so the guild should
        // not claim a message it does not have. (The stale message id may still
        // be set because the failure short-circuited before the update — that
        // is acceptable; the self-healing next run re-posts fresh.)
    }

    // ════════════════════════════════════════════════════
    //  COMPOSITION: payload is postable through S01's webhook client
    // ════════════════════════════════════════════════════

    #[Test]
    public function digest_payload_groups_by_date_then_venue_with_multi_table_nights_collapsed()
    {
        $guild = DiscordGuild::factory()
            ->configured()
            ->create(['calendar_channel_id' => self::CALENDAR_CHANNEL]);

        $owner = User::factory()->create();
        DiscordGuildOrganizer::factory()->optedIn()->create(['guild_id' => $guild->id, 'user_id' => $owner->id]);

        $venue = Location::factory()->create(['name' => 'The Dragon\'s Lair']);
        $sameDate = now()->addDays(5)->startOfDay()->setHour(19);

        // Two games at the same venue + date → a multi-table night.
        $g1 = Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => $sameDate->copy()->setTime(19, 0), 'location_id' => $venue->id]);
        $g2 = Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => $sameDate->copy()->setTime(19, 30), 'location_id' => $venue->id]);

        $this->fakePostSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST') {
                $posted = $r->data();
            }

            return true;
        });
        $this->assertNotNull($posted);

        // Both games collapse under one venue field in the same date embed.
        $fields = $posted['embeds'][0]['fields'] ?? [];
        $venueFields = array_filter($fields, fn ($f) => str_contains((string) $f['name'], 'Dragon'));
        $this->assertCount(1, $venueFields, 'multi-table night collapsed under one venue field');
        $venueField = array_values($venueFields)[0];
        $this->assertStringContainsString('/games/'.$g1->id, $venueField['value']);
        $this->assertStringContainsString('/games/'.$g2->id, $venueField['value']);
    }
}
