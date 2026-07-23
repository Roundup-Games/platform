<?php

namespace Tests\Feature\Jobs;

use App\Enums\DiscordRsvpOutcome;
use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Jobs\ProcessDiscordRsvp;
use App\Models\DiscordCardMessage;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\DiscordWebhookClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the {@see ProcessDiscordRsvp} queued job (M057/S03/T03): the deferred
 * interaction closer that writes through the SAME participant pipeline as a web
 * RSVP, then best-effort refreshes the card roster and resolves the @original
 * confirmation.
 *
 * Proof level (matching the slice's verification contract): these tests bind a
 * REAL {@see DiscordWebhookClient} + REAL {@see DiscordPublisher} wired to an
 * Http::fake()-intercepted surface, so the job → participant pipeline → card
 * refresh → @original PATCH path is live, not mocked. The job's own
 * responsibilities under test are:
 *
 *   - The participant write mirrors the share-link self-join path exactly
 *     (one source of truth): Game::lockForUpdate(), capacity check, owner /
 *     already-participant / game-status guards, OverflowRouter::resolve(),
 *     approved_at stamping, JoinSource::Discord.
 *   - Best-effort Discord I/O (card refresh + confirmation) NEVER rolls back
 *     the RSVP — it is logged and swallowed.
 *   - The @original PATCH hits the token-authenticated webhook URL with NO
 *     Bot Authorization header.
 *   - join_source=discord is accepted by the DB CHECK constraint (migration).
 */
class ProcessDiscordRsvpTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://discord.test/api/v10';

    private const BOT_APP_ID = '111222333444555666';

    private const INTERACTION_TOKEN = 'a-test-interaction-token';

    private const GUILD_ID_SNOWFLAKE = '999000111222333444';

    private const GAMES_CHANNEL = '888777666555444333';

    private const CARD_MESSAGE_ID = '777666555444333222';

    protected function setUp(): void
    {
        parent::setUp();

        // The @original PATCH URL needs the bot application id; the webhook
        // client reads the base URL from config. Both are test-only values.
        config([
            'services.discord.bot_application_id' => self::BOT_APP_ID,
            'services.discord.api_base_url' => self::BASE_URL,
        ]);

        $this->bindWebhookClient();
    }

    // ── Pipeline write: one source of truth ───────────────────────────────

    #[Test]
    public function approved_join_writes_participant_with_discord_source_and_stamps_approved_at(): void
    {
        [$owner, $game, $clicker] = $this->joinableGame();
        Http::fake($this->okOriginalOnly());

        $this->runJob($game->id, $clicker->id);

        // The row mirrors joinViaShareLink's not-full branch exactly: Approved
        // status, Player role, approved_at stamped (LIFO demotion ordering),
        // join_source=discord. Only the join_source differs from a share-link row.
        $this->assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $clicker->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'join_source' => JoinSource::Discord->value,
        ]);

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $clicker->id)
            ->sole();
        $this->assertNotNull($participant->approved_at, 'approved_at must be stamped so LIFO capacity-demotion ordering is correct.');
    }

    #[Test]
    public function full_game_routes_to_waitlist_with_waitlisted_at(): void
    {
        [$owner, $game, $clicker] = $this->fullGame(benchMode: false);
        Http::fake($this->okOriginalOnly());

        $this->runJob($game->id, $clicker->id);

        $this->assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $clicker->id,
            'status' => ParticipantStatus::Waitlisted->value,
            'join_source' => JoinSource::Discord->value,
        ]);

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $clicker->id)
            ->sole();
        $this->assertNotNull($participant->waitlisted_at);
        $this->assertNull($participant->approved_at);
    }

    #[Test]
    public function bench_mode_full_game_routes_to_bench_with_benched_at(): void
    {
        [$owner, $game, $clicker] = $this->fullGame(benchMode: true);
        Http::fake($this->okOriginalOnly());

        $this->runJob($game->id, $clicker->id);

        $this->assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $clicker->id,
            'status' => ParticipantStatus::Benched->value,
            'join_source' => JoinSource::Discord->value,
        ]);

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $clicker->id)
            ->sole();
        $this->assertNotNull($participant->benched_at);
        $this->assertNull($participant->approved_at);
    }

    // ── Guards: refuse / already-on-roster ────────────────────────────────

    #[Test]
    public function already_participant_resolves_to_already_confirmation_without_duplicate_row(): void
    {
        [$owner, $game, $clicker] = $this->joinableGame();
        // Seed an existing approved participant for the clicker (double-click /
        // re-dispatch race).
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $clicker->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);

        Http::fake($this->okOriginalOnly());
        $log = Log::spy();

        $this->runJob($game->id, $clicker->id);

        // No duplicate row.
        $this->assertSame(
            1,
            GameParticipant::where('game_id', $game->id)->where('user_id', $clicker->id)->count(),
            'A re-dispatch for an already-participant must not create a duplicate row.'
        );

        $log->shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'discord_rsvp.completed' && ($c['status'] ?? null) === DiscordRsvpOutcome::AlreadyOnRoster->logValue())
            ->atLeast()
            ->once();
    }

    #[Test]
    public function owner_is_refused_without_writing_a_row(): void
    {
        [$owner, $game, $clicker] = $this->joinableGame();
        Http::fake($this->okOriginalOnly());

        // The game's owner clicks their own RSVP button.
        $this->runJob($game->id, $owner->id);

        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'join_source' => JoinSource::Discord->value,
        ]);
    }

    #[Test]
    public function completed_game_is_refused_without_writing_a_row(): void
    {
        [$owner, $game, $clicker] = $this->joinableGame();
        $game->update(['status' => GameStatus::Completed->value]);
        Http::fake($this->okOriginalOnly());

        $this->runJob($game->id, $clicker->id);

        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $clicker->id,
            'join_source' => JoinSource::Discord->value,
        ]);
    }

    #[Test]
    public function canceled_game_is_refused_without_writing_a_row(): void
    {
        [$owner, $game, $clicker] = $this->joinableGame();
        $game->update(['status' => GameStatus::Canceled->value]);
        Http::fake($this->okOriginalOnly());

        $this->runJob($game->id, $clicker->id);

        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $clicker->id,
            'join_source' => JoinSource::Discord->value,
        ]);
    }

    #[Test]
    public function missing_game_resolves_deferred_interaction_as_refused(): void
    {
        $clicker = User::factory()->create();
        $sent = [];
        Http::fake($this->recordingOk($sent));

        // A valid UUID that simply does not exist — the real production
        // scenario is a game deleted between dispatch and execution. The
        // dispatch contract (T02) guarantees a well-formed game id; a
        // malformed id is not a real path.
        $this->runJob((string) Str::orderedUuid(), $clicker->id);

        // The deferred interaction is still resolved so the clicker's "Bot is
        // thinking…" ends with a real message rather than timing out.
        $this->assertTrue(
            $this->didPatchOriginal($sent),
            'A missing game must still resolve the deferred @original response as Refused.'
        );
    }

    #[Test]
    public function missing_user_resolves_deferred_interaction_as_refused(): void
    {
        [$owner, $game] = $this->joinableGame();
        $sent = [];
        Http::fake($this->recordingOk($sent));

        // Valid UUID; the user was deleted between dispatch and execution.
        $this->runJob($game->id, (string) Str::orderedUuid());

        $this->assertTrue($this->didPatchOriginal($sent));
    }

    // ── Best-effort isolation: Discord I/O never rolls back the RSVP ───────

    #[Test]
    public function card_refresh_failure_does_not_rollback_rsvp(): void
    {
        [$owner, $game, $clicker] = $this->joinableGameWithPostedCard();
        // Simulate the card edit-in-place terminally failing (5xx exhausts retries).
        Http::fake([
            $this->cardEditUrl() => Http::sequence()->push('', 500)->push('', 500)->push('', 500),
            $this->originalUrl() => Http::response(['id' => 'orig'], 200),
        ]);
        $log = Log::spy();

        $this->runJob($game->id, $clicker->id);

        // The RSVP write is the durable outcome — it stands regardless of the card.
        $this->assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $clicker->id,
            'status' => ParticipantStatus::Approved->value,
            'join_source' => JoinSource::Discord->value,
        ]);

        $log->shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => $m === 'discord_rsvp.card_refresh_failed')
            ->atLeast()
            ->once();
    }

    #[Test]
    public function confirmation_failure_does_not_rollback_rsvp(): void
    {
        [$owner, $game, $clicker] = $this->joinableGame();
        // @original PATCH fails terminally; no card is posted (no target guild).
        Http::fake([
            $this->originalUrl() => Http::sequence()->push('', 500)->push('', 500)->push('', 500),
        ]);
        $log = Log::spy();

        $this->runJob($game->id, $clicker->id);

        $this->assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $clicker->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $log->shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => $m === 'discord_rsvp.confirmation_failed')
            ->atLeast()
            ->once();
    }

    // ── Card refresh: fires when a row is written, skips otherwise ─────────

    #[Test]
    public function card_refresh_fires_when_a_row_is_written(): void
    {
        [$owner, $game, $clicker] = $this->joinableGameWithPostedCard();
        $sent = [];
        Http::fake($this->recordingOk($sent));

        $this->runJob($game->id, $clicker->id);

        $this->assertTrue(
            $this->didPatchCard($sent),
            'A written RSVP must trigger a card-roster refresh (edit-in-place PATCH).'
        );
    }

    #[Test]
    public function card_refresh_is_skipped_when_nothing_changed(): void
    {
        [$owner, $game, $clicker] = $this->joinableGameWithPostedCard();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $clicker->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);

        $sent = [];
        Http::fake($this->recordingOk($sent));

        $this->runJob($game->id, $clicker->id);

        // Already-on-roster wrote nothing, so there is nothing new to render.
        $this->assertFalse(
            $this->didPatchCard($sent),
            'Already-on-roster changed nothing to render; the card must not be re-published.'
        );
    }

    // ── @original PATCH: URL shape + token auth ────────────────────────────

    #[Test]
    public function original_patch_uses_token_url_without_bot_authorization_header(): void
    {
        [$owner, $game, $clicker] = $this->joinableGame();
        $sent = [];
        Http::fake($this->recordingOk($sent));

        $this->runJob($game->id, $clicker->id);

        $original = collect($sent)->first(fn (Request $r) => str_contains($r->url(), 'messages/@original'));

        $this->assertNotNull($original, 'The @original PATCH must be sent.');
        $this->assertStringContainsString(
            'webhooks/'.self::BOT_APP_ID.'/'.self::INTERACTION_TOKEN.'/messages/@original',
            $original->url(),
            'The @original URL is token-authenticated: /webhooks/{appId}/{token}/messages/@original.'
        );
        // Interaction webhooks are token-authenticated by the URL — NO Bot header.
        $this->assertFalse(
            $original->hasHeader('Authorization'),
            'The @original PATCH must NOT send a Bot Authorization header (token-authenticated).'
        );
        $body = json_decode((string) $original->body(), true);
        $this->assertIsArray($body, 'The @original PATCH body must be valid JSON.');
        $this->assertSame(64, $body['flags'] ?? null, 'The confirmation must be ephemeral (flags 64).');
    }

    #[Test]
    public function completed_is_logged_with_the_outcome_status(): void
    {
        [$owner, $game, $clicker] = $this->joinableGame();
        Http::fake($this->okOriginalOnly());
        $log = Log::spy();

        $this->runJob($game->id, $clicker->id);

        $log->shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'discord_rsvp.completed'
                && ($c['game_id'] ?? null) === $game->id
                && ($c['user_id'] ?? null) === $clicker->id
                && ($c['status'] ?? null) === DiscordRsvpOutcome::Approved->logValue())
            ->atLeast()
            ->once();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Bind a REAL webhook client wired to Http::fake() (test base URL, no real
     * sleep). The publisher auto-resolves from the container with this client.
     */
    private function bindWebhookClient(): void
    {
        $this->app->instance(DiscordWebhookClient::class, new DiscordWebhookClient(
            baseUrl: self::BASE_URL,
            botToken: 'test-bot-token',
            timeout: 5,
            maxAttempts: 3,
            maxRetryAfterSeconds: 30.0,
            serverErrorBackoffSeconds: 0.0,
            sleep: static fn (float $seconds) => null,
        ));
    }

    /**
     * Run the job synchronously with the container-resolved dependencies.
     */
    private function runJob(string $gameId, string $userId): void
    {
        (new ProcessDiscordRsvp($gameId, $userId, self::GUILD_ID_SNOWFLAKE, self::INTERACTION_TOKEN))
            ->handle(app(DiscordWebhookClient::class), app(DiscordPublisher::class));
    }

    /**
     * @return array{User, Game, User} [owner, game, clicker]
     */
    private function joinableGame(int $maxPlayers = 4): array
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'max_players' => $maxPlayers,
            'min_players' => 2,
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
        ]);
        // Owner counts as one approved participant.
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $clicker = User::factory()->create();

        return [$owner, $game, $clicker];
    }

    /**
     * @return array{User, Game, User} [owner, game, clicker]
     */
    private function fullGame(bool $benchMode): array
    {
        $maxPlayers = 2; // owner + 1 approved fills it; clicker overflows.
        [$owner, $game, $clicker] = $this->joinableGame($maxPlayers);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);

        if ($benchMode) {
            $game->update(['bench_mode' => true]);
        }

        return [$owner, $game, $clicker];
    }

    /**
     * A joinable game whose owner has opted in to a configured guild with an
     * existing posted card (so the publisher's edit-in-place refresh fires).
     *
     * @return array{User, Game, User}
     */
    private function joinableGameWithPostedCard(): array
    {
        [$owner, $game, $clicker] = $this->joinableGame();

        $guild = DiscordGuild::factory()->configured()->create();
        DiscordGuildOrganizer::factory()
            ->optedIn()
            ->create(['user_id' => $owner->id, 'guild_id' => $guild->id]);

        DiscordCardMessage::create([
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => self::GAMES_CHANNEL,
            'message_id' => self::CARD_MESSAGE_ID,
        ]);

        return [$owner, $game, $clicker];
    }

    /**
     * Http::fake stub: 200 for the @original PATCH only (no card target).
     */
    private function okOriginalOnly(): \Closure
    {
        return function (Request $request) {
            if (str_contains($request->url(), 'messages/@original')) {
                return Http::response(['id' => 'orig-1'], 200);
            }

            return Http::response('', 404);
        };
    }

    /**
     * Http::fake stub: 200 everywhere, recording every sent request into $sent.
     *
     * @param  array<int, Request>  $sent
     */
    private function recordingOk(array &$sent): \Closure
    {
        return function (Request $request) use (&$sent) {
            $sent[] = $request;

            return Http::response(['id' => 'resp-1'], 200);
        };
    }

    private function originalUrl(): string
    {
        return self::BASE_URL.'/webhooks/'.self::BOT_APP_ID.'/'.self::INTERACTION_TOKEN.'/messages/@original';
    }

    private function cardEditUrl(): string
    {
        return self::BASE_URL.'/channels/'.self::GAMES_CHANNEL.'/messages/'.self::CARD_MESSAGE_ID;
    }

    /**
     * @param  array<int, Request>  $sent
     */
    private function didPatchOriginal(array $sent): bool
    {
        return collect($sent)->contains(fn (Request $r) => str_contains($r->url(), 'messages/@original'));
    }

    /**
     * @param  array<int, Request>  $sent
     */
    private function didPatchCard(array $sent): bool
    {
        return collect($sent)->contains(fn (Request $r) => str_contains($r->url(), '/channels/'.self::GAMES_CHANNEL.'/messages/'));
    }
}
