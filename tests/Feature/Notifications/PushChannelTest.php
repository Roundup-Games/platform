<?php

use App\Dto\PushPayload;
use App\Models\NotificationEngagementCounter;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Services\NotificationEngagementCounters;
use Carbon\Carbon;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Psr\Http\Message\ResponseInterface;

/*
|--------------------------------------------------------------------------
| PushChannel integration tests
|--------------------------------------------------------------------------
|
| PushChannel orchestrates Minishlink\WebPush (the external push boundary —
| real push servers cannot be reached from tests), so the WebPush client is
| mocked, exactly like Http::fake() for an HTTP API. Everything ELSE runs for
| real: a real User with real PushSubscription rows, the real send() loop, and
| the real handleReport() — whose job is to DELETE expired subscriptions.
|
| The previous version of this file lived in Unit/ and mocked User,
| PushSubscription, AND handleReport itself, ending every test in
| `expect(true)->toBeTrue()`. Mocking handleReport meant the test named
| "deletes expired subscriptions" never ran the deletion. These tests assert
| the real DB outcome instead.
*/

describe('PushChannel', function () {
    beforeEach(function () {
        $this->webPush = Mockery::mock(WebPush::class);
        $this->channel = new PushChannel(new NotificationEngagementCounters, $this->webPush);
        // Freeze the clock: counter rows bucket on the UTC calendar date, so
        // a test straddling UTC midnight would write and read different daily
        // rows and false-fail.
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));
    });

    afterEach(function () {
        Mockery::close();
    });

    // Helper: a real notification carrying a push payload, optionally with a
    // toDatabase() type (the counter's category source, M080/S03).
    function pushNotification(PushPayload $payload, ?string $type = null): Notification
    {
        return new class($payload, $type) extends Notification
        {
            public function __construct(
                private PushPayload $payload,
                private ?string $type,
            ) {}

            public function via(object $notifiable): array
            {
                return [];
            }

            public function toPush(object $notifiable): PushPayload
            {
                return $this->payload;
            }

            public function toDatabase(object $notifiable): array
            {
                return ['type' => $this->type];
            }
        };
    }

    /** Today's (category, push, event) engagement counter — the D284 aggregate row. */
    function pushCounterCount(string $category, string $event): int
    {
        return (int) NotificationEngagementCounter::query()
            ->where('date', now()->toDateString())
            ->where('category', $category)
            ->where('channel', 'push')
            ->where('event', $event)
            ->value('count');
    }

    // ── Graceful degradation: early returns must not throw ──

    it('returns early without queueing when WebPush is null', function () {
        $user = User::factory()->create();
        $payload = new PushPayload('Title', 'Body', '/icon.png', '/url');

        // No exception, and no WebPush instance to call anyway.
        (new PushChannel(new NotificationEngagementCounters, null))->send($user, pushNotification($payload));

        expect(PushSubscription::count())->toBe(0)
            // Early-return paths never reach a report — no counter either.
            ->and(NotificationEngagementCounter::count())->toBe(0);
    });

    it('returns early when the notification has no toPush method or opts out with null', function () {
        $user = User::factory()->create();
        PushSubscription::factory()->create(['user_id' => $user]);

        $noToPush = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [];
            }
        };

        $optsOut = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [];
            }

            public function toPush(object $notifiable): ?PushPayload
            {
                return null;
            }
        };

        // Neither case reaches WebPush.
        $this->webPush->shouldNotReceive('queueNotification');
        $this->webPush->shouldNotReceive('flush');

        $this->channel->send($user, $noToPush);
        $this->channel->send($user, $optsOut);
    });

    // ── Happy path: queues each subscription, flushes the batch ──

    it('queues one notification per subscription and flushes the batch', function () {
        $user = User::factory()->create();
        $sub1 = PushSubscription::factory()->create(['user_id' => $user]);
        $sub2 = PushSubscription::factory()->create(['user_id' => $user]);

        $success = Mockery::mock(MessageSentReport::class);
        $success->shouldReceive('isSuccess')->andReturn(true);
        // Enriched delivery logging reads the endpoint for per-device attribution.
        $success->shouldReceive('getEndpoint')->andReturn('https://push.example.com/ok');

        // Silence the per-device push.sent success log (DB count is the assertion).
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->webPush->shouldReceive('queueNotification')->twice();
        $this->webPush->shouldReceive('flush')
            ->once()
            ->andReturn((function () use ($success) {
                yield $success;
                yield $success;
            })());

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url', 'tag1'), 'waitlist_promoted'));

        // Successful delivery never deletes subscriptions.
        expect(PushSubscription::where('user_id', $user->id)->count())->toBe(2)
            // D284: one `sent` counter increment PER DEVICE report (2 devices),
            // attributed to the notification's toDatabase() category.
            ->and(pushCounterCount('waitlist_promoted', 'sent'))->toBe(2)
            ->and(pushCounterCount('waitlist_promoted', 'send_failed'))->toBe(0)
            ->and(pushCounterCount('waitlist_promoted', 'subscription_expired'))->toBe(0);
    });

    // ── The flagship behaviour: expired subscriptions are deleted ──

    it('deletes expired subscriptions and leaves healthy ones intact', function () {
        $user = User::factory()->create();
        $expired = PushSubscription::factory()->create([
            'user_id' => $user,
            'endpoint' => 'https://push.example.com/expired',
        ]);
        $healthy = PushSubscription::factory()->create([
            'user_id' => $user,
            'endpoint' => 'https://push.example.com/healthy',
        ]);

        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSuccess')->andReturn(false);
        $report->shouldReceive('isSubscriptionExpired')->andReturn(true);
        $report->shouldReceive('getEndpoint')->andReturn('https://push.example.com/expired');

        // Silence the structured deletion log; the DB state is the assertion.
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->webPush->shouldReceive('queueNotification')->twice();
        $this->webPush->shouldReceive('flush')
            ->once()
            ->andReturn((function () use ($report) {
                yield $report;
            })());

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url'), 'waitlist_promoted'));

        expect(PushSubscription::find($expired->id))->toBeNull('expired subscription should be deleted')
            ->and(PushSubscription::find($healthy->id))->not->toBeNull('healthy subscription must remain')
            // D284: the expiry outcome is counted under the same category.
            ->and(pushCounterCount('waitlist_promoted', 'subscription_expired'))->toBe(1);
    });

    // ── Transient failures are logged but do not delete the subscription ──

    it('logs a warning on a non-expired failure and keeps the subscription', function () {
        $user = User::factory()->create();
        $sub = PushSubscription::factory()->create([
            'user_id' => $user,
            'endpoint' => 'https://push.example.com/failed',
        ]);

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getStatusCode')->andReturn(429);

        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSuccess')->andReturn(false);
        $report->shouldReceive('isSubscriptionExpired')->andReturn(false);
        $report->shouldReceive('getEndpoint')->andReturn('https://push.example.com/failed');
        $report->shouldReceive('getReason')->andReturn('Too Many Requests');
        $report->shouldReceive('getResponse')->andReturn($response);

        Log::shouldReceive('warning')
            ->with('push.send_failed', Mockery::on(fn ($ctx) => $ctx['endpoint'] === 'https://push.example.com/failed'
                && $ctx['reason'] === 'Too Many Requests'))
            ->once();

        $this->webPush->shouldReceive('queueNotification')->once();
        $this->webPush->shouldReceive('flush')
            ->once()
            ->andReturn((function () use ($report) {
                yield $report;
            })());

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url'), 'waitlist_promoted'));

        // A transient failure must NOT remove the subscription.
        expect(PushSubscription::find($sub->id))->not->toBeNull()
            // D284: the failure outcome is counted under the same category.
            ->and(pushCounterCount('waitlist_promoted', 'send_failed'))->toBe(1)
            ->and(pushCounterCount('waitlist_promoted', 'sent'))->toBe(0);
    });

    // ── Resilience: a queueing exception is logged, never propagated ──

    it('logs a warning and continues when queueNotification throws', function () {
        $user = User::factory()->create();
        $sub = PushSubscription::factory()->create([
            'user_id' => $user,
            'endpoint' => 'https://push.example.com/broken',
        ]);

        $this->webPush->shouldReceive('queueNotification')
            ->andThrow(new RuntimeException('Connection refused'));
        $this->webPush->shouldReceive('flush')
            ->andReturn((function () {
                yield from [];
            })());

        Log::shouldReceive('warning')
            ->with('push.queue_failed', Mockery::on(fn ($ctx) => $ctx['subscription_id'] === $sub->id
                && str_contains($ctx['error'], 'Connection refused')))
            ->once();

        // Must not throw — the channel swallows per-subscription errors.
        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url')));

        expect(PushSubscription::find($sub->id))->not->toBeNull()
            // queue_failed never reaches a device report — no counter either.
            ->and(NotificationEngagementCounter::count())->toBe(0);
    });

    // ── Counter attribution: the fallback category (M080/S03) ──

    it('falls back to the class basename category when toDatabase has no type', function () {
        $user = User::factory()->create();
        PushSubscription::factory()->create(['user_id' => $user]);

        $success = Mockery::mock(MessageSentReport::class);
        $success->shouldReceive('isSuccess')->andReturn(true);
        $success->shouldReceive('getEndpoint')->andReturn('https://push.example.com/ok');

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->webPush->shouldReceive('queueNotification')->once();
        $this->webPush->shouldReceive('flush')
            ->once()
            ->andReturn((function () use ($success) {
                yield $success;
            })());

        // No type passed → toDatabase() returns a null type → the counter
        // attributes via the class-basename fallback (mirrors
        // DiscordChannel::resolveCategory): PHP derives an anonymous class's
        // short name from its parent, so this test notification lands under
        // "Notification@anonymous".
        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url')));

        $row = NotificationEngagementCounter::query()
            ->where('channel', 'push')
            ->where('event', 'sent')
            ->first();

        expect($row)->not->toBeNull()
            ->and($row->category)->toStartWith('Notification@anonymous');
    });

    // ── Resilience: a counter failure never breaks delivery ──

    it('logs and continues when the engagement counter write fails', function () {
        $user = User::factory()->create();
        $expired = PushSubscription::factory()->create([
            'user_id' => $user,
            'endpoint' => 'https://push.example.com/expired',
        ]);

        $counters = Mockery::mock(NotificationEngagementCounters::class);
        $counters->shouldReceive('incrementFor')->andThrow(new RuntimeException('counters unavailable'));
        $channel = new PushChannel($counters, $this->webPush);

        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSuccess')->andReturn(false);
        $report->shouldReceive('isSubscriptionExpired')->andReturn(true);
        $report->shouldReceive('getEndpoint')->andReturn('https://push.example.com/expired');

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->with('notifications.engagement_counter_failed', Mockery::on(fn ($ctx) => $ctx['channel'] === 'push'
                && $ctx['event'] === 'subscription_expired'
                && str_contains($ctx['error'], 'counters unavailable')))
            ->once();

        $this->webPush->shouldReceive('queueNotification')->once();
        $this->webPush->shouldReceive('flush')
            ->once()
            ->andReturn((function () use ($report) {
                yield $report;
            })());

        // Telemetry is best-effort (D284): the expired-subscription cleanup
        // (the load-bearing behavior) still runs despite the counter throw.
        $channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url'), 'waitlist_promoted'));

        expect(PushSubscription::find($expired->id))->toBeNull();
    });
});
