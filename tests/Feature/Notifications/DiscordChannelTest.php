<?php

namespace Tests\Feature\Notifications;

use App\Enums\OAuthProvider;
use App\Models\LinkedAccount;
use App\Models\NotificationEngagementCounter;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\Channels\DiscordChannel;
use App\Services\Discord\DiscordWebhookClient;
use App\Services\Discord\DiscordWebhookPayload;
use App\Services\NotificationEngagementCounters;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
    use DatabaseTransactions;

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

        // Freeze the clock: counter rows bucket on the UTC calendar date, so
        // a test straddling UTC midnight would write and read different daily
        // rows and false-fail.
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));

        // Default to "fully configured"; individual tests opt out of gates.
        config()->set('services.discord.publishing_enabled', true);
        config()->set('services.discord.bot_token', 'test-bot-token');
        // Dev/prod identity (D282): the app_id_short log marker is the last 4
        // characters of the configured application id.
        config()->set('services.discord.bot_application_id', '123456789012345678'); // gitleaks:allow — synthetic test-only Discord app id
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
        return new DiscordChannel(new NotificationEngagementCounters, $this->makeClient());
    }

    /**
     * Today's (category, discord, event) engagement counter — the D284 daily
     * aggregate row (M080/S03/T04).
     */
    private function discordCounterCount(string $category, string $event): int
    {
        return (int) NotificationEngagementCounter::query()
            ->where('date', now()->toDateString())
            ->where('category', $category)
            ->where('channel', 'discord')
            ->where('event', $event)
            ->value('count');
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
     * Notification whose DM payload is explicit — used for the posting paths
     * (the channel no longer synthesizes payloads from toDatabase(), D247).
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

            public function toDiscord(object $notifiable): DiscordWebhookPayload
            {
                $lines = [];
                if (($this->data['entity_name'] ?? null) !== null) {
                    $lines[] = (string) $this->data['entity_name'];
                }
                $lines[] = (string) ($this->data['action_url'] ?? 'https://roundup.test');

                return new DiscordWebhookPayload(content: implode("\n", $lines));
            }
        };
    }

    /**
     * Notification with toDatabase() but NO toDiscord() — the D247 gap shape.
     */
    private function payloadlessNotification(array $data = []): Notification
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
     * Carries toDatabase() like every real BaseNotification subclass — without
     * it the category fallback is the anonymous reflection name, which embeds
     * a NUL byte Postgres text columns cannot store.
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

            public function toDatabase(object $notifiable): array
            {
                return ['type' => 'waitlist_promoted'];
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
                    && ($context['app_id_short'] ?? null) === '5678'
                    && ($context['dm_channel_id'] ?? null) === self::DM_CHANNEL;
            })
            ->once();

        // D284: the successful delivery is counted under the notification's
        // toDatabase() category on the discord channel — and nothing else.
        $this->assertSame(1, $this->discordCounterCount('waitlist_promoted', 'dm_sent'));
        $this->assertSame(0, $this->discordCounterCount('waitlist_promoted', 'dm_failed'));
        $this->assertSame(1, NotificationEngagementCounter::query()->count());
    }

    #[Test]
    public function sent_log_carries_a_null_app_id_short_marker_when_no_application_id_is_configured(): void
    {
        config()->set('services.discord.bot_application_id', null);

        Http::fake([
            'discord.test/*' => Http::response(['id' => self::MESSAGE_ID], 200),
        ]);

        Log::spy();

        $user = $this->linkedUser();

        $this->channel()->send($user, $this->databaseNotification());

        // Unconfigured application id: the marker stays present in the context
        // (uniform shape) but is null, so an unattributed line is visible
        // rather than silently missing the key.
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_sent'
                && array_key_exists('app_id_short', $c)
                && $c['app_id_short'] === null)
            ->once();
    }

    // ── Graceful no-op paths ────────────────────────────

    #[Test]
    public function send_skips_with_no_linked_account_reason_when_user_has_no_discord_account(): void
    {

        Log::spy();

        $user = User::factory()->create(); // no linked account

        $this->channel()->send($user, $this->databaseNotification());

        Http::assertNothingSent();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'no_linked_account'
                && ($c['app_id_short'] ?? null) === '5678')
            ->once();

        $this->assertSame(1, $this->discordCounterCount('waitlist_promoted', 'dm_skipped:no_linked_account'));
    }

    #[Test]
    public function send_skips_when_publishing_is_disabled(): void
    {
        config()->set('services.discord.publishing_enabled', false);

        Log::spy();

        $user = $this->linkedUser();

        $this->channel()->send($user, $this->databaseNotification());

        Http::assertNothingSent();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'publishing_disabled')
            ->once();

        $this->assertSame(1, $this->discordCounterCount('waitlist_promoted', 'dm_skipped:publishing_disabled'));
    }

    #[Test]
    public function send_skips_when_bot_token_is_missing(): void
    {
        config()->set('services.discord.bot_token', '');

        Log::spy();

        $user = $this->linkedUser();

        $this->channel()->send($user, $this->databaseNotification());

        Http::assertNothingSent();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'bot_token_missing')
            ->once();

        $this->assertSame(1, $this->discordCounterCount('waitlist_promoted', 'dm_skipped:bot_token_missing'));
    }

    #[Test]
    public function send_skips_with_bot_token_missing_reason_when_client_is_null(): void
    {

        Log::spy();

        $user = $this->linkedUser();

        // Null client = Discord unconfigured (mirrors PushChannel's null guard).
        (new DiscordChannel(new NotificationEngagementCounters, null))->send($user, $this->databaseNotification());

        Http::assertNothingSent();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'bot_token_missing')
            ->once();

        // Same counter event as the empty-token path (one reason vocabulary).
        $this->assertSame(1, $this->discordCounterCount('waitlist_promoted', 'dm_skipped:bot_token_missing'));
    }

    #[Test]
    public function send_skips_with_dm_opt_out_reason_when_to_discord_returns_null(): void
    {

        Log::spy();

        $user = $this->linkedUser();

        $this->channel()->send($user, $this->discordOverrideNotification(null));

        Http::assertNothingSent();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'dm_opt_out')
            ->once();

        $this->assertSame(1, $this->discordCounterCount('waitlist_promoted', 'dm_skipped:dm_opt_out'));
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
    public function send_skips_with_warning_when_to_discord_is_missing_the_d247_gap(): void
    {
        Log::spy();

        $user = $this->linkedUser();

        // A toDatabase() shape alone is no longer enough (D247): the DM is
        // never synthesized — a missing toDiscord() is a gap that skips with
        // a warning, not a silent fallback to entity_name + action_url (or a
        // bare URL, the 2026-10-24 badge DM incident).
        $this->channel()->send($user, $this->payloadlessNotification());

        Http::assertNothingSent();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_missing_payload'
                && ($c['notification_type'] ?? null) !== null)
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'notification.discord_dm_skipped'
                && ($c['reason'] ?? null) === 'no_discord_payload')
            ->once();

        $this->assertSame(1, $this->discordCounterCount('waitlist_promoted', 'dm_skipped:no_discord_payload'));
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

        // D284: an API failure deliberately lands in TWO rows — dm_failed
        // ("did the Discord API fail?") and dm_skipped:dm_channel_failed
        // ("what was the send outcome?").
        $this->assertSame(1, $this->discordCounterCount('waitlist_promoted', 'dm_failed'));
        $this->assertSame(1, $this->discordCounterCount('waitlist_promoted', 'dm_skipped:dm_channel_failed'));
    }

    /**
     * Regression: DiscordChannel must receive a non-null DiscordWebhookClient
     * when resolved from the container. The constructor param is nullable with
     * a null default; without an explicit binding for DiscordWebhookClient,
     * Laravel's resolveClass() skips auto-resolution and returns the default,
     * silently killing every Discord DM delivery with a misleading
     * 'bot_token_missing' skip.
     */
    #[Test]
    public function container_resolves_discord_channel_with_a_non_null_client(): void
    {
        config()->set('services.discord.bot_token', 'test-bot-token');

        $channel = app(DiscordChannel::class);

        $this->assertNotNull(
            (new \ReflectionClass($channel))->getProperty('client')->getValue($channel),
            'DiscordChannel resolved from the container must have a non-null DiscordWebhookClient. '
            .'Without an explicit binding, Laravel skips auto-resolution for nullable params with defaults.'
        );
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

        // D284: the post-message failure — dm_failed plus the failure-induced
        // skip outcome (two-row semantics per the class docblock).
        $this->assertSame(1, $this->discordCounterCount('waitlist_promoted', 'dm_failed'));
        $this->assertSame(1, $this->discordCounterCount('waitlist_promoted', 'dm_skipped:send_failed'));
    }

    #[Test]
    public function a_counter_write_failure_never_breaks_the_dm_delivery(): void
    {
        $calls = [];

        Http::fake(function (Request $request) use (&$calls) {
            $calls[] = $request->url();

            if (str_contains($request->url(), 'users/@me/channels')) {
                return Http::response(['id' => self::DM_CHANNEL], 200);
            }

            return Http::response(['id' => self::MESSAGE_ID], 200);
        });

        Log::spy();

        $counters = \Mockery::mock(NotificationEngagementCounters::class);
        $counters->shouldReceive('incrementFor')->andThrow(new \RuntimeException('counters unavailable'));

        $user = $this->linkedUser();

        // Telemetry is best-effort (D284): the DM delivery is load-bearing, so
        // a counter failure is logged and swallowed — the DM is still posted.
        (new DiscordChannel($counters, $this->makeClient()))
            ->send($user, $this->databaseNotification());

        $this->assertCount(2, $calls, 'both Discord REST calls must still happen');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c) => $m === 'notifications.engagement_counter_failed'
                && ($c['channel'] ?? null) === 'discord'
                && ($c['event'] ?? null) === 'dm_sent'
                && str_contains((string) ($c['error'] ?? ''), 'counters unavailable'))
            ->once();
    }

    #[Test]
    public function every_base_notification_defines_an_explicit_to_discord_the_d247_contract(): void
    {
        $violations = [];

        foreach (glob(app_path('Notifications').'/*.php') as $file) {
            $class = 'App\\Notifications\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, BaseNotification::class)) {
                continue;
            }

            if (! method_exists($class, 'toDiscord')) {
                $violations[] = $class;
            }
        }

        $this->assertSame([], $violations, sprintf(
            'D247: every BaseNotification subclass must define toDiscord() (a payload, or a null opt-out) - missing on: %s. '
            .'Without it the Discord DM silently skips with a warning - make a conscious choice instead.',
            implode(', ', $violations),
        ));
    }
}
