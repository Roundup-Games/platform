<?php

use App\Dto\PushPayload;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
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
        $this->channel = new PushChannel($this->webPush);
    });

    afterEach(function () {
        Mockery::close();
    });

    // Helper: a real notification carrying a push payload.
    function pushNotification(PushPayload $payload): Notification
    {
        return new class($payload) extends Notification
        {
            public function __construct(private PushPayload $payload) {}

            public function via(object $notifiable): array
            {
                return [];
            }

            public function toPush(object $notifiable): PushPayload
            {
                return $this->payload;
            }
        };
    }

    // ── Graceful degradation: early returns must not throw ──

    it('returns early without queueing when WebPush is null', function () {
        $user = User::factory()->create();
        $payload = new PushPayload('Title', 'Body', '/icon.png', '/url');

        // No exception, and no WebPush instance to call anyway.
        (new PushChannel(null))->send($user, pushNotification($payload));

        expect(PushSubscription::count())->toBe(0);
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

        $this->webPush->shouldReceive('queueNotification')->twice();
        $this->webPush->shouldReceive('flush')
            ->once()
            ->andReturn((function () use ($success) {
                yield $success;
                yield $success;
            })());

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url', 'tag1')));

        // Successful delivery never deletes subscriptions.
        expect(PushSubscription::where('user_id', $user->id)->count())->toBe(2);
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

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url')));

        expect(PushSubscription::find($expired->id))->toBeNull('expired subscription should be deleted')
            ->and(PushSubscription::find($healthy->id))->not->toBeNull('healthy subscription must remain');
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

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url')));

        // A transient failure must NOT remove the subscription.
        expect(PushSubscription::find($sub->id))->not->toBeNull();
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

        expect(PushSubscription::find($sub->id))->not->toBeNull();
    });
});
