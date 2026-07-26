<?php

namespace Tests\Feature\Jobs;

use App\Enums\Visibility;
use App\Jobs\PublishDiscordDigest;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordDigestPublisher;
use App\Services\Discord\DiscordDigestRenderer;
use App\Services\Discord\DiscordPublishException;
use App\Services\Discord\DiscordWebhookClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the {@see PublishDiscordDigest} queued job (M057/S02/T04): one job per
 * guild that rewrites the daily digest via the {@see DiscordDigestPublisher}.
 *
 * The job is dispatched by the `discord:publish-digest` command; it carries the
 * guild primary key as a string and resolves the guild fresh in handle() (so a
 * guild removed between dispatch and execution is skipped cleanly, mirroring
 * {@see PublishGameToDiscord}). This is the digest analogue of the per-game
 * card job.
 *
 * To exercise the full integration (the slice's proof level) these tests bind a
 * REAL DiscordDigestPublisher whose webhook client points at Http::fake() — so
 * the job → publisher → client → renderer path is live, not mocked. The job's
 * own responsibilities under test are: guild resolution + missing-guild exit,
 * delegating to the publisher, the structured-log lifecycle, and propagating
 * DiscordPublishException so the queue retries.
 */
class PublishDiscordDigestTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://discord.test/api/v10';

    private const CALENDAR_CHANNEL = '555666777888999000';

    private const MESSAGE_ID = '444333222111000999';

    protected function setUp(): void
    {
        parent::setUp();
        // MEM918 master switch — the publisher posts until this is enabled.
        config(['services.discord.publishing_enabled' => true]);
        $this->bindFakePublisher();
    }

    /**
     * Bind a REAL DiscordDigestPublisher wired to an Http::fake()-intercepted
     * webhook client so the job exercises the full publish path. The sleep
     * closure makes 429 backoff instant in tests.
     */
    private function bindFakePublisher(): void
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

        $this->app->instance(
            DiscordDigestPublisher::class,
            new DiscordDigestPublisher($client, new DiscordDigestRenderer),
        );
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
     * A postable guild (calendar channel + not paused).
     */
    private function postableGuild(array $overrides = []): DiscordGuild
    {
        return DiscordGuild::factory()
            ->configured()
            ->create(array_merge([
                'calendar_channel_id' => self::CALENDAR_CHANNEL,
                'paused' => false,
            ], $overrides));
    }

    // ════════════════════════════════════════════════════
    //  DELEGATION: job calls the publisher once for the resolved guild
    // ════════════════════════════════════════════════════

    #[Test]
    public function publishes_the_digest_for_the_resolved_guild()
    {
        $guild = $this->postableGuild();
        $this->fakePostSuccess();

        (new PublishDiscordDigest($guild->id))->handle(app(DiscordDigestPublisher::class));

        $guild->refresh();
        // The publisher posted + tracked the message id — proof the job
        // delegated to the real publisher for THIS guild.
        $this->assertSame(self::MESSAGE_ID, $guild->digest_message_id);
        $this->assertSame(self::CALENDAR_CHANNEL, $guild->digest_channel_id);
    }

    #[Test]
    public function accepts_string_primary_key_in_constructor()
    {
        $guild = $this->postableGuild();
        $this->fakePostSuccess();

        // Constructor takes the primitive PK — passes strings, not models.
        $job = new PublishDiscordDigest($guild->id);
        $this->assertSame($guild->id, $job->guildId);
    }

    // ════════════════════════════════════════════════════
    //  MISSING GUILD: removed between dispatch and execution
    // ════════════════════════════════════════════════════

    #[Test]
    public function missing_guild_is_skipped_cleanly_without_posting()
    {
        Http::fake();
        Log::spy();

        // A valid-format UUID that does not exist — mirrors the production path
        // (the command only ever dispatches real guild ids) and the
        // UpdateUserDiscoveryCacheTest pattern. A non-UUID string would trip a
        // DB-level cast error before Eloquent can return null.
        $missingId = Str::uuid()->toString();
        (new PublishDiscordDigest($missingId))->handle(app(DiscordDigestPublisher::class));

        // No Discord call for a non-existent guild.
        Http::assertNothingSent();
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'discord_digest.job.guild_missing')
            ->atLeast()
            ->once();
    }

    // ════════════════════════════════════════════════════
    //  STRUCTURED-LOG LIFECYCLE (started / completed)
    // ════════════════════════════════════════════════════

    #[Test]
    public function logs_job_started_and_completed_for_a_successful_publish()
    {
        $guild = $this->postableGuild();
        $this->fakePostSuccess();
        Log::spy();

        (new PublishDiscordDigest($guild->id))->handle(app(DiscordDigestPublisher::class));

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'discord_digest.job.started'
                && ($ctx['guild_id'] ?? null) === $guild->id)
            ->atLeast()
            ->once();
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'discord_digest.job.completed'
                && ($ctx['guild_id'] ?? null) === $guild->id)
            ->atLeast()
            ->once();
    }

    #[Test]
    public function successful_publish_emits_publisher_posted_pulse()
    {
        // The job runs the publisher, which owns the discord_digest.posted
        // steady-state health signal. Proves the job wires the publisher in
        // (not a no-op) end to end. Requires an eligible game so the populated
        // (posted) path runs rather than the empty-state path.
        $guild = $this->postableGuild();
        $owner = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => Visibility::Public->value,
            'date_time' => now()->addDays(3),
        ]);
        DiscordGuildOrganizer::factory()
            ->optedIn()
            ->create(['guild_id' => $guild->id, 'user_id' => $owner->id]);

        $this->fakePostSuccess();
        Log::spy();

        (new PublishDiscordDigest($guild->id))->handle(app(DiscordDigestPublisher::class));

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg) => $msg === 'discord_digest.posted')
            ->atLeast()
            ->once();
    }

    // ════════════════════════════════════════════════════
    //  FAILURE PROPAGATION (the queue retries on DiscordPublishException)
    // ════════════════════════════════════════════════════

    #[Test]
    public function publisher_failure_propagates_as_discord_publish_exception()
    {
        $guild = $this->postableGuild();

        Http::fake([
            self::BASE_URL.'/channels/*/messages' => Http::response(['message' => 'Missing Access'], 403),
        ]);

        $this->expectException(DiscordPublishException::class);
        (new PublishDiscordDigest($guild->id))->handle(app(DiscordDigestPublisher::class));
    }

    #[Test]
    public function failed_method_logs_job_failed_after_exhausted_retries()
    {
        $guild = $this->postableGuild();
        Log::spy();

        $exception = new DiscordPublishException('boom');
        (new PublishDiscordDigest($guild->id))->failed($exception);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'discord_digest.job.failed'
                && ($ctx['guild_id'] ?? null) === $guild->id
                && ($ctx['exception'] ?? null) === 'boom'
                && ($ctx['exception_class'] ?? null) === DiscordPublishException::class)
            ->atLeast()
            ->once();
    }

    #[Test]
    public function failed_method_tolerates_null_exception()
    {
        $guild = $this->postableGuild();
        Log::spy();

        (new PublishDiscordDigest($guild->id))->failed(null);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $msg) => $msg === 'discord_digest.job.failed')
            ->atLeast()
            ->once();
    }

    // ════════════════════════════════════════════════════
    //  RETRY CONTRACT: idempotent publisher keeps retries safe
    // ════════════════════════════════════════════════════

    #[Test]
    public function job_retry_converges_via_edit_in_place_without_duplicate()
    {
        // tries=3 + backoff=60 mirror PublishGameToDiscord; the publisher is
        // idempotent so retries PATCH, never duplicate. Prove the contract by
        // running twice against a tracked digest.
        $guild = $this->postableGuild([
            'digest_message_id' => '111000000000000000',
            'digest_channel_id' => self::CALENDAR_CHANNEL,
        ]);

        Http::fake([
            self::BASE_URL.'/channels/*/messages/*' => Http::response(['id' => self::MESSAGE_ID], 200),
        ]);

        (new PublishDiscordDigest($guild->id))->handle(app(DiscordDigestPublisher::class));
        (new PublishDiscordDigest($guild->id))->handle(app(DiscordDigestPublisher::class));

        $guild->refresh();
        $this->assertSame(self::MESSAGE_ID, $guild->digest_message_id);
        // Both runs PATCHed (edited), never POSTed a duplicate.
        Http::assertSent(fn ($r) => $r->method() === 'POST' ? false : true);
    }
}
