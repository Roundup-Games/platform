<?php

namespace Tests\Feature\Notifications;

use App\Enums\OAuthProvider;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Services\Discord\DiscordWebhookClient;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers DiscordChannel (D118) — the custom notification channel that delivers
 * a Discord DM, mirroring PushChannel's graceful-degradation contract.
 *
 * Asserts the two-step bot-REST flow (createDmChannel + postMessage), the
 * auto-derived payload from toDatabase(), the toDiscord() override, and every
 * graceful no-op path the slice verification names: no_linked_account,
 * publishing_disabled, bot_token_missing (incl. null client), dm_opt_out,
 * dm_channel_failed (shared-guild 403), and send_failed.
 */
class DiscordChannelTest extends TestCase
{
    private const DM_CHANNEL = '888777666555444333';

    private const MESSAGE_ID = '999888777666555444';

    /**
     * Per-call snowflake sequence so each linked account is unique. The
     * linked_accounts table enforces a unique constraint on
     * (provider, provider_user_id); reusing one snowflake across tests that
     * share DB state trips a duplicate-key violation.
     */
    private static int $snowflakeSeq = 111222333444555600;

    private function uniqueSnowflake(): string
    {
        return (string) (self::$snowflakeSeq++);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Default to "fully configured"; individual tests opt out of gates.
        config()->set('services.discord.publishing_enabled', true);
        config()->set('services.discord.bot_token', 'test-bot-token');
    }

    private function makeClient(): DiscordWebhookClient
    {
        return new DiscordWebhookClient(
            baseUrl: 'https://discord.test/api/v10',
            botToken: 'test-bot-token',
            timeout: 5,
            maxAttempts: 3,
            maxRetryAfterSeconds: 30.0,
            serverErrorBackoffSeconds: 0.0,
            sleep: static fn (float $s) => null,
        );
    }

    private function channel(): DiscordChannel
    {
        return new DiscordChannel($this->makeClient());
    }

    /**
     * Create a real user with a linked Discord account (provider_user_id = the
     * snowflake the channel must POST as recipient_id).
     */
    private function linkedUser(): User
    {
        $user = User::factory()->create();

        LinkedAccount::factory()->create([
            'user_id' => $user,
            'provider' => OAuthProvider::Discord,
            'provider_user_id' => $this->uniqueSnowflake(),
        ]);

        return $user;
    }

    /**
     * Minimal notification with a toDatabase() array (the auto-derive source).
     */
    private function databaseNotification(array $data = []): Notification
    {
        $data = $data !== [] ? $data : [
            'type' => 'waitlist_promoted',
            'entity_name' => 'Catan Night',
            'action_url' => 'https://roundup.test/games/123',
        ];

        return new class($data) extends Notification
        {
            public function __construct(private array $data) {}

            public function via(object $notifiable): array
            {
                return [];
            }

            public function toDatabase(object $notifiable): array
            {
                return $this->data;
            }
        };
    }

    /**
     * Notification that overrides toDiscord() (used for opt-out + verbatim tests).
     */
    private function discordOverrideNotification(?DiscordWebhookPayload $payload): Notification
    {
        return new class($payload) extends Notification
        {
            public function __construct(private ?DiscordWebhookPayload $payload) {}

            public function via(object $notifiable): array
            {
                return [];
            }

            public function toDiscord(object $notifiable): ?DiscordWebhookPayload
            {
                return $this->payload;
            }
        };
    }

    // ── User::discordLinkedAccount accessor ─────────────

    #[Test]
    public function discord_linked_account_accessor_returns_the_discord_linked_account(): void
    {
        $user = User::factory()->create();
        $snowflake = $this->uniqueSnowflake();

        LinkedAccount::factory()->create([
            'user_id' => $user,
            'provider' => OAuthProvider::Discord,
            'provider_user_id' => $snowflake,
        ]);

        $account = $user->discordLinkedAccount();

        $this->assertNotNull($account);
        $this->assertSame(OAuthProvider::Discord, $account->provider);
        $this->assertSame($snowflake, $account->provider_user_id);
    }

    #[Test]
    public function discord_linked_account_accessor_returns_null_when_no_discord_account_linked(): void
    {
        $user = User::factory()->create();

        // A non-Discord account must NOT satisfy the accessor.
        LinkedAccount::factory()->create([
            'user_id' => $user,
            'provider' => OAuthProvider::Google,
            'provider_user_id' => 'google-123',
        ]);

        $this->assertNull($user->discordLinkedAccount());
    }

    // ── Happy path: two-step DM delivery ────────────────

