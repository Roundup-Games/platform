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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the DiscordDigestPublisher chokepoint — the single path through which
 * the daily two-week calendar digest is published to a guild's calendar channel.
 *
 * Rewritten for M059/S03: the digest is now a DAILY THREAD (one fresh thread
 * per guild per day, anchored on a starter message), replacing M057's single
 * edited message. These tests inject a webhook client pointed at Http::fake()
 * so no real Discord call is made; the renderer is the real pure transformer.
 *
 * Coverage: first-run-of-day (starter + thread create + track), within-day
 * refresh (PATCH starter, no second thread), cross-day (fresh create, previous
 * archived), empty window (daily pulse still creates a thread), legacy
 * single-message retirement (one-time delete), channel-reconfig, 404 self-heal,
 * eligibility gating (paused, no channel, publishing_enabled off, opted-out
 * owner, non-public, non-scheduled, outside window), per-guild failure, and the
 * batched roster-count query.
 */
class DiscordDigestPublisherTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE_URL = 'https://discord.test/api/v10';

    private const CALENDAR_CHANNEL = '555666777888999000';

    private const MESSAGE_ID = '444333222111000999';

    private const THREAD_ID = '999888777666555444';

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
     * @return array{0: DiscordGuild, 1: User, 2: Game}
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
     * Http::fake stub for a successful first-run-of-day: starter POST + thread
     * create. Patterns are ordered most-specific first so the thread create
     * (channels/…/messages/…/threads) matches before the generic message one.
     */
    private function fakeCreateSuccess(string $messageId = self::MESSAGE_ID, string $threadId = self::THREAD_ID): void
    {
        Http::fake([
            self::BASE_URL.'/channels/*/messages/*/threads' => Http::response(['id' => $threadId, 'type' => 11], 200),
            self::BASE_URL.'/channels/*/messages/*' => Http::response(['id' => $messageId], 200), // edit (PATCH) / delete
            self::BASE_URL.'/channels/*/messages' => Http::response(['id' => $messageId, 'channel_id' => self::CALENDAR_CHANNEL], 200),
            self::BASE_URL.'/channels/*' => Http::response([], 204),
        ]);
    }

    /**
     * Http::fake stub for a successful within-day refresh: PATCH the starter.
     */
    private function fakeEditSuccess(): void
    {
        Http::fake([
            self::BASE_URL.'/channels/*/messages/*' => Http::response(['id' => self::MESSAGE_ID], 200),
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
    //  FIRST RUN OF THE DAY: starter POST + thread create + track
    // ════════════════════════════════════════════════════

    #[Test]
    public function first_run_posts_starter_then_creates_thread_and_tracks_the_trio()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $this->fakeCreateSuccess();

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        $this->assertSame(self::MESSAGE_ID, $guild->digest_thread_message_id);
        $this->assertSame(self::CALENDAR_CHANNEL, $guild->digest_thread_channel_id);
        $this->assertSame(now()->toDateString(), $guild->digest_thread_date);

        // Exactly one starter POST + one thread create POST.
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages')
            && ! str_contains($r->url(), '/threads'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/messages/'.self::MESSAGE_ID.'/threads'));
    }

    #[Test]
    public function first_run_logs_created_status_with_event_and_embed_counts()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $this->fakeCreateSuccess();
        Log::spy();

        $this->makePublisher()->publish($guild);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => ($ctx['guild_id'] ?? null) === $guild->id
                && ($ctx['status'] ?? null) === 'created'
                && ($ctx['event_count'] ?? null) === 1
                && is_int($ctx['embed_count'] ?? null)
                && ($ctx['message_id'] ?? null) === self::MESSAGE_ID
                && ($ctx['thread_id'] ?? null) === self::THREAD_ID)
            ->atLeast()
            ->once();
    }

    // ════════════════════════════════════════════════════
    //  WITHIN-DAY REFRESH: same-channel, same-day PATCH of the starter
    // ════════════════════════════════════════════════════

    #[Test]
    public function same_day_rerun_patches_the_starter_without_creating_a_second_thread()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $guild->update([
            'digest_thread_date' => now()->toDateString(),
            'digest_thread_channel_id' => self::CALENDAR_CHANNEL,
            'digest_thread_message_id' => self::MESSAGE_ID,
        ]);

        $this->fakeEditSuccess();

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        $this->assertSame(self::MESSAGE_ID, $guild->digest_thread_message_id);
        $this->assertSame(now()->toDateString(), $guild->digest_thread_date);

        // PATCH the starter, and NO thread-create POST.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages/'.self::MESSAGE_ID));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/threads') ? false : true);
        Http::assertSentCount(1);
    }

    #[Test]
    public function same_day_refresh_logs_refreshed_status()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $guild->update([
            'digest_thread_date' => now()->toDateString(),
            'digest_thread_channel_id' => self::CALENDAR_CHANNEL,
            'digest_thread_message_id' => self::MESSAGE_ID,
        ]);
        $this->fakeEditSuccess();
        Log::spy();

        $this->makePublisher()->publish($guild);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => ($ctx['status'] ?? null) === 'refreshed')
            ->atLeast()
            ->once();
    }

    #[Test]
    public function same_day_refresh_404_self_heals_into_a_fresh_create()
    {
        // The starter was removed out-of-band (moderator delete). A 404 must
        // self-heal into a fresh starter + thread create for today rather than
        // bricking the digest on a dead message id.
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $guild->update([
            'digest_thread_date' => now()->toDateString(),
            'digest_thread_channel_id' => self::CALENDAR_CHANNEL,
            'digest_thread_message_id' => 'dead-message-id',
        ]);

        Http::fake([
            // The 404 on the stale starter...
            self::BASE_URL.'/channels/'.self::CALENDAR_CHANNEL.'/messages/dead-message-id' => Http::response(['message' => 'Unknown Message'], 404),
            // ...then the self-heal create path.
            self::BASE_URL.'/channels/*/messages/*/threads' => Http::response(['id' => self::THREAD_ID, 'type' => 11], 200),
            self::BASE_URL.'/channels/*/messages' => Http::response(['id' => self::MESSAGE_ID, 'channel_id' => self::CALENDAR_CHANNEL], 200),
            self::BASE_URL.'/channels/*' => Http::response([], 204),
        ]);

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        $this->assertSame(self::MESSAGE_ID, $guild->digest_thread_message_id, 'self-healed onto a fresh starter');
        // The create path issued a thread create.
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/messages/'.self::MESSAGE_ID.'/threads'));
    }

    // ════════════════════════════════════════════════════
    //  CROSS-DAY: a new daily run creates a fresh thread, archiving yesterday's
    // ════════════════════════════════════════════════════

    #[Test]
    public function cross_day_run_creates_a_fresh_thread_and_does_not_delete_the_previous()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $yesterday = now()->subDay()->toDateString();
        $yesterdayMessage = '111222333444555666';
        $guild->update([
            'digest_thread_date' => $yesterday,
            'digest_thread_channel_id' => self::CALENDAR_CHANNEL,
            'digest_thread_message_id' => $yesterdayMessage,
        ]);

        $this->fakeCreateSuccess();

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        // New thread tracked for today.
        $this->assertSame(now()->toDateString(), $guild->digest_thread_date);
        $this->assertSame(self::MESSAGE_ID, $guild->digest_thread_message_id);

        // Yesterday's starter is NOT deleted (archived, left read-only).
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages/'.$yesterdayMessage) ? false : true);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), $yesterdayMessage));
    }

    #[Test]
    public function thread_create_failure_after_successful_starter_post_does_not_orphan_the_starter()
    {
        // M059/S03 robustness: the starter is tracked BEFORE the thread create,
        // so a thread-create failure must NOT throw (which would orphan the
        // starter on the next retry — a duplicate POST). It logs a warning,
        // leaves the starter tracked, and the next same-day run refreshes it.
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();

        Http::fake([
            // Starter POST succeeds...
            self::BASE_URL.'/channels/*/messages' => Http::response(['id' => self::MESSAGE_ID, 'channel_id' => self::CALENDAR_CHANNEL], 200),
            // ...but the thread create fails terminally (403).
            self::BASE_URL.'/channels/*/messages/*/threads' => Http::response(['message' => 'Missing Access'], 403),
            self::BASE_URL.'/channels/*' => Http::response([], 204),
        ]);

        // No exception — the thread failure is swallowed (best-effort).
        $this->makePublisher()->publish($guild);

        $guild->refresh();
        // The starter is tracked even though the thread failed — no orphan, so
        // a retry / same-day re-run PATCHes this starter rather than POSTing a
        // duplicate.
        $this->assertSame(self::MESSAGE_ID, $guild->digest_thread_message_id);
        $this->assertSame(now()->toDateString(), $guild->digest_thread_date);
        $this->assertSame(self::CALENDAR_CHANNEL, $guild->digest_thread_channel_id);

        // Exactly one POST to the starter (no duplicate on this run).
        $starterPosts = 0;
        Http::assertSent(function (Request $r) use (&$starterPosts): bool {
            if ($r->method() === 'POST' && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages') && ! str_contains($r->url(), '/threads')) {
                $starterPosts++;
            }

            return true;
        });
        $this->assertSame(1, $starterPosts, 'no duplicate starter when the thread create fails');
    }

    // ════════════════════════════════════════════════════
    //  LEGACY RETIREMENT: first new-model run deletes the old single message
    // ════════════════════════════════════════════════════

    #[Test]
    public function legacy_single_message_is_best_effort_deleted_on_first_new_model_run()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $legacyMessage = '555444333222111000';
        $legacyChannel = self::CALENDAR_CHANNEL;
        $guild->update([
            'digest_message_id' => $legacyMessage,
            'digest_channel_id' => $legacyChannel,
        ]);

        $this->fakeCreateSuccess();

        $this->makePublisher()->publish($guild);

        // Legacy single message deleted once...
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), "/channels/{$legacyChannel}/messages/{$legacyMessage}"));

        $guild->refresh();
        // ...and the legacy tracking cleared.
        $this->assertNull($guild->digest_message_id);
        $this->assertNull($guild->digest_channel_id);
        // The new daily thread is tracked.
        $this->assertSame(self::MESSAGE_ID, $guild->digest_thread_message_id);
    }

    #[Test]
    public function legacy_delete_failure_is_swallowed_and_new_thread_still_created()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $guild->update([
            'digest_message_id' => 'gone-already',
            'digest_channel_id' => self::CALENDAR_CHANNEL,
        ]);

        Http::fake([
            // Legacy message already gone.
            self::BASE_URL.'/channels/'.self::CALENDAR_CHANNEL.'/messages/gone-already' => Http::response(['message' => 'Unknown Message'], 404),
            self::BASE_URL.'/channels/*/messages/*/threads' => Http::response(['id' => self::THREAD_ID, 'type' => 11], 200),
            self::BASE_URL.'/channels/*/messages' => Http::response(['id' => self::MESSAGE_ID, 'channel_id' => self::CALENDAR_CHANNEL], 200),
            self::BASE_URL.'/channels/*' => Http::response([], 204),
        ]);

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        $this->assertSame(self::MESSAGE_ID, $guild->digest_thread_message_id, 'new thread created despite legacy 404');
        $this->assertNull($guild->digest_message_id);
    }

    // ════════════════════════════════════════════════════
    //  EMPTY WINDOW: a daily pulse — still creates/refreshes a thread
    // ════════════════════════════════════════════════════

    #[Test]
    public function empty_window_first_run_creates_a_thread_with_empty_state()
    {
        $guild = DiscordGuild::factory()
            ->configured()
            ->create(['calendar_channel_id' => self::CALENDAR_CHANNEL]);

        $this->fakeCreateSuccess();

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        $this->assertSame(self::MESSAGE_ID, $guild->digest_thread_message_id);
        $this->assertSame(now()->toDateString(), $guild->digest_thread_date);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/messages/'.self::MESSAGE_ID.'/threads'));
    }

    #[Test]
    public function empty_window_same_day_refresh_patches_in_place_and_logs_empty()
    {
        $guild = DiscordGuild::factory()
            ->configured()
            ->create([
                'calendar_channel_id' => self::CALENDAR_CHANNEL,
                'digest_thread_date' => now()->toDateString(),
                'digest_thread_channel_id' => self::CALENDAR_CHANNEL,
                'digest_thread_message_id' => self::MESSAGE_ID,
            ]);

        $this->fakeEditSuccess();
        Log::spy();

        $this->makePublisher()->publish($guild);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => ($ctx['event_count'] ?? null) === 0
                && ($ctx['status'] ?? null) === 'empty')
            ->atLeast()
            ->once();
    }

    // ════════════════════════════════════════════════════
    //  CHANNEL RECONFIG: calendar channel changed → fresh create in new channel
    // ════════════════════════════════════════════════════

    #[Test]
    public function calendar_channel_reconfigured_creates_fresh_thread_in_new_channel()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $oldChannel = self::CALENDAR_CHANNEL;
        $newChannel = '777888999000111222';
        $guild->update([
            'digest_thread_date' => now()->toDateString(),
            'digest_thread_channel_id' => $oldChannel,
            'digest_thread_message_id' => '111222333444555666',
            'calendar_channel_id' => $newChannel,
        ]);

        Http::fake([
            self::BASE_URL.'/channels/*/messages/*/threads' => Http::response(['id' => self::THREAD_ID, 'type' => 11], 200),
            self::BASE_URL.'/channels/'.$newChannel.'/messages' => Http::response(['id' => self::MESSAGE_ID, 'channel_id' => $newChannel], 200),
        ]);

        $this->makePublisher()->publish($guild);

        $guild->refresh();
        $this->assertSame($newChannel, $guild->digest_thread_channel_id);
        $this->assertSame(self::MESSAGE_ID, $guild->digest_thread_message_id);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), "/channels/{$newChannel}/messages")
            && ! str_contains($r->url(), '/threads'));
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
        $this->assertNull($guild->digest_thread_message_id);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => ($ctx['reason'] ?? null) === 'paused')
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
            ->withArgs(fn (string $msg, array $ctx) => ($ctx['reason'] ?? null) === 'no_calendar_channel')
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
        $this->assertNull($guild->digest_thread_message_id);
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

        $this->fakeCreateSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            // Capture the starter POST (to the calendar channel, not a thread).
            if ($r->method() === 'POST' && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages') && ! str_contains($r->url(), '/threads')) {
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

        $this->fakeCreateSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST' && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages') && ! str_contains($r->url(), '/threads')) {
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

        $this->fakeCreateSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST' && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages') && ! str_contains($r->url(), '/threads')) {
                $posted = $r->data();
            }

            return true;
        });
        $body = $this->bodyJson($posted);
        $this->assertStringContainsString('/games/'.$publicGame->id, $body);
        $this->assertSame(1, substr_count($body, '/games/'));
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

        $this->fakeCreateSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST' && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages') && ! str_contains($r->url(), '/threads')) {
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

        $nearGame = Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => now()->addDays(10)]);
        Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => now()->addDays(20)]);
        Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => now()->subDays(2)]);

        $this->fakeCreateSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST' && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages') && ! str_contains($r->url(), '/threads')) {
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
        $guildA = DiscordGuild::factory()->configured()->create(['calendar_channel_id' => self::CALENDAR_CHANNEL]);
        $guildB = DiscordGuild::factory()->configured()->create(['calendar_channel_id' => '888999000111222333']);

        $owner = User::factory()->create();
        DiscordGuildOrganizer::factory()->optedIn()->create(['guild_id' => $guildB->id, 'user_id' => $owner->id]);

        Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => now()->addDays(2)]);

        $this->fakeCreateSuccess();
        Log::spy();

        $this->makePublisher()->publish($guildA);

        $guildA->refresh();
        // Empty window still creates the daily-pulse thread.
        $this->assertSame(self::MESSAGE_ID, $guildA->digest_thread_message_id);
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => ($ctx['guild_id'] ?? null) === $guildA->id)
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

        $this->fakeCreateSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST' && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages') && ! str_contains($r->url(), '/threads')) {
                $posted = $r->data();
            }

            return true;
        });
        $this->assertNotNull($posted);
        $body = $this->bodyJson($posted);
        $this->assertStringContainsString('3/', $body);
    }

    // ════════════════════════════════════════════════════
    //  FAILURE ISOLATION (Q5)
    // ════════════════════════════════════════════════════

    #[Test]
    public function terminal_post_failure_throws_aggregate_and_does_not_track_a_thread()
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
        $this->assertNull($guild->digest_thread_message_id);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $msg, array $ctx) => ($ctx['guild_id'] ?? null) === $guild->id)
            ->atLeast()
            ->once();
    }

    #[Test]
    public function terminal_edit_failure_throws_aggregate_but_preserves_existing_tracking()
    {
        [$guild, $owner, $game] = $this->guildWithUpcomingGame();
        $guild->update([
            'digest_thread_date' => now()->toDateString(),
            'digest_thread_channel_id' => self::CALENDAR_CHANNEL,
            'digest_thread_message_id' => self::MESSAGE_ID,
        ]);

        // A 500 (not a 404) on the edit → re-thrown, not self-healed.
        Http::fake([
            self::BASE_URL.'/channels/*/messages/*' => Http::response(['message' => 'Internal'], 500),
        ]);

        $threw = false;
        try {
            $this->makePublisher()->publish($guild);
        } catch (DiscordPublishException $e) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $guild->refresh();
        // Prior tracking untouched.
        $this->assertSame(self::MESSAGE_ID, $guild->digest_thread_message_id);
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

        $g1 = Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => $sameDate->copy()->setTime(19, 0), 'location_id' => $venue->id]);
        $g2 = Game::factory()->create(['owner_id' => $owner->id, 'visibility' => 'public', 'date_time' => $sameDate->copy()->setTime(19, 30), 'location_id' => $venue->id]);

        $this->fakeCreateSuccess();

        $this->makePublisher()->publish($guild);

        $posted = null;
        Http::assertSent(function (Request $r) use (&$posted): bool {
            if ($r->method() === 'POST' && str_contains($r->url(), '/channels/'.self::CALENDAR_CHANNEL.'/messages') && ! str_contains($r->url(), '/threads')) {
                $posted = $r->data();
            }

            return true;
        });
        $this->assertNotNull($posted);

        $fields = $posted['embeds'][0]['fields'] ?? [];
        $venueFields = array_filter($fields, fn ($f) => str_contains((string) $f['name'], 'Dragon'));
        $this->assertCount(1, $venueFields, 'multi-table night collapsed under one venue field');
        $venueField = array_values($venueFields)[0];
        $this->assertStringContainsString('/games/'.$g1->id, $venueField['value']);
        $this->assertStringContainsString('/games/'.$g2->id, $venueField['value']);
    }
}
