<?php

use App\Dto\PushPayload;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

describe('PushChannel', function () {
    beforeEach(function () {
        $this->webPush = Mockery::mock(WebPush::class);
        $this->channel = new PushChannel($this->webPush);
    });

    afterEach(function () {
        Mockery::close();
    });

    it('returns early when WebPush is null (VAPID keys missing)', function () {
        $channel = new PushChannel(null);

        $sub = Mockery::mock(PushSubscription::class)->makePartial();
        $sub->endpoint = 'https://push.example.com/1';
        $sub->p256h_key = 'key1';
        $sub->auth_token = 'auth1';

        $user = Mockery::mock(User::class);
        $user->shouldNotReceive('getAttribute');

        $payload = new PushPayload('Title', 'Body', '/icon.png', '/url');
        $notification = new class($payload) extends Notification
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

        // Should not throw or attempt to access subscriptions
        $channel->send($user, $notification);
        expect(true)->toBeTrue();
    });

    it('does nothing when notification has no toPush method', function () {
        $user = Mockery::mock(User::class);
        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return [];
            }
        };

        // Should not throw or call anything
        $this->channel->send($user, $notification);
        expect(true)->toBeTrue();
    });

    it('does nothing when toPush returns null (opted out)', function () {
        $user = Mockery::mock(User::class);
        $user->shouldNotReceive('getAttribute');

        $notification = new class extends Notification
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

        $this->channel->send($user, $notification);
        expect(true)->toBeTrue();
    });

    it('does nothing when user has no push subscriptions', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('getAttribute')->with('pushSubscriptions')->andReturn(collect([]));

        $payload = new PushPayload('Title', 'Body', '/icon.png', '/url');

        $notification = new class($payload) extends Notification
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

        $this->channel->send($user, $notification);
        expect(true)->toBeTrue();
    });

    it('queues notifications and flushes batch', function () {
        $sub1 = Mockery::mock(PushSubscription::class)->makePartial();
        $sub1->endpoint = 'https://push.example.com/1';
        $sub1->p256h_key = 'key1';
        $sub1->auth_token = 'auth1';
        $sub1->id = 1;
        $sub1->user_id = 10;

        $sub2 = Mockery::mock(PushSubscription::class)->makePartial();
        $sub2->endpoint = 'https://push.example.com/2';
        $sub2->p256h_key = 'key2';
        $sub2->auth_token = 'auth2';
        $sub2->id = 2;
        $sub2->user_id = 10;

        $user = Mockery::mock(User::class);
        $user->shouldReceive('getAttribute')->with('pushSubscriptions')
            ->andReturn(collect([$sub1, $sub2]));

        $payload = new PushPayload('Test', 'Body', '/icon.png', '/url', 'tag1');

        // Create a successful report mock
        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSuccess')->andReturn(true);

        // Expect queueNotification to be called twice (once per subscription)
        $this->webPush->shouldReceive('queueNotification')
            ->twice();

        // flush() returns a Generator yielding reports
        $this->webPush->shouldReceive('flush')
            ->once()
            ->andReturn((function () use ($report) {
                yield $report;
                yield $report;
            })());

        $notification = new class($payload) extends Notification
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

        $this->channel->send($user, $notification);
    });

    it('deletes expired subscriptions and logs on flush', function () {
        $sub = Mockery::mock(PushSubscription::class)->makePartial();
        $sub->endpoint = 'https://push.example.com/expired';
        $sub->p256h_key = 'key1';
        $sub->auth_token = 'auth1';
        $sub->id = 42;
        $sub->user_id = 10;

        $user = Mockery::mock(User::class);
        $user->shouldReceive('getAttribute')->with('pushSubscriptions')
            ->andReturn(collect([$sub]));

        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSuccess')->andReturn(false);
        $report->shouldReceive('isSubscriptionExpired')->andReturn(true);
        $report->shouldReceive('getEndpoint')->andReturn('https://push.example.com/expired');

        $this->webPush->shouldReceive('queueNotification')->once();
        $this->webPush->shouldReceive('flush')
            ->once()
            ->andReturn((function () use ($report) {
                yield $report;
            })());

        // Use a partial mock to intercept handleReport which does a static
        // DB query (PushSubscription::where) that can't run in unit tests.
        $channel = Mockery::mock(PushChannel::class, [$this->webPush])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $channel->shouldReceive('handleReport')
            ->once()
            ->with(Mockery::on(fn ($r) => $r === $report));

        $payload = new PushPayload('Test', 'Body', '/icon.png', '/url');
        $notification = new class($payload) extends Notification
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

        $channel->send($user, $notification);
    });

    it('logs warning on failed send that is not expired', function () {
        $sub = Mockery::mock(PushSubscription::class)->makePartial();
        $sub->endpoint = 'https://push.example.com/failed';
        $sub->p256h_key = 'key1';
        $sub->auth_token = 'auth1';
        $sub->id = 99;
        $sub->user_id = 10;

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getStatusCode')->andReturn(429);

        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSuccess')->andReturn(false);
        $report->shouldReceive('isSubscriptionExpired')->andReturn(false);
        $report->shouldReceive('getReason')->andReturn('Too Many Requests');
        $report->shouldReceive('getResponse')->andReturn($response);
        $report->shouldReceive('getEndpoint')->andReturn('https://push.example.com/failed');

        $this->webPush->shouldReceive('queueNotification')->once();
        $this->webPush->shouldReceive('flush')
            ->once()
            ->andReturn((function () use ($report) {
                yield $report;
            })());

        Log::shouldReceive('warning')
            ->with('push.send_failed', Mockery::on(function ($ctx) {
                return ($ctx['endpoint'] ?? null) === 'https://push.example.com/failed'
                    && ($ctx['reason'] ?? null) === 'Too Many Requests';
            }))
            ->once();

        $user = Mockery::mock(User::class);
        $user->shouldReceive('getAttribute')->with('pushSubscriptions')
            ->andReturn(collect([$sub]));

        $payload = new PushPayload('Test', 'Body', '/icon.png', '/url');
        $notification = new class($payload) extends Notification
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

        $this->channel->send($user, $notification);
    });

    it('logs warning and continues on queue exception', function () {
        $sub = Mockery::mock(PushSubscription::class)->makePartial();
        $sub->endpoint = 'https://push.example.com/broken';
        $sub->p256h_key = 'key1';
        $sub->auth_token = 'auth1';
        $sub->id = 50;
        $sub->user_id = 10;

        // queueNotification throws
        $this->webPush->shouldReceive('queueNotification')
            ->once()
            ->andThrow(new RuntimeException('Connection refused'));

        // flush still gets called (empty batch, returns empty generator)
        $this->webPush->shouldReceive('flush')
            ->once()
            ->andReturn((function () { yield from []; })());

        Log::shouldReceive('warning')
            ->with('push.queue_failed', Mockery::on(function ($ctx) {
                return ($ctx['subscription_id'] ?? null) === 50
                    && str_contains($ctx['error'] ?? '', 'Connection refused');
            }))
            ->once();

        $user = Mockery::mock(User::class);
        $user->shouldReceive('getAttribute')->with('pushSubscriptions')
            ->andReturn(collect([$sub]));

        $payload = new PushPayload('Test', 'Body', '/icon.png', '/url');
        $notification = new class($payload) extends Notification
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

        // Must NOT throw
        $this->channel->send($user, $notification);
    });
});

