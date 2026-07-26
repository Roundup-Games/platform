<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RefreshDiscordCard;
use App\Models\DiscordCardMessage;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\DiscordWebhookClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the {@see RefreshDiscordCard} debounced card-refresh job (M057/S04/T01).
 *
 * Proof level (matching the slice's verification contract): the debounce /
 * coalescing + uniqueness behavior is the only genuinely novel code in S04
 * (everything downstream — DiscordPublisher edit-in-place, webhook client
 * 429 backoff, renderer — shipped in S01–S03). So these tests prove:
 *
 *   - The job is ShouldBeUnique keyed on gameId, so a burst of roster churn
 *     coalesces to a single queued refresh (the UniqueLock is held while the
 *     job is delayed; Queue::fake() never releases it).
 *   - The dispatch delay equals the debounce window (config-driven), so the
 *     refresh lands at the window edge, not immediately.
 *   - handle() re-publishes the card through the REAL DiscordPublisher
 *     chokepoint wired to an Http::fake()-intercepted surface, hitting the
 *     edit-in-place PATCH URL (no duplicate POST).
 *   - A game deleted between dispatch and run exits cleanly — no retry, no
 *     publish, a structured game_missing log — proving deleteWhenMissingModels.
 *   - failure() emits the structured job.failed log for ops tracing.
 *
 * The observer hook (created/updated-status/deleted → dispatch) is exercised
 * separately in GameParticipantObserverDiscordTest (T02).
 */
class RefreshDiscordCardTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://discord.test/api/v10';

    private const GAMES_CHANNEL = '888777666555444333';

    private const CARD_MESSAGE_ID = '777666555444333222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.discord.api_base_url' => self::BASE_URL,
            'services.discord.bot_token' => 'test-bot-token',
            'services.discord.publishing_enabled' => true,
        ]);

        $this->bindWebhookClient();
    }

    // ── Debounce: uniqueId + uniqueFor + dispatch delay ───────────────────

    #[Test]
    public function unique_id_is_keyed_on_game_id(): void
    {
        $jobA = new RefreshDiscordCard((string) Str::orderedUuid());
        $jobB = new RefreshDiscordCard((string) Str::orderedUuid());

        $this->assertSame($jobA->gameId, $jobA->uniqueId());
        $this->assertSame($jobB->gameId, $jobB->uniqueId());
        $this->assertNotSame($jobA->uniqueId(), $jobB->uniqueId());
    }

    #[Test]
    public function unique_for_equals_the_configured_debounce_window(): void
    {
        config(['services.discord.card_refresh_debounce_seconds' => 42]);

        $job = new RefreshDiscordCard((string) Str::orderedUuid());

        $this->assertSame(42, $job->uniqueFor());
    }

    #[Test]
    public function dispatch_delay_equals_the_default_debounce_window(): void
    {
        config(['services.discord.card_refresh_debounce_seconds' => 15]);

        $job = new RefreshDiscordCard((string) Str::orderedUuid());

        // delay() stores a Carbon or int; assert the second value matches.
        $this->assertEquals(15, $job->delay->totalSeconds ?? $job->delay);
    }

    #[Test]
    public function dispatch_delay_respects_an_overridden_debounce_window(): void
    {
        config(['services.discord.card_refresh_debounce_seconds' => 30]);

        $job = new RefreshDiscordCard((string) Str::orderedUuid());

        $this->assertEquals(30, $job->delay->totalSeconds ?? $job->delay);
        $this->assertSame(30, $job->uniqueFor());
    }

    // ── Coalescing: a burst of churn → one queued refresh ─────────────────

    #[Test]
    public function rapid_dispatches_for_the_same_game_coalesce_to_one(): void
    {
        Queue::fake();

        $gameId = (string) Str::orderedUuid();

        // A burst of roster churn: five dispatches for the same game inside
        // the debounce window. ShouldBeUnique acquires a UniqueLock for
        // uniqueFor() seconds on the first dispatch; under Queue::fake() the
        // job never processes so the lock is never released, and every
        // subsequent dispatch is suppressed in PendingDispatch::__destruct.
        for ($i = 0; $i < 5; $i++) {
            RefreshDiscordCard::dispatch($gameId);
        }

        Queue::assertPushed(RefreshDiscordCard::class, 1);
        Queue::assertPushed(RefreshDiscordCard::class, function (RefreshDiscordCard $job) use ($gameId) {
            return $job->gameId === $gameId;
        });
    }

    #[Test]
    public function dispatches_for_different_games_do_not_coalesce(): void
    {
        Queue::fake();

        $gameA = (string) Str::orderedUuid();
        $gameB = (string) Str::orderedUuid();

        RefreshDiscordCard::dispatch($gameA);
        RefreshDiscordCard::dispatch($gameB);

        Queue::assertPushed(RefreshDiscordCard::class, 2);
    }

    // ── handle(): edit-in-place PATCH through the real publisher ──────────

    #[Test]
    public function handle_republishes_an_existing_card_in_place(): void
    {
        [$owner, $game] = $this->gameWithPostedCard();

        $sent = [];
        Http::fake($this->recordingOk($sent));

        (new RefreshDiscordCard((string) $game->id))->handle(app(DiscordPublisher::class));

        // The refresh MUST hit the edit-in-place PATCH URL for the existing
        // card — never a duplicate POST to the channel.
        $patch = collect($sent)->first(fn (Request $r) => str_contains(
            $r->url(),
            '/channels/'.self::GAMES_CHANNEL.'/messages/'.self::CARD_MESSAGE_ID
        ));
        $this->assertNotNull($patch, 'Refresh must PATCH the existing card in place (no duplicate POST).');
        $this->assertSame('PATCH', $patch->method());
    }

    #[Test]
    public function handle_logs_started_and_completed_for_tracing(): void
    {
        [$owner, $game] = $this->gameWithPostedCard();

        Http::fake([$this->cardEditUrl() => Http::response(['id' => self::CARD_MESSAGE_ID], 200)]);
        $log = Log::spy();

        (new RefreshDiscordCard((string) $game->id))->handle(app(DiscordPublisher::class));

        $log->shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'discord_card_refresh.job.started'
                && ($c['game_id'] ?? null) === $game->id)
            ->atLeast()
            ->once();
        $log->shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'discord_card_refresh.job.completed'
                && ($c['game_id'] ?? null) === $game->id)
            ->atLeast()
            ->once();
    }

    // ── Missing game: clean exit, no publish, no retry ───────────────────

    #[Test]
    public function a_game_deleted_before_run_exits_cleanly_without_publishing(): void
    {
        $sent = [];
        Http::fake($this->recordingOk($sent));

        $missingGameId = (string) Str::orderedUuid();

        (new RefreshDiscordCard($missingGameId))->handle(app(DiscordPublisher::class));

        $this->assertEmpty(
            $sent,
            'A deleted game must not trigger any Discord REST call.'
        );
    }

    #[Test]
    public function a_missing_game_is_logged_for_tracing(): void
    {
        Http::fake();
        $log = Log::spy();

        $missingGameId = (string) Str::orderedUuid();

        (new RefreshDiscordCard($missingGameId))->handle(app(DiscordPublisher::class));

        $log->shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'discord_card_refresh.job.game_missing'
                && ($c['game_id'] ?? null) === $missingGameId)
            ->atLeast()
            ->once();
    }

    // ── failure(): structured log on retries exhausted ────────────────────

    #[Test]
    public function failure_logs_the_exception_for_tracing(): void
    {
        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
            return $message === 'discord_card_refresh.job.failed'
                && ($context['game_id'] ?? null) === 'game-xyz'
                && ($context['exception'] ?? null) === 'boom'
                && ($context['exception_class'] ?? null) === \RuntimeException::class;
        });

        $job = new RefreshDiscordCard('game-xyz');
        $job->failed(new \RuntimeException('boom'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

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
     * @return array{User, Game}
     */
    private function gameWithPostedCard(): array
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        // Pin the guild's games channel to the same channel the card was
        // posted to, so DiscordPublisher takes the edit-in-place PATCH branch
        // (existing->channel_id === guild->games_channel_id) rather than the
        // "guild reconfigured channel" DELETE+POST branch. The PATCH branch
        // is the edit-in-place contract RefreshDiscordCard is proving.
        $guild = DiscordGuild::factory()->configured()->create([
            'games_channel_id' => self::GAMES_CHANNEL,
        ]);
        DiscordGuildOrganizer::factory()
            ->optedIn()
            ->create(['user_id' => $owner->id, 'guild_id' => $guild->id]);

        DiscordCardMessage::create([
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => self::GAMES_CHANNEL,
            'message_id' => self::CARD_MESSAGE_ID,
        ]);

        return [$owner, $game];
    }

    private function cardEditUrl(): string
    {
        return self::BASE_URL.'/channels/'.self::GAMES_CHANNEL.'/messages/'.self::CARD_MESSAGE_ID;
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
}
