<?php

namespace Tests\Feature\Services\Discord;

use App\Exceptions\DiscordApiException;
use App\Services\Discord\DiscordWebhookClient;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DiscordWebhookClientTest extends TestCase
{
    private const CHANNEL = '999000111222333444';

    private const MESSAGE_ID = '555666777888999000';

    private function makeClient(
        int $maxAttempts = 3,
        ?\Closure $sleep = null,
        ?float $maxRetryAfter = 30.0,
    ): DiscordWebhookClient {
        return new DiscordWebhookClient(
            baseUrl: 'https://discord.test/api/v10',
            botToken: 'test-bot-token',
            timeout: 5,
            maxAttempts: $maxAttempts,
            maxRetryAfterSeconds: $maxRetryAfter,
            serverErrorBackoffSeconds: 0.0,
            sleep: $sleep ?? static fn (float $s) => null,
        );
    }

    // ── postMessage ─────────────────────────────────────

    #[Test]
    public function post_message_returns_message_id_from_discord_response()
    {
        Http::fake([
            'discord.test/api/v10/channels/'.self::CHANNEL.'/messages' => Http::response([
                'id' => self::MESSAGE_ID,
                'channel_id' => self::CHANNEL,
            ], 200),
        ]);

        $client = $this->makeClient();
        $id = $client->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'Game']));

        $this->assertSame(self::MESSAGE_ID, $id);
    }

    #[Test]
    public function post_message_sends_bot_authorization_header_and_json_body()
    {
        $captured = [];

        Http::fake(function (Request $request) use (&$captured) {
            $captured['headers'] = $request->headers();
            $captured['body'] = $request->data();
            $captured['method'] = $request->method();
            $captured['url'] = $request->url();

            return Http::response(['id' => self::MESSAGE_ID], 200);
        });

        $payload = DiscordWebhookPayload::embed(
            embed: ['title' => 'Catan Night'],
            components: [['type' => 1, 'components' => []]],
        );

        $this->makeClient()->postMessage(self::CHANNEL, $payload);

        $this->assertSame('POST', $captured['method']);
        $this->assertStringContainsString('/channels/'.self::CHANNEL.'/messages', $captured['url']);
        // Bot scheme — NOT Bearer (Discord rejects Bearer for bot endpoints).
        $this->assertSame('Bot test-bot-token', $captured['headers']['Authorization'][0] ?? null);
        // Body is the serialized payload.
        $this->assertArrayHasKey('embeds', $captured['body']);
        $this->assertSame('Catan Night', $captured['body']['embeds'][0]['title']);
        $this->assertArrayHasKey('components', $captured['body']);
    }

    #[Test]
    public function post_message_throws_when_response_lacks_message_id()
    {
        Http::fake([
            'discord.test/*' => Http::response(['foo' => 'bar'], 200),
        ]);

        $this->expectException(DiscordApiException::class);
        $this->expectExceptionMessageMatches('/did not contain a message id/');

        $this->makeClient()->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'x']));
    }

    // ── editMessage ─────────────────────────────────────

    #[Test]
    public function edit_message_patches_the_correct_message_id()
    {
        $captured = [];

        Http::fake(function (Request $request) use (&$captured) {
            $captured['method'] = $request->method();
            $captured['url'] = $request->url();

            return Http::response(['id' => self::MESSAGE_ID], 200);
        });

        $id = $this->makeClient()->editMessage(
            self::CHANNEL,
            self::MESSAGE_ID,
            DiscordWebhookPayload::embed(['title' => 'Updated']),
        );

        $this->assertSame(self::MESSAGE_ID, $id);
        $this->assertSame('PATCH', $captured['method']);
        $this->assertStringEndsWith(
            '/channels/'.self::CHANNEL.'/messages/'.self::MESSAGE_ID,
            $captured['url'],
        );
    }

    // ── deleteMessage ───────────────────────────────────

    #[Test]
    public function delete_message_deletes_by_channel_and_message_id()
    {
        $captured = [];

        Http::fake(function (Request $request) use (&$captured) {
            $captured['method'] = $request->method();
            $captured['url'] = $request->url();

            return Http::response('', 204);
        });

        $this->makeClient()->deleteMessage(self::CHANNEL, self::MESSAGE_ID);

        $this->assertSame('DELETE', $captured['method']);
        $this->assertStringEndsWith(
            '/channels/'.self::CHANNEL.'/messages/'.self::MESSAGE_ID,
            $captured['url'],
        );
    }

    // ── createDmChannel ─────────────────────────────────

    private const DM_CHANNEL_ID = '808182838485868788';

    private const RECIPIENT_SNOWFLAKE = '909192939495969798';

    #[Test]
    public function create_dm_channel_returns_channel_id_from_discord_response()
    {
        Http::fake([
            'discord.test/api/v10/users/@me/channels' => Http::response([
                'id' => self::DM_CHANNEL_ID,
                'type' => 1, // DM channel type
                'recipients' => [],
            ], 200),
        ]);

        $id = $this->makeClient()->createDmChannel(self::RECIPIENT_SNOWFLAKE);

        $this->assertSame(self::DM_CHANNEL_ID, $id);
    }

    #[Test]
    public function create_dm_channel_posts_recipient_id_body_with_bot_authorization_header()
    {
        $captured = [];

        Http::fake(function (Request $request) use (&$captured) {
            $captured['headers'] = $request->headers();
            $captured['body'] = $request->data();
            $captured['method'] = $request->method();
            $captured['url'] = $request->url();

            return Http::response(['id' => self::DM_CHANNEL_ID], 200);
        });

        $this->makeClient()->createDmChannel(self::RECIPIENT_SNOWFLAKE);

        $this->assertSame('POST', $captured['method']);
        $this->assertStringEndsWith('/users/@me/channels', $captured['url']);
        // Bot scheme — NOT Bearer (Discord rejects Bearer for bot endpoints).
        $this->assertSame('Bot test-bot-token', $captured['headers']['Authorization'][0] ?? null);
        // Body carries the recipient snowflake, exactly as Discord's DM-create spec requires.
        $this->assertSame(
            ['recipient_id' => self::RECIPIENT_SNOWFLAKE],
            $captured['body'],
        );
    }

    #[Test]
    public function create_dm_channel_throws_when_response_lacks_channel_id()
    {
        Http::fake([
            'discord.test/*' => Http::response(['foo' => 'bar'], 200),
        ]);

        $this->expectException(DiscordApiException::class);
        $this->expectExceptionMessageMatches('/did not contain a channel id/');

        $this->makeClient()->createDmChannel(self::RECIPIENT_SNOWFLAKE);
    }

    #[Test]
    public function create_dm_channel_throws_when_response_id_is_empty_string()
    {
        Http::fake([
            'discord.test/*' => Http::response(['id' => ''], 200),
        ]);

        $this->expectException(DiscordApiException::class);
        $this->expectExceptionMessageMatches('/did not contain a channel id/');

        $this->makeClient()->createDmChannel(self::RECIPIENT_SNOWFLAKE);
    }

    /**
     * The shared-guild 403 is the highest-risk DM-delivery path (research §10):
     * a user who linked Discord but never joined a roundup guild gets
     * `403 Cannot send messages to this user`. createDmChannel surfaces it as a
     * non-retryable DiscordApiException (requestFailed) so the channel layer
     * (T02) can catch + degrade gracefully. This test pins that contract.
     */
    #[Test]
    public function create_dm_channel_surfaces_shared_guild_403_as_non_retryable_failure()
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response([
                'message' => 'Cannot send messages to this user',
                'code' => 40007,
            ], 403);
        });

        try {
            $this->makeClient(maxAttempts: 3)->createDmChannel(self::RECIPIENT_SNOWFLAKE);
            $this->fail('Expected DiscordApiException for shared-guild 403.');
        } catch (DiscordApiException $e) {
            $this->assertMatchesRegularExpression(
                '/failed with status 403/',
                $e->getMessage(),
            );
            $this->assertSame(1, $calls, '4xx must NOT retry — surfaces immediately so T02 can degrade');
        }
    }

    #[Test]
    public function create_dm_channel_client_error_logs_structured_error_before_throwing()
    {
        Log::spy();

        Http::fake([
            'discord.test/*' => Http::response(['message' => 'Unauthorized', 'code' => 0], 401),
        ]);

        try {
            $this->makeClient()->createDmChannel(self::RECIPIENT_SNOWFLAKE);
        } catch (DiscordApiException $e) {
            // expected
        }

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) {
                return $message === 'Discord API client error'
                    && ($context['endpoint'] ?? null) === 'users/@me/channels'
                    && ($context['status'] ?? null) === 401;
            })
            ->atLeast()
            ->once();
    }

    // ── 429 rate-limit backoff ──────────────────────────

    #[Test]
    public function rate_limited_429_triggers_backoff_respecting_retry_after_then_retries()
    {
        $calls = 0;
        $sleeps = [];

        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                // Discord per-route 429 carries retry_after (float seconds) in JSON.
                return Http::response(['message' => 'You are being rate limited.', 'retry_after' => 0.25], 429);
            }

            return Http::response(['id' => self::MESSAGE_ID], 200);
        });

        $client = $this->makeClient(
            sleep: static function (float $seconds) use (&$sleeps) {
                $sleeps[] = $seconds;
            },
        );

        $id = $client->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'x']));

        $this->assertSame(self::MESSAGE_ID, $id);
        $this->assertCount(1, $sleeps, 'exactly one backoff sleep before the retry');
        $this->assertSame(0.25, $sleeps[0], 'backoff respects the JSON retry_after');
        $this->assertSame(2, $calls, 'initial 429 plus one retry');
    }

    #[Test]
    public function rate_limited_429_uses_retry_after_header_when_json_body_absent()
    {
        $calls = 0;
        $sleeps = [];

        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                // Global 429 carries Retry-After header (integer seconds), no JSON body.
                return Http::response('', 429, ['Retry-After' => '2']);
            }

            return Http::response(['id' => self::MESSAGE_ID], 200);
        });

        $client = $this->makeClient(
            sleep: static function (float $seconds) use (&$sleeps) {
                $sleeps[] = $seconds;
            },
        );

        $id = $client->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'x']));

        $this->assertSame(self::MESSAGE_ID, $id);
        $this->assertSame(2.0, $sleeps[0]);
    }

    #[Test]
    public function rate_limited_429_with_no_retry_after_uses_safe_default()
    {
        $calls = 0;
        $sleeps = [];

        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response('Rate limited', 429);
            }

            return Http::response(['id' => self::MESSAGE_ID], 200);
        });

        $client = $this->makeClient(
            sleep: static function (float $seconds) use (&$sleeps) {
                $sleeps[] = $seconds;
            },
        );

        $client->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'x']));

        $this->assertSame(1.0, $sleeps[0], 'falls back to a 1s default when no directive present');
    }

    #[Test]
    public function rate_limited_429_exceeding_max_retry_after_throws_immediately()
    {
        Http::fake([
            'discord.test/*' => Http::response(['retry_after' => 60.0], 429),
        ]);

        $client = $this->makeClient(maxRetryAfter: 30.0);

        $this->expectException(DiscordApiException::class);
        $this->expectExceptionMessageMatches('/rate-limited.*60/');

        $client->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'x']));
    }

    #[Test]
    public function rate_limited_429_exhausting_attempts_throws()
    {
        Http::fake([
            'discord.test/*' => Http::response(['retry_after' => 0.1], 429),
        ]);

        $client = $this->makeClient(maxAttempts: 2);

        $this->expectException(DiscordApiException::class);
        $this->expectExceptionMessageMatches('/rate-limited/');

        $client->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'x']));
    }

    // ── 5xx server errors ───────────────────────────────

    #[Test]
    public function server_error_5xx_retries_then_throws_when_exhausted()
    {
        Http::fake([
            'discord.test/*' => Http::sequence()
                ->push('', 503)
                ->push('', 502)
                ->push('', 500),
        ]);

        $this->expectException(DiscordApiException::class);
        $this->expectExceptionMessageMatches('/server error 500/');

        $this->makeClient(maxAttempts: 3)->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'x']));
    }

    #[Test]
    public function server_error_5xx_recovers_on_retry()
    {
        Http::fake([
            'discord.test/*' => Http::sequence()
                ->push('', 503)
                ->push(['id' => self::MESSAGE_ID], 200),
        ]);

        $id = $this->makeClient()->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'x']));

        $this->assertSame(self::MESSAGE_ID, $id);
    }

    // ── 4xx client errors ───────────────────────────────

    #[Test]
    public function client_error_4xx_is_non_retryable_and_throws_immediately()
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['message' => '401: Unauthorized', 'code' => 0], 401);
        });

        $this->expectException(DiscordApiException::class);
        $this->expectExceptionMessageMatches('/failed with status 401/');

        try {
            $this->makeClient(maxAttempts: 3)->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'x']));
        } finally {
            $this->assertSame(1, $calls, '4xx must NOT retry');
        }
    }

    // ── connection failures ─────────────────────────────

    #[Test]
    public function connection_exception_retries_then_throws_when_exhausted()
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timed out');
        });

        $this->expectException(DiscordApiException::class);
        $this->expectExceptionMessageMatches('/connection.*timed out/i');

        $this->makeClient(maxAttempts: 2)->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'x']));
    }

    #[Test]
    public function connection_exception_recovers_on_retry()
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('DNS failure');
            }

            return Http::response(['id' => self::MESSAGE_ID], 200);
        });

        $id = $this->makeClient()->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'x']));

        $this->assertSame(self::MESSAGE_ID, $id);
    }

    // ── observability ───────────────────────────────────

    #[Test]
    public function transient_failures_log_retry_warnings_with_structured_context()
    {
        Log::spy();

        Http::fake([
            'discord.test/*' => Http::sequence()
                ->push('', 503)
                ->push(['id' => self::MESSAGE_ID], 200),
        ]);

        $this->makeClient()->postMessage(self::CHANNEL, DiscordWebhookPayload::embed(['title' => 'x']));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) {
                return $message === 'Discord API transient failure; retrying'
                    && ($context['reason'] ?? null) === 'server_error'
                    && ($context['endpoint'] ?? null) === 'channels/'.self::CHANNEL.'/messages'
                    && ($context['status'] ?? null) === 503
                    && ($context['attempt'] ?? null) === 1;
            })
            ->atLeast()
            ->once();
    }

    // ── payload serialization ───────────────────────────

    #[Test]
    public function payload_omits_null_fields_so_discord_applies_its_defaults()
    {
        $captured = [];

        Http::fake(function (Request $request) use (&$captured) {
            $captured['body'] = $request->data();

            return Http::response(['id' => self::MESSAGE_ID], 200);
        });

        $this->makeClient()->postMessage(
            self::CHANNEL,
            DiscordWebhookPayload::embed(['title' => 'Game']),
        );

        // embed() sets content=null, embeds=[...], components=null → only
        // embeds should be present in the serialized body.
        $this->assertArrayHasKey('embeds', $captured['body']);
        $this->assertArrayNotHasKey('content', $captured['body']);
        $this->assertArrayNotHasKey('components', $captured['body']);
        $this->assertArrayNotHasKey('flags', $captured['body']);
        $this->assertArrayNotHasKey('allowed_mentions', $captured['body']);
        $this->assertArrayNotHasKey('tts', $captured['body']);
    }
}