describe('PushPayload', function () {
    it('converts to array with all fields', function () {
        $payload = new PushPayload('Title', 'Body text', '/icon.png', 'https://example.com/game/1', 'game-1');

        $array = $payload->toArray();

        expect($array)->toBe([
            'title' => 'Title',
            'body' => 'Body text',
            'icon' => '/icon.png',
            'url' => 'https://example.com/game/1',
            'tag' => 'game-1',
        ]);
    });

    it('omits tag when null', function () {
        $payload = new PushPayload('Title', 'Body', '/icon.png', '/url');

        $array = $payload->toArray();

        expect($array)->not->toHaveKey('tag');
        expect($array)->toHaveCount(4);
    });
});

describe('Key mapping', function () {
    it('maps p256h_key to p256dh for Minishlink compatibility', function () {
        $source = file_get_contents(base_path('app/Models/PushSubscription.php'));

        // Verify the model's toWebPushSubscription() correctly maps our DB
        // column name (p256h_key) to Minishlink's expected key name (p256dh)
        expect($source)->toContain("'p256dh' =>");
        expect($source)->toContain('p256h_key');
        expect($source)->toContain('toWebPushSubscription');
    });

    it('PushChannel uses toWebPushSubscription from model', function () {
        $source = file_get_contents(base_path('app/Notifications/Channels/PushChannel.php'));

        expect($source)->toContain('toWebPushSubscription()');
        expect($source)->not->toContain("'p256dh' =>");  // mapping no longer in PushChannel
    });
});
