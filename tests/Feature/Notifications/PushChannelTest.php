<?php

use App\Dto\PushPayload;
use App\Models\NotificationEngagementCounter;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Services\NotificationEngagementCounters;
use App\Services\Notifications\PushDeliveryProfile;
use Carbon\Carbon;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
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

    // Helper: a REAL on-curve P-256 public key (uncompressed point,
    // base64url) — what a genuine browser subscription stores. The poison
    // classification probe runs real Web Push crypto against the stored
    // material, so tests distinguishing "key-material poison" from
    // "transient/config failure" must use valid keys for the latter.
    function validP256dhKey(): string
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $details = openssl_pkey_get_details($key);

        return rtrim(strtr(base64_encode("\x04".$details['ec']['x'].$details['ec']['y']), '+/', '-_'), '=');
    }

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

    // ── M081 review: a poison key aborting the batch flush must not starve
    // the user's devices — WebPush::flush() prepares (encrypts) the whole
    // batch up front, so one undecodable subscription key throws before ANY
    // report is produced. The channel retries per subscription and prunes
    // the culprit (it can never be delivered to by any future send either).

    it('prunes a poison subscription when the batch flush aborts before any report', function () {
        $user = User::factory()->create();
        $poison = PushSubscription::factory()->create([
            'user_id' => $user,
            'endpoint' => 'https://push.example.com/poison',
        ]);

        Log::shouldReceive('warning')->zeroOrMoreTimes();

        // Batch flush aborts (the prepare()/encryption path throws the
        // poison-key class), then the isolated retry for the same single
        // subscription throws it again.
        $this->webPush->shouldReceive('queueNotification')->twice();
        $this->webPush->shouldReceive('flush')->once()->andThrow(new InvalidArgumentException('Invalid data: only uncompressed keys are supported'));
        $this->webPush->shouldReceive('flush')->once()->andThrow(new InvalidArgumentException('Invalid data: only uncompressed keys are supported'));

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url'), 'waitlist_promoted'));

        expect(PushSubscription::find($poison->id))->toBeNull('the poison subscription must be pruned')
            ->and(pushCounterCount('waitlist_promoted', 'subscription_expired'))->toBe(1);
    });

    it('delivers to the healthy devices when one poison key aborts the batch', function () {
        $user = User::factory()->create();
        PushSubscription::factory()->count(2)->create(['user_id' => $user]);

        $success = Mockery::mock(MessageSentReport::class);
        $success->shouldReceive('isSuccess')->andReturn(true);
        $success->shouldReceive('getEndpoint')->andReturn('https://push.example.com/ok');

        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        // Batch aborts; then the two isolated retries: one throws the
        // poison-key class (pruned), one delivers (report consumed). Order
        // between the two devices is irrelevant to the assertion.
        $this->webPush->shouldReceive('queueNotification')->times(4);
        $this->webPush->shouldReceive('flush')->once()->andThrow(new InvalidArgumentException('Invalid data: only uncompressed keys are supported'));
        $this->webPush->shouldReceive('flush')->once()->andThrow(new InvalidArgumentException('Invalid data: only uncompressed keys are supported'));
        $this->webPush->shouldReceive('flush')->once()->andReturn((function () use ($success) {
            yield $success;
        })());

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url'), 'waitlist_promoted'));

        expect(PushSubscription::where('user_id', $user->id)->count())->toBe(1, 'exactly the poison device is pruned')
            ->and(pushCounterCount('waitlist_promoted', 'sent'))->toBe(1)
            ->and(pushCounterCount('waitlist_promoted', 'subscription_expired'))->toBe(1);
    });

    it('keeps subscriptions when the isolated failure is not a key-material failure', function () {
        $user = User::factory()->create();
        // REAL on-curve key: the classification probe must pass it, so the
        // RuntimeException is classified transient/config (VAPID) — pruning
        // on it would delete the user's entire fleet on one bad deploy.
        $sub = PushSubscription::factory()->create([
            'user_id' => $user,
            'p256h_key' => validP256dhKey(),
        ]);

        Log::shouldReceive('warning')->zeroOrMoreTimes();

        // A config-level failure (e.g. VAPID) throws for EVERY subscription.
        $this->webPush->shouldReceive('queueNotification')->twice();
        $this->webPush->shouldReceive('flush')->once()->andThrow(new RuntimeException('VAPID key missing'));
        $this->webPush->shouldReceive('flush')->once()->andThrow(new RuntimeException('VAPID key missing'));

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url'), 'waitlist_promoted'));

        expect(PushSubscription::find($sub->id))->not->toBeNull('non-key failures must not prune')
            ->and(pushCounterCount('waitlist_promoted', 'subscription_expired'))->toBe(0)
            // Counter parity with the batch path: an isolated non-poison
            // failure still counts as a failed device send (D284) — it
            // previously only logged, undercounting send_failed.
            ->and(pushCounterCount('waitlist_promoted', 'send_failed'))->toBe(1);
    });

    it('prunes a poison subscription when the isolated failure is the TypeError key class', function () {
        // Minishlink v11 throws TypeError for wrong-length keys (hex2bin
        // returns false → strict-types Base64Url::encode) — previously the
        // generic branch logged + KEPT the row, an immortal poison device
        // that aborted every future batch (M081 fifth-pass review). The
        // factory's random key is undecodable by construction, so the
        // classification probe confirms permanent poison.
        $user = User::factory()->create();
        $poison = PushSubscription::factory()->create(['user_id' => $user]);

        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->webPush->shouldReceive('queueNotification')->twice();
        $this->webPush->shouldReceive('flush')->once()->andThrow(new TypeError('Base64Url::encode(): Argument #1 ($data) must be of type string'));
        $this->webPush->shouldReceive('flush')->once()->andThrow(new TypeError('Base64Url::encode(): Argument #1 ($data) must be of type string'));

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url'), 'waitlist_promoted'));

        expect(PushSubscription::find($poison->id))->toBeNull('a TypeError-class poison row must be pruned')
            ->and(pushCounterCount('waitlist_promoted', 'subscription_expired'))->toBe(1)
            ->and(pushCounterCount('waitlist_promoted', 'send_failed'))->toBe(0);
    });

    it('prunes a poison subscription when the key is a well-shaped off-curve point', function () {
        // 0x04 prefix + 64 bytes passes the intake length/prefix validation
        // but the coordinates are not on the P-256 curve — Web Push's key
        // agreement fails with RuntimeException ("Unable to compute the
        // agreement key"), another class the old InvalidArgumentException-
        // only prune missed.
        $user = User::factory()->create();
        $poison = PushSubscription::factory()->create([
            'user_id' => $user,
            'p256h_key' => rtrim(strtr(base64_encode("\x04".random_bytes(64)), '+/', '-_'), '='),
        ]);

        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->webPush->shouldReceive('queueNotification')->twice();
        $this->webPush->shouldReceive('flush')->once()->andThrow(new RuntimeException('Unable to compute the agreement key'));
        $this->webPush->shouldReceive('flush')->once()->andThrow(new RuntimeException('Unable to compute the agreement key'));

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url'), 'waitlist_promoted'));

        expect(PushSubscription::find($poison->id))->toBeNull('an off-curve poison row must be pruned')
            ->and(pushCounterCount('waitlist_promoted', 'subscription_expired'))->toBe(1);
    });

    it('isolates a report-processing failure instead of re-sending to every device', function () {
        // A transient failure INSIDE handleReport (here: the success log
        // write) must not escape into the batch catch — that path re-queues
        // the payload for ALL devices: duplicate pushes for the already-
        // reported device and double-counted sent (M081 fifth-pass review).
        $user = User::factory()->create();
        PushSubscription::factory()->count(2)->create(['user_id' => $user]);

        $ok = Mockery::mock(MessageSentReport::class);
        $ok->shouldReceive('isSuccess')->andReturn(true);
        $ok->shouldReceive('getEndpoint')->andReturn('https://push.example.com/ok');

        // First push.sent log blows up (simulated log sink hiccup); later
        // calls succeed — the SECOND report must still be processed.
        $logCalls = 0;
        Log::shouldReceive('info')->andReturnUsing(function () use (&$logCalls) {
            if ($logCalls++ === 0) {
                throw new RuntimeException('log sink hiccup');
            }
        });
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        // ONE batch flush yields both reports. queueNotification is pinned
        // to EXACTLY the two batch queues — a re-send through the isolated
        // fallback would call it again and fail the test.
        $this->webPush->shouldReceive('queueNotification')->twice();
        $this->webPush->shouldReceive('flush')->once()->andReturn((function () use ($ok) {
            yield $ok;
            yield $ok;
        })());

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url'), 'waitlist_promoted'));

        // The FIRST report failed mid-processing (before its counter), so it
        // counts nothing — but the SECOND is still processed and counted.
        expect(pushCounterCount('waitlist_promoted', 'sent'))->toBe(1, 'the second report is still processed despite the first throwing')
            ->and(PushSubscription::where('user_id', $user->id)->count())->toBe(2, 'no cleanup side effects');
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

    // ── M081/S05/T02: per-category urgency/TTL ride as WebPush options ──

    it('passes high urgency and the short TTL for deadline-critical categories', function () {
        $user = User::factory()->create();
        PushSubscription::factory()->create(['user_id' => $user]);
        PushSubscription::factory()->create(['user_id' => $user]);

        $success = Mockery::mock(MessageSentReport::class);
        $success->shouldReceive('isSuccess')->andReturn(true);
        $success->shouldReceive('getEndpoint')->andReturn('https://push.example.com/ok');

        Log::shouldReceive('info')->zeroOrMoreTimes();

        // Every queued notification carries the deadline profile's options
        // (RFC 8030 Urgency + TTL headers) — one options array per device.
        $this->webPush->shouldReceive('queueNotification')
            ->twice()
            ->with(
                Mockery::type(SubscriptionInterface::class),
                Mockery::type('string'),
                ['urgency' => PushDeliveryProfile::URGENCY_HIGH, 'TTL' => PushDeliveryProfile::HIGH_TTL_SECONDS],
            );
        $this->webPush->shouldReceive('flush')
            ->once()
            ->andReturn((function () use ($success) {
                yield $success;
                yield $success;
            })());

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url', 'tag1'), 'waitlist_promoted'));

        // The options wiring must not disturb the M080 counter invariant:
        // exactly one `sent` increment per device report (2 devices).
        expect(pushCounterCount('waitlist_promoted', 'sent'))->toBe(2)
            ->and(pushCounterCount('waitlist_promoted', 'send_failed'))->toBe(0)
            ->and(pushCounterCount('waitlist_promoted', 'subscription_expired'))->toBe(0);
    });

    it('passes normal urgency and the 24h TTL for ambient categories', function () {
        $user = User::factory()->create();
        PushSubscription::factory()->create(['user_id' => $user]);

        $success = Mockery::mock(MessageSentReport::class);
        $success->shouldReceive('isSuccess')->andReturn(true);
        $success->shouldReceive('getEndpoint')->andReturn('https://push.example.com/ok');

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->webPush->shouldReceive('queueNotification')
            ->once()
            ->with(
                Mockery::type(SubscriptionInterface::class),
                Mockery::type('string'),
                ['urgency' => PushDeliveryProfile::URGENCY_NORMAL, 'TTL' => PushDeliveryProfile::NORMAL_TTL_SECONDS],
            );
        $this->webPush->shouldReceive('flush')
            ->once()
            ->andReturn((function () use ($success) {
                yield $success;
            })());

        $this->channel->send($user, pushNotification(new PushPayload('Test', 'Body', '/icon.png', '/url'), 'recap_posted'));

        expect(pushCounterCount('recap_posted', 'sent'))->toBe(1);
    });
});
