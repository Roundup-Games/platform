<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\DiscordApiException;
use App\Jobs\PublishGameBulletinToDiscord;
use App\Models\DiscordBulletinMessage;
use App\Models\DiscordCardMessage;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\User;
use App\Services\Discord\DiscordWebhookClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the {@see PublishGameBulletinToDiscord} teaser fan-out job (M062/S01).
 *
 * Proof level (matching the slice's verification contract): the genuinely
 * novel behavior this job adds over the existing card publisher is
 * per-bulletin, per-thread idempotent fan-out with a teaser-only payload —
 * so these tests prove:
 *
 *   - Fan-out: one teaser POST into every live session thread (posted card
 *     with thread_id) across multiple guilds; cards without threads and
 *     pending cards are excluded.
 *   - VISIBILITY (D132): the thread payload carries attribution, a link and
 *     a ≤100-char snippet — a unique marker planted beyond char 100 of the
 *     bulletin body never reaches the public-thread request.
 *   - Idempotency: an existing tracking row (posted OR failed) gates the
 *     thread, so a retry after partial success posts only missing threads.
 *   - Failure split: retryable failures (5xx) bubble for a queue retry
 *     without writing a row; terminal 4xx record STATUS_FAILED + error_code
 *     and the job continues with the remaining threads.
 *   - No-ops: no live threads, publishing disabled, session threads
 *     disabled, or bulletin deleted between dispatch and run.
 *
 * The real DiscordWebhookClient runs against an Http::fake()-intercepted
 * surface (same pattern as RefreshDiscordCardTest); the observer hook is
 * covered separately in GameBulletinObserverFanoutTest.
 */
class PublishGameBulletinToDiscordTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE_URL = 'https://discord.test/api/v10';

    private const THREAD_A = '111222333444555666';

    private const THREAD_B = '666555444333222111';

    /** Unique marker planted beyond char 100 of the bulletin body. */
    private const TAIL_MARKER = 'CONFIDENTIAL_TAIL_MARKER';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.discord.api_base_url' => self::BASE_URL,
            'services.discord.bot_token' => 'test-bot-token',
            'services.discord.publishing_enabled' => true,
            'services.discord.session_threads_enabled' => true,
        ]);

        // maxAttempts: 1 so a faked 4xx/5xx throws on the first response —
        // deterministic for Http::sequence() — and the no-op sleep keeps the
        // suite instant.
        $this->app->instance(DiscordWebhookClient::class, new DiscordWebhookClient(
            baseUrl: self::BASE_URL,
            botToken: 'test-bot-token',
            timeout: 5,
            maxAttempts: 1,
            maxRetryAfterSeconds: 0.0,
            serverErrorBackoffSeconds: 0.0,
            sleep: static fn (float $seconds) => null,
        ));
    }

    // ── Fan-out + teaser visibility ───────────────────────────────────

    #[Test]
    public function posts_teaser_into_every_live_thread_across_guilds(): void
    {
        [$game, $bulletin] = $this->gameWithBulletin(threadACard: true, threadBCard: true, noThreadCard: true, pendingCard: true);

        Http::fake([
            $this->threadUrl(self::THREAD_A) => Http::response(['id' => 'msg-a'], 200),
            $this->threadUrl(self::THREAD_B) => Http::response(['id' => 'msg-b'], 200),
        ]);

        $this->runJob($bulletin->id);

        // Exactly one POST per live thread; the thread-less card and the
        // pending card are not addressed at all.
        Http::assertSentCount(2);
        $this->assertThreadPosted(self::THREAD_A, 'msg-a', $bulletin);
        $this->assertThreadPosted(self::THREAD_B, 'msg-b', $bulletin);

        // Teaser payload: attribution + snippet + link present, full body absent.
        Http::assertSent(function (Request $request) {
            if ($request->url() !== $this->threadUrl(self::THREAD_A)) {
                return false;
            }

            $body = (string) $request->body();
            $embed = $request->data()['embeds'][0] ?? [];

            return str_contains($body, 'Host User')
                && str_contains($body, substr(self::bulletinContent(), 0, 60))
                && ! str_contains($body, self::TAIL_MARKER)
                && str_contains($body, 'Read the full update on the session page.')
                && isset($embed['url'])
                && str_contains((string) $embed['url'], '/games/');
        });
    }

    #[Test]
    public function teaser_never_carries_content_beyond_the_snippet_limit(): void
    {
        [$game, $bulletin] = $this->gameWithBulletin(threadACard: true);

        $bodies = [];
        Http::fake(function ($request) use (&$bodies) {
            $bodies[] = (string) $request->body();

            return Http::response(['id' => 'msg-a'], 200);
        });

        $this->runJob($bulletin->id);

        $this->assertCount(1, $bodies);
        $this->assertNotSame('', $bodies[0]);

        // The ≤100-char prefix is present; anything past it is not.
        $this->assertStringContainsString(substr(self::bulletinContent(), 0, 80), $bodies[0]);
        $this->assertStringNotContainsString(self::TAIL_MARKER, $bodies[0]);
    }

    // ── Idempotency ───────────────────────────────────────────────────

    #[Test]
    public function teaser_neutralizes_masked_link_markdown_in_public_threads(): void
    {
        // The in-app board shows this verbatim as plain text; if the teaser
        // carried it unchanged, Discord would render a clickable cloaked link
        // in a guild-public thread.
        $phishing = '[totally safe page](https://evil.example/phish) and a bare https://example.com/ok';
        $host = User::factory()->create(['name' => 'Host User']);
        $game = Game::factory()->create(['owner_id' => $host->id]);
        $bulletin = GameBulletin::factory()->for($game)->for($host, 'user')->create([
            'content' => $phishing,
        ]);
        DiscordCardMessage::factory()->for($game)->create(['thread_id' => self::THREAD_A]);

        $descriptions = [];
        Http::fake(function ($request) use (&$descriptions) {
            $descriptions[] = (string) ($request->data()['embeds'][0]['description'] ?? '');

            return Http::response(['id' => 'msg-a'], 200);
        });

        $this->runJob($bulletin->id);

        $this->assertCount(1, $descriptions);
        // The masked-link adjacency is broken…
        $this->assertStringNotContainsString('](https://', $descriptions[0]);
        // …while every character stays visible, and bare URLs remain intact
        // (Discord auto-links them verbatim — no cloaking vector).
        $this->assertStringContainsString('[totally safe page] (https://evil.example/phish)', $descriptions[0]);
        $this->assertStringContainsString('a bare https://example.com/ok', $descriptions[0]);
    }

    #[Test]
    public function teaser_follows_the_games_language(): void
    {
        $host = User::factory()->create(['name' => 'Host User']);
        $game = Game::factory()->create(['owner_id' => $host->id, 'language' => 'de']);
        $bulletin = GameBulletin::factory()->for($game)->for($host, 'user')->create([
            'content' => 'Wir starten 30 Minuten später.',
        ]);
        DiscordCardMessage::factory()->for($game)->create(['thread_id' => self::THREAD_A]);

        $embeds = [];
        Http::fake(function ($request) use (&$embeds) {
            $embed = $request->data()['embeds'][0] ?? [];
            $embeds[] = [(string) ($embed['title'] ?? ''), (string) ($embed['description'] ?? '')];

            return Http::response(['id' => 'msg-a'], 200);
        });

        $this->runJob($bulletin->id);

        $this->assertCount(1, $embeds);
        [$title, $description] = $embeds[0];
        $this->assertStringContainsString('Neues Update für', $title);
        $this->assertStringContainsString('Host User hat ein Update für', $description);
        $this->assertStringContainsString('Lies das vollständige Update auf der Session-Seite.', $description);
        $this->assertStringNotContainsString('New update for', $title.$description);
    }

    #[Test]
    public function skips_threads_that_already_have_a_tracking_row(): void
    {
        [, , $bulletin, $cards] = $this->gameWithBulletinAndCards(threadACard: true, threadBCard: true);

        DiscordBulletinMessage::factory()
            ->for($bulletin, 'bulletin')
            ->create([
                'guild_id' => $cards[0]->guild_id,
                'thread_id' => self::THREAD_A,
            ]);

        Http::fake([
            $this->threadUrl(self::THREAD_B) => Http::response(['id' => 'msg-b'], 200),
        ]);

        $this->runJob($bulletin->id);

        // Only the un-tracked thread was addressed.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === $this->threadUrl(self::THREAD_B));
        $this->assertSame(1, DiscordBulletinMessage::where('bulletin_id', $bulletin->id)->where('thread_id', self::THREAD_B)->count());
    }

    #[Test]
    public function a_retry_after_partial_success_posts_only_missing_threads(): void
    {
        [$game, $bulletin] = $this->gameWithBulletin(threadACard: true, threadBCard: true);

        // Thread A succeeds immediately; thread B answers 5xx once (retryable
        // → the job bubbles without writing a row), then succeeds.
        Http::fake([
            $this->threadUrl(self::THREAD_A) => Http::response(['id' => 'msg-a'], 200),
            $this->threadUrl(self::THREAD_B) => Http::sequence()
                ->push(['error' => 'boom'], 500)
                ->push(['id' => 'msg-b'], 200),
        ]);

        try {
            $this->runJob($bulletin->id);
            $this->fail('Expected a retryable DiscordApiException on the first run.');
        } catch (DiscordApiException) {
            // Expected — thread A posted, thread B left untracked for retry.
        }

        $this->assertThreadPosted(self::THREAD_A, 'msg-a', $bulletin);
        $this->assertSame(0, DiscordBulletinMessage::where('bulletin_id', $bulletin->id)->where('thread_id', self::THREAD_B)->count());

        // Queue retry: only thread B is attempted.
        $this->runJob($bulletin->id);

        Http::assertSentCount(3); // A once, B twice (failure + success)
        $this->assertSame(1, DiscordBulletinMessage::where('bulletin_id', $bulletin->id)->where('thread_id', self::THREAD_A)->count());
        $this->assertThreadPosted(self::THREAD_B, 'msg-b', $bulletin);
    }

    // ── Terminal failures ─────────────────────────────────────────────

    #[Test]
    public function terminal_client_error_records_failed_row_and_continues(): void
    {
        [$game, $bulletin] = $this->gameWithBulletin(threadACard: true, threadBCard: true);

        Http::fake([
            $this->threadUrl(self::THREAD_A) => Http::response(['id' => 'msg-a'], 200),
            $this->threadUrl(self::THREAD_B) => Http::response(['error' => 'missing permissions'], 403),
        ]);

        // No exception bubbles — the remaining thread still gets its teaser.
        $this->runJob($bulletin->id);

        $this->assertThreadPosted(self::THREAD_A, 'msg-a', $bulletin);

        $failed = DiscordBulletinMessage::where('bulletin_id', $bulletin->id)
            ->where('thread_id', self::THREAD_B)
            ->first();
        $this->assertNotNull($failed);
        $this->assertSame(DiscordBulletinMessage::STATUS_FAILED, $failed->status);
        $this->assertSame(403, $failed->error_code);
        $this->assertNull($failed->message_id);

        // A retry does not churn on the terminally-failed thread.
        $this->runJob($bulletin->id);
        Http::assertSentCount(2);
        $this->assertSame(1, DiscordBulletinMessage::where('bulletin_id', $bulletin->id)->where('thread_id', self::THREAD_B)->count());
    }

    // ── No-ops ────────────────────────────────────────────────────────

    #[Test]
    public function no_ops_when_the_game_has_no_live_threads(): void
    {
        [$game, $bulletin] = $this->gameWithBulletin(noThreadCard: true, pendingCard: true);

        Http::fake();

        $this->runJob($bulletin->id);

        Http::assertNothingSent();
        $this->assertSame(0, DiscordBulletinMessage::where('bulletin_id', $bulletin->id)->count());
    }

    #[Test]
    public function no_ops_when_publishing_is_disabled(): void
    {
        config(['services.discord.publishing_enabled' => false]);

        [$game, $bulletin] = $this->gameWithBulletin(threadACard: true);

        Http::fake();

        $this->runJob($bulletin->id);

        Http::assertNothingSent();
    }

    #[Test]
    public function no_ops_when_session_threads_are_disabled(): void
    {
        config(['services.discord.session_threads_enabled' => false]);

        [$game, $bulletin] = $this->gameWithBulletin(threadACard: true);

        Http::fake();

        $this->runJob($bulletin->id);

        Http::assertNothingSent();
    }

    #[Test]
    public function exits_cleanly_when_the_bulletin_was_deleted(): void
    {
        Http::fake();

        $this->runJob((string) Str::orderedUuid());

        Http::assertNothingSent();
    }

    #[Test]
    public function failure_logs_structured_error(): void
    {
        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
            return $message === 'discord_bulletin.job.failed'
                && $context['bulletin_id'] === 'missing'
                && str_contains((string) $context['exception'], 'queue gave up');
        });

        (new PublishGameBulletinToDiscord('missing'))->failed(new \RuntimeException('queue gave up'));
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function runJob(string $bulletinId): void
    {
        (new PublishGameBulletinToDiscord($bulletinId))
            ->handle($this->app->make(DiscordWebhookClient::class));
    }

    /**
     * The bulletin body: exactly 100 padding chars, then a unique marker the
     * teaser must never carry, then more padding past the 280-char UI cap
     * shape (the marker would be visible in a full-body post).
     */
    private static function bulletinContent(): string
    {
        return str_repeat('A', PublishGameBulletinToDiscord::TEASER_SNIPPET_LENGTH).self::TAIL_MARKER.str_repeat('B', 120);
    }

    private function threadUrl(string $threadId): string
    {
        return self::BASE_URL."/channels/{$threadId}/messages";
    }

    private function assertThreadPosted(string $threadId, string $messageId, GameBulletin $bulletin): void
    {
        $row = DiscordBulletinMessage::where('bulletin_id', $bulletin->id)
            ->where('thread_id', $threadId)
            ->first();

        $this->assertNotNull($row, "Expected a posted tracking row for thread {$threadId}.");
        $this->assertSame(DiscordBulletinMessage::STATUS_POSTED, $row->status);
        $this->assertSame($messageId, $row->message_id);
        $this->assertNull($row->error_code);
    }

    /**
     * @param  array{0: User, 1: Game, 2: GameBulletin, 3: array<int, DiscordCardMessage>}
     */
    private function gameWithBulletinAndCards(bool $threadACard = false, bool $threadBCard = false, bool $noThreadCard = false, bool $pendingCard = false): array
    {
        $host = User::factory()->create(['name' => 'Host User']);
        $game = Game::factory()->create(['owner_id' => $host->id]);
        $bulletin = GameBulletin::factory()->for($game)->for($host, 'user')->create([
            'content' => self::bulletinContent(),
        ]);

        $cards = [];
        if ($threadACard) {
            $cards[] = DiscordCardMessage::factory()->for($game)->create(['thread_id' => self::THREAD_A]);
        }
        if ($threadBCard) {
            $cards[] = DiscordCardMessage::factory()->for($game)->create(['thread_id' => self::THREAD_B]);
        }
        if ($noThreadCard) {
            $cards[] = DiscordCardMessage::factory()->for($game)->create(['thread_id' => null]);
        }
        if ($pendingCard) {
            $cards[] = DiscordCardMessage::factory()->for($game)->pending()->create(['thread_id' => null]);
        }

        return [$host, $game, $bulletin, $cards];
    }

    /**
     * @param  array{0: Game, 1: GameBulletin}
     */
    private function gameWithBulletin(bool $threadACard = false, bool $threadBCard = false, bool $noThreadCard = false, bool $pendingCard = false): array
    {
        [, $game, $bulletin] = $this->gameWithBulletinAndCards($threadACard, $threadBCard, $noThreadCard, $pendingCard);

        return [$game, $bulletin];
    }
}
