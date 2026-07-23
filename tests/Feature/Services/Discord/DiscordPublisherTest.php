<?php

namespace Tests\Feature\Services\Discord;

use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Exceptions\DiscordApiException;
use App\Models\DiscordCardMessage;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\Discord\DiscordCardRenderer;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\DiscordPublishException;
use App\Services\Discord\DiscordWebhookClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the DiscordPublisher chokepoint (T05): the single path through which
 * all Discord card posting flows. Covers the five plan-required contract
 * points (public posts + persists message_id; protected does NOT post;
 * private has no surface; paused guild does not post; organizer without
 * publish_enabled does not post) plus edit-in-place idempotency, channel
 * reconfiguration, visibility-downgrade unpublish, and the failure surface.
 *
 * The publisher composes DiscordWebhookClient (T03) + DiscordCardRenderer
 * (T04). Tests inject a webhook client pointed at Http::fake() so no real
 * Discord call is made; the renderer is the real pure transformer.
 */
class DiscordPublisherTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://discord.test/api/v10';

    private const GAMES_CHANNEL = '111222333444555666';

    private const MESSAGE_ID = '999888777666555444';

    /**
     * Build a publisher wired to an Http::fake()-intercepted webhook client.
     * The sleep closure makes 429 backoff instant in tests.
     */
    private function makePublisher(): DiscordPublisher
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

        return new DiscordPublisher($client, new DiscordCardRenderer);
    }

    /**
     * Standard fixture: an opted-in organizer owning a public game, with a
     * configured (non-paused) guild. Returns the created models so each test
     * can tweak them.
     *
     * @return array{game: Game, guild: DiscordGuild, organizer: DiscordGuildOrganizer}
     */
    private function publicGameInOptedInGuild(): array
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Public->value,
        ]);

        $guild = DiscordGuild::factory()
            ->configured()
            ->create(['owner_user_id' => User::factory()->create()->id]);

        $organizer = DiscordGuildOrganizer::factory()
            ->optedIn()
            ->create([
                'guild_id' => $guild->id,
                'user_id' => $owner->id,
            ]);

        return [$game, $guild, $organizer];
    }

    /**
     * Http::fake stub for a successful Discord message POST, echoing a message
     * id. Used by the post path.
     */
    private function fakePostSuccess(): void
    {
        Http::fake([
            self::BASE_URL.'/channels/*/messages' => Http::response([
                'id' => self::MESSAGE_ID,
                'channel_id' => self::GAMES_CHANNEL,
            ], 200),
        ]);
    }

    // ════════════════════════════════════════════════════
    //  CONTRACT: public event by opted-in organizer posts
    // ════════════════════════════════════════════════════

    #[Test]
    public function public_event_by_opted_in_organizer_posts_to_mapped_guild_and_persists_message_id()
    {
        [$game, $guild] = $this->publicGameInOptedInGuild();
        $this->fakePostSuccess();

        $publisher = $this->makePublisher();
        $publisher->publish($game);

        // Card message tracked with Discord's snowflake id.
        $card = DiscordCardMessage::where('game_id', $game->id)
            ->where('guild_id', $guild->id)
            ->first();
        $this->assertNotNull($card, 'discord_card_messages row was persisted');
        $this->assertSame(self::MESSAGE_ID, $card->message_id);
        $this->assertSame($guild->games_channel_id, $card->channel_id);

        // Exactly one POST to the games channel. The factory generates a
        // random games_channel_id, so assert against the guild's actual id.
        Http::assertSentCount(1);
        $expectedChannel = $guild->games_channel_id;
        Http::assertSent(function (Request $request) use ($expectedChannel): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/channels/'.$expectedChannel.'/messages');
        });
    }

    // ════════════════════════════════════════════════════
    //  CONTRACT: protected event does NOT post to channel
    // ════════════════════════════════════════════════════

    #[Test]
    public function protected_event_does_not_post_to_channel()
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Protected->value,
        ]);
        $guild = DiscordGuild::factory()->configured()->create();
        DiscordGuildOrganizer::factory()->optedIn()->create([
            'guild_id' => $guild->id,
            'user_id' => $owner->id,
        ]);

        Http::fake(); // No Discord call expected.

        Log::spy();

        $this->makePublisher()->publish($game);

        Http::assertNothingSent();
        $this->assertSame(0, DiscordCardMessage::where('game_id', $game->id)->count());

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'discord_publisher.visibility_gate'
                && ($ctx['visibility'] ?? null) === 'protected'
                && ($ctx['decision'] ?? null) === 'blocked')
            ->atLeast()
            ->once();
    }

    // ════════════════════════════════════════════════════
    //  CONTRACT: private event has no Discord surface
    // ════════════════════════════════════════════════════

    #[Test]
    public function private_event_has_no_discord_surface()
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Private->value,
        ]);
        $guild = DiscordGuild::factory()->configured()->create();
        DiscordGuildOrganizer::factory()->optedIn()->create([
            'guild_id' => $guild->id,
            'user_id' => $owner->id,
        ]);

        Http::fake();

        $this->makePublisher()->publish($game);

        Http::assertNothingSent();
        $this->assertSame(0, DiscordCardMessage::where('game_id', $game->id)->count());
    }

    // ════════════════════════════════════════════════════
    //  CONTRACT: paused guild does not post
    // ════════════════════════════════════════════════════

    #[Test]
    public function paused_guild_does_not_post()
    {
        [$game, $guild, $organizer] = $this->publicGameInOptedInGuild();
        $guild->update(['paused' => true]);

        Http::fake();

        $this->makePublisher()->publish($game);

        Http::assertNothingSent();
        $this->assertSame(0, DiscordCardMessage::where('game_id', $game->id)->count());
    }

    // ════════════════════════════════════════════════════
    //  CONTRACT: organizer without publish_enabled does not post
    // ════════════════════════════════════════════════════

    #[Test]
    public function organizer_without_publish_enabled_does_not_post()
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Public->value,
        ]);
        $guild = DiscordGuild::factory()->configured()->create();
        // Opt-OUT row — organizer explicitly declined.
        DiscordGuildOrganizer::factory()->optedOut()->create([
            'guild_id' => $guild->id,
            'user_id' => $owner->id,
        ]);

        Http::fake();

        $this->makePublisher()->publish($game);

        Http::assertNothingSent();
        $this->assertSame(0, DiscordCardMessage::where('game_id', $game->id)->count());
    }

    // ════════════════════════════════════════════════════
    //  EDIT-IN-PLACE IDEMPOTENCY
    // ════════════════════════════════════════════════════

    #[Test]
    public function republish_edits_existing_card_in_place_rather_than_duplicating()
    {
        [$game, $guild] = $this->publicGameInOptedInGuild();

        // Existing tracked card — re-publish should PATCH it.
        $existing = DiscordCardMessage::create([
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => $guild->games_channel_id,
            'message_id' => '111000000000000000',
        ]);

        Http::fake([
            self::BASE_URL.'/channels/*/messages/111000000000000000' => Http::response([
                'id' => self::MESSAGE_ID,
            ], 200),
        ]);

        $this->makePublisher()->publish($game);

        // Still one row (no duplicate), message_id updated.
        $existing->refresh();
        $this->assertSame(self::MESSAGE_ID, $existing->message_id);
        $this->assertSame(1, DiscordCardMessage::where('game_id', $game->id)->count());

        // PATCH, not POST.
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/messages/111000000000000000');
        });
    }

    #[Test]
    public function guild_channel_reconfiguration_deletes_stale_and_posts_to_new_channel()
    {
        [$game, $guild] = $this->publicGameInOptedInGuild();

        $oldChannel = $guild->games_channel_id;
        $newChannel = '222333444555666777';
        $guild->update(['games_channel_id' => $newChannel]);

        DiscordCardMessage::create([
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => $oldChannel,
            'message_id' => '111000000000000000',
        ]);

        Http::fake([
            self::BASE_URL."/channels/{$oldChannel}/messages/111000000000000000" => Http::response([], 204),
            self::BASE_URL."/channels/{$newChannel}/messages" => Http::response(['id' => self::MESSAGE_ID], 200),
        ]);

        $this->makePublisher()->publish($game);

        $card = DiscordCardMessage::where('game_id', $game->id)->first();
        $this->assertSame($newChannel, $card->channel_id);
        $this->assertSame(self::MESSAGE_ID, $card->message_id);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), $newChannel));
    }

    // ════════════════════════════════════════════════════
    //  VISIBILITY DOWNGRADE → UNPUBLISH
    // ════════════════════════════════════════════════════

    #[Test]
    public function visibility_downgrade_from_public_unpublishes_existing_card()
    {
        [$game, $guild] = $this->publicGameInOptedInGuild();

        $card = DiscordCardMessage::create([
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => $guild->games_channel_id,
            'message_id' => '111000000000000000',
        ]);

        Http::fake([
            self::BASE_URL.'/channels/*/messages/*' => Http::response([], 204),
        ]);

        // Flip to protected → publisher should unpublish.
        $game->update(['visibility' => Visibility::Protected->value]);
        $this->makePublisher()->publish($game->fresh());

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
        $this->assertSame(0, DiscordCardMessage::where('game_id', $game->id)->count());
    }

    #[Test]
    public function unpublish_is_best_effort_and_removes_tracking_row_even_on_discord_failure()
    {
        [$game, $guild] = $this->publicGameInOptedInGuild();

        DiscordCardMessage::create([
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => $guild->games_channel_id,
            'message_id' => '111000000000000000',
        ]);

        // Discord delete fails (e.g. message already gone).
        Http::fake([
            self::BASE_URL.'/channels/*/messages/*' => Http::response(['message' => 'Unknown Message'], 404),
        ]);

        $publisher = $this->makePublisher();

        // Must NOT throw — unpublish is best-effort.
        $publisher->unpublish($game);

        $this->assertSame(0, DiscordCardMessage::where('game_id', $game->id)->count());
    }

    // ════════════════════════════════════════════════════
    //  FAILURE SURFACE (Q5)
    // ════════════════════════════════════════════════════

    #[Test]
    public function discord_api_failure_on_post_throws_aggregate_and_does_not_persist_tracking_row()
    {
        [$game] = $this->publicGameInOptedInGuild();

        Http::fake([
            self::BASE_URL.'/channels/*/messages' => Http::response(['message' => 'Missing Access'], 403),
        ]);

        // publish() catches the per-guild DiscordApiException and re-throws a
        // DiscordPublishException aggregate so the queued job retries.
        $this->expectException(DiscordPublishException::class);

        try {
            $this->makePublisher()->publish($game);
        } finally {
            // No tracking row when the post failed.
            $this->assertSame(0, DiscordCardMessage::where('game_id', $game->id)->count());
        }
    }

    #[Test]
    public function one_guild_failure_does_not_block_others_then_throws_aggregate()
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Public->value,
        ]);

        $goodGuild = DiscordGuild::factory()->configured()->create();
        $badGuild = DiscordGuild::factory()->configured()->create();
        DiscordGuildOrganizer::factory()->optedIn()->create(['guild_id' => $goodGuild->id, 'user_id' => $owner->id]);
        DiscordGuildOrganizer::factory()->optedIn()->create(['guild_id' => $badGuild->id, 'user_id' => $owner->id]);

        Http::fake([
            // good guild channel succeeds, bad guild channel 403s.
            self::BASE_URL.'/channels/'.$goodGuild->games_channel_id.'/messages' => Http::response(['id' => self::MESSAGE_ID], 200),
            self::BASE_URL.'/channels/'.$badGuild->games_channel_id.'/messages' => Http::response(['message' => 'Forbidden'], 403),
        ]);

        $threw = false;
        try {
            $this->makePublisher()->publish($game);
        } catch (DiscordPublishException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'aggregate DiscordPublishException was thrown');

        // good guild got its card despite the bad guild failing.
        $this->assertSame(
            1,
            DiscordCardMessage::where('guild_id', $goodGuild->id)->count(),
            'good guild card was persisted before the bad guild failed'
        );
    }

    // ════════════════════════════════════════════════════
    //  ROSTER CONTEXT (publisher computes counts the renderer reads)
    // ════════════════════════════════════════════════════

    #[Test]
    public function publisher_includes_roster_counts_from_participant_pipeline_in_payload()
    {
        [$game, $guild] = $this->publicGameInOptedInGuild();

        // 3 approved + 2 waitlisted + 1 benched.
        foreach (range(1, 3) as $_) {
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Waitlisted->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Benched->value,
        ]);

        $posted = null;
        Http::fake([
            self::BASE_URL.'/channels/*/messages' => function (Request $request) use (&$posted) {
                $posted = $request->data();

                return Http::response(['id' => self::MESSAGE_ID], 200);
            },
        ]);

        $this->makePublisher()->publish($game);

        $this->assertNotNull($posted);
        // The roster field value carries the approved count + waitlist overflow.
        $rosterField = collect($posted['embeds'][0]['fields'] ?? [])
            ->firstWhere('name', 'Players');
        $this->assertNotNull($rosterField, 'roster field present');
        $this->assertStringContainsString('3/', $rosterField['value']);
        $this->assertStringContainsString('1 waitlist', $rosterField['value']);
        $this->assertStringContainsString('1 bench', $rosterField['value']);
    }

    // ════════════════════════════════════════════════════
    //  OBSERVER WIRING (Q7 — the dispatch gate)
    // ════════════════════════════════════════════════════

    #[Test]
    public function observer_does_not_dispatch_when_publishing_disabled()
    {
        config(['services.discord.publishing_enabled' => false]);
        Http::fake();

        $game = Game::factory()->create(['visibility' => Visibility::Public->value]);

        // GameObserver::saved ran during factory create — no Discord call.
        Http::assertNothingSent();
        $this->assertSame(0, DiscordCardMessage::where('game_id', $game->id)->count());
    }

    #[Test]
    public function observer_dispatches_publish_job_when_enabled_and_job_posts_via_publisher()
    {
        // The container-resolved webhook client reads api_base_url from config;
        // align it with the Http::fake() base URL so the intercept matches.
        config([
            'services.discord.publishing_enabled' => true,
            'services.discord.api_base_url' => self::BASE_URL,
        ]);
        $this->fakePostSuccess();

        $owner = User::factory()->create();
        $guild = DiscordGuild::factory()->configured()->create();
        DiscordGuildOrganizer::factory()->optedIn()->create([
            'guild_id' => $guild->id,
            'user_id' => $owner->id,
        ]);

        // sync queue → job runs inline during GameObserver::saved.
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Public->value,
        ]);

        // The job dispatched by the observer posted the card.
        $this->assertSame(1, DiscordCardMessage::where('game_id', $game->id)->count());
    }

    #[Test]
    public function game_with_no_owner_does_not_crash_publisher()
    {
        // A game whose owner_id points to a deleted user — owner() returns null.
        $game = Game::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'visibility' => Visibility::Public->value,
        ]);
        $game->owner->delete();
        // Force the relation to reload as null.
        $game->unsetRelation('owner');

        Http::fake();

        // Should no-op cleanly (no targets), not throw.
        $this->makePublisher()->publish($game);

        Http::assertNothingSent();
    }

    #[Test]
    public function guild_without_games_channel_configured_is_skipped()
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Public->value,
        ]);
        // Guild with no games_channel_id.
        $guild = DiscordGuild::factory()->create(['games_channel_id' => null]);
        DiscordGuildOrganizer::factory()->optedIn()->create([
            'guild_id' => $guild->id,
            'user_id' => $owner->id,
        ]);

        Http::fake();

        $this->makePublisher()->publish($game);

        Http::assertNothingSent();
        $this->assertSame(0, DiscordCardMessage::where('game_id', $game->id)->count());
    }
}