    #[Test]
    public function send_creates_dm_channel_then_posts_message_in_order_and_logs_sent(): void
    {
        $calls = [];

        Http::fake(function (Request $request) use (&$calls) {
            $calls[] = [
                'method' => $request->method(),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_contains($request->url(), 'users/@me/channels')) {
                return Http::response(['id' => self::DM_CHANNEL], 200);
            }

            return Http::response(['id' => self::MESSAGE_ID], 200);
        });

        Log::spy();

        $user = $this->linkedUser();
        $snowflake = $user->discordLinkedAccount()->provider_user_id;
        $this->channel()->send($user, $this->databaseNotification());

        // Two calls, in order: DM-channel creation first, then the message post.
        $this->assertCount(2, $calls);
        $this->assertSame('POST', $calls[0]['method']);
        $this->assertStringEndsWith('/users/@me/channels', $calls[0]['url']);
        $this->assertSame(['recipient_id' => $snowflake], $calls[0]['body']);
        $this->assertSame('POST', $calls[1]['method']);
        $this->assertStringContainsString('/channels/'.self::DM_CHANNEL.'/messages', $calls[1]['url']);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($user) {
                return $message === 'notification.discord_dm_sent'
                    && ($context['user_id'] ?? null) === $user->id
                    && ($context['category'] ?? null) === 'waitlist_promoted'
                    && ($context['dm_channel_id'] ?? null) === self::DM_CHANNEL;
            })
            ->once();
    }

    // ── Graceful no-op paths ────────────────────────────

    #[Test]
    public function send_skips_with_no_linked_account_reason_when_user_has_no_discord_account(): void
    {
        Http::fake(); // no calls should happen

        Log::spy();

        $user = User::factory()->create(); // no linked account

        $this->channel()->send($user, $this->databaseNotification());

        Http::assertNothingSent();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'no_linked_account')
            ->once();
    }

    #[Test]
    public function send_skips_when_publishing_is_disabled(): void
    {
        config()->set('services.discord.publishing_enabled', false);

        Http::fake();

        Log::spy();

        $user = $this->linkedUser();

        $this->channel()->send($user, $this->databaseNotification());

        Http::assertNothingSent();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'publishing_disabled')
            ->once();
    }

    #[Test]
    public function send_skips_when_bot_token_is_missing(): void
    {
        config()->set('services.discord.bot_token', '');

        Http::fake();

        Log::spy();

        $user = $this->linkedUser();

        $this->channel()->send($user, $this->databaseNotification());

        Http::assertNothingSent();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'bot_token_missing')
            ->once();
    }

    #[Test]
    public function send_skips_with_bot_token_missing_reason_when_client_is_null(): void
    {
        Http::fake();

        Log::spy();

        $user = $this->linkedUser();

        // Null client = Discord unconfigured (mirrors PushChannel's null guard).
        (new DiscordChannel(null))->send($user, $this->databaseNotification());

        Http::assertNothingSent();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'bot_token_missing')
            ->once();
    }

    #[Test]
    public function send_skips_with_dm_opt_out_reason_when_to_discord_returns_null(): void
    {
        Http::fake();

        Log::spy();

        $user = $this->linkedUser();

        $this->channel()->send($user, $this->discordOverrideNotification(null));

        Http::assertNothingSent();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'dm_opt_out')
            ->once();
    }

    #[Test]
    public function send_uses_to_discord_override_payload_verbatim(): void
    {
        $captured = [];

        Http::fake(function (Request $request) use (&$captured) {
            if (str_contains($request->url(), 'users/@me/channels')) {
                return Http::response(['id' => self::DM_CHANNEL], 200);
            }
            $captured = $request->data();

            return Http::response(['id' => self::MESSAGE_ID], 200);
        });

        $user = $this->linkedUser();

        $payload = DiscordWebhookPayload::embed(['title' => 'Custom DM']);

        $this->channel()->send($user, $this->discordOverrideNotification($payload));

        $this->assertSame('Custom DM', $captured['embeds'][0]['title'] ?? null);
    }

    #[Test]
    public function send_auto_derives_content_from_to_database_keys(): void
    {
        $captured = [];

        Http::fake(function (Request $request) use (&$captured) {
            if (str_contains($request->url(), 'users/@me/channels')) {
                return Http::response(['id' => self::DM_CHANNEL], 200);
            }
            $captured = $request->data();

            return Http::response(['id' => self::MESSAGE_ID], 200);
        });

        $user = $this->linkedUser();

        $this->channel()->send($user, $this->databaseNotification([
            'type' => 'waitlist_promoted',
            'entity_name' => 'Catan Night',
            'action_url' => 'https://roundup.test/games/123',
        ]));

        // entity_name + action_url joined with a newline.
        $this->assertSame("Catan Night\nhttps://roundup.test/games/123", $captured['content'] ?? null);
    }

    // ── Failure paths: graceful, never throws ───────────

    #[Test]
    public function send_treats_shared_guild_403_as_graceful_dm_channel_failed_noop(): void
    {
        // The shared-guild 403 (40007) on DM-channel creation (research §10).
        Http::fake([
            'discord.test/*' => Http::response(['message' => 'Cannot send messages to this user', 'code' => 40007], 403),
        ]);

        Log::spy();

        $user = $this->linkedUser();

        // Must not throw — the whole point of the graceful contract.
        $this->channel()->send($user, $this->databaseNotification());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_api_error'
                && ($c['step'] ?? null) === 'create_dm_channel')
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'dm_channel_failed')
            ->once();
    }

    #[Test]
    public function send_treats_post_message_failure_as_graceful_send_failed_noop(): void
    {
        // DM channel opens fine; message post fails (missing message id).
        Http::fake([
            'discord.test/api/v10/users/@me/channels' => Http::response(['id' => self::DM_CHANNEL], 200),
            'discord.test/api/v10/channels/'.self::DM_CHANNEL.'/messages' => Http::response(['nope' => true], 200),
        ]);

        Log::spy();

        $user = $this->linkedUser();

        $this->channel()->send($user, $this->databaseNotification());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_api_error'
                && ($c['step'] ?? null) === 'post_message')
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'send_failed')
            ->once();
    }
}
