<?php

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
    $this->user = User::factory()->create();
});

describe('POST /api/push/subscribe', function () {
    it('creates a new push subscription', function () {
        $payload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-123',
            'keys' => [
                // RFC 8291 key fixtures (base64url, unpadded): a real
                // uncompressed P-256 point is 65 bytes starting with the
                // 0x04 prefix; the auth secret is 16 bytes. The endpoint
                // validates structure — one malformed key in the table
                // aborts every batch flush for that user (M081 review).
                'p256h' => 'BBERERERERERERERERERERERERERERERERERERERERERIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiI',
                'auth' => 'q6urq6urq6urq6urq6urqw',
            ],
        ];

        $response = $this->actingAs($this->user)->postJson('/api/v1/push/subscribe', $payload);

        $response->assertCreated()
            ->assertJsonStructure(['id']);

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $this->user->id,
            'endpoint' => $payload['endpoint'],
        ]);
    });

    it('updates an existing subscription with same endpoint', function () {
        $existing = PushSubscription::factory()->create([
            'user_id' => $this->user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/same-endpoint',
        ]);

        $payload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/same-endpoint',
            'keys' => [
                'p256h' => 'BBERERERERERERERERERERERERERERERERERERERERERIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiI',
                'auth' => 'q6urq6urq6urq6urq6urqw',
            ],
        ];

        $response = $this->actingAs($this->user)->postJson('/api/v1/push/subscribe', $payload);

        $response->assertOk()
            ->assertJson(['id' => $existing->id]);

        // Should not create a duplicate
        $this->assertEquals(1, PushSubscription::where('endpoint', $payload['endpoint'])->count());
    });

    it('requires authentication', function () {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://example.com',
            'keys' => ['p256h' => 'a', 'auth' => 'b'],
        ])->assertUnauthorized();
    });

    it('validates required fields', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/push/subscribe', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint', 'keys.p256h', 'keys.auth']);
    });

    it('validates endpoint is a URL', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => 'not-a-url',
                'keys' => ['p256h' => 'a', 'auth' => 'b'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint']);
    });

    // ── M081 review: structurally invalid key material is rejected at intake.
    // One undecodable p256dh key in the subscriptions table aborts every
    // batch flush for that user (WebPush encrypts the whole batch up front),
    // so garbage keys must never be stored.

    it('rejects a p256dh key that is not a 65-byte uncompressed P-256 point', function (string $key) {
        $this->actingAs($this->user)
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/bad-keys',
                'keys' => ['p256h' => $key, 'auth' => 'q6urq6urq6urq6urq6urqw'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.p256h']);
    })->with([
        'not base64url at all' => 'not+a+base64url$key!!',
        'decodes but wrong length (64 bytes)' => 'BBERERERERERERERERERERERERERERERERERERERERERIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIi',
        'missing the 0x04 uncompressed prefix' => 'AgERERERERERERERERERERERERERERERERERERERERERIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiI',
    ]);

    it('rejects an auth secret that is not base64url', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/bad-auth',
                'keys' => [
                    'p256h' => 'BBERERERERERERERERERERERERERERERERERERERERERIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiI',
                    'auth' => 'not+base64url!!',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.auth']);
    });

    it('rate limits after 10 attempts', function () {
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->user)->postJson('/api/v1/push/subscribe', [
                'endpoint' => "https://fcm.googleapis.com/fcm/send/ep-{$i}",
                'keys' => ['p256h' => 'BBERERERERERERERERERERERERERERERERERERERERERIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiI', 'auth' => 'q6urq6urq6urq6urq6urqw'],
            ]);
        }

        $response = $this->actingAs($this->user)->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/ep-11',
            'keys' => ['p256h' => 'BBERERERERERERERERERERERERERERERERERERERERERIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiI', 'auth' => 'q6urq6urq6urq6urq6urqw'],
        ]);

        $response->assertStatus(429);
    });

    it('captures user agent', function () {
        $this->actingAs($this->user)
            ->withHeaders(['User-Agent' => 'TestBrowser/1.0'])
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/ua-test',
                'keys' => ['p256h' => 'BBERERERERERERERERERERERERERERERERERERERERERIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiI', 'auth' => 'q6urq6urq6urq6urq6urqw'],
            ]);

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $this->user->id,
            'user_agent' => 'TestBrowser/1.0',
        ]);
    });

    it('allows multiple users on the same device (shared endpoint)', function () {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $endpoint = 'https://push.example.com/subscribe/abc123';

        // User A subscribes
        $this->actingAs($userA)
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => $endpoint,
                'keys' => ['p256h' => 'BBERERERERERERERERERERERERERERERERERERERERERIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiI', 'auth' => 'q6urq6urq6urq6urq6urqw'],
            ])
            ->assertCreated();

        // User B subscribes with the same endpoint (same device)
        $this->actingAs($userB)
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => $endpoint,
                'keys' => ['p256h' => 'BBERERERERERERERERERERERERERERERERERERERERERIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiI', 'auth' => 'q6urq6urq6urq6urq6urqw'],
            ])
            ->assertCreated();

        // Both subscriptions exist independently
        expect(PushSubscription::where('endpoint', $endpoint)->count())->toBe(2);
        expect(PushSubscription::where('user_id', $userA->id)->where('endpoint', $endpoint)->exists())->toBeTrue();
        expect(PushSubscription::where('user_id', $userB->id)->where('endpoint', $endpoint)->exists())->toBeTrue();
    });
});

describe('DELETE /api/push/subscribe', function () {
    it('deletes an existing subscription', function () {
        $subscription = PushSubscription::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/push/subscribe', [
                'endpoint' => $subscription->endpoint,
            ]);

        $response->assertNoContent();

        $this->assertDatabaseMissing('push_subscriptions', [
            'id' => $subscription->id,
        ]);
    });

    it('returns 404 for non-existent subscription', function () {
        $this->actingAs($this->user)
            ->deleteJson('/api/v1/push/subscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/nonexistent',
            ])
            ->assertNotFound();
    });

    it('does not delete another users subscription', function () {
        $otherUser = User::factory()->create();
        $subscription = PushSubscription::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $this->actingAs($this->user)
            ->deleteJson('/api/v1/push/subscribe', [
                'endpoint' => $subscription->endpoint,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('push_subscriptions', [
            'id' => $subscription->id,
        ]);
    });

    it('requires authentication', function () {
        $this->deleteJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://example.com',
        ])->assertUnauthorized();
    });

    it('validates endpoint is required', function () {
        $this->actingAs($this->user)
            ->deleteJson('/api/v1/push/subscribe', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint']);
    });
});

describe('GET /api/push/vapid-public-key', function () {
    it('returns the VAPID public key', function () {
        Config::set('services.vapid.public_key', 'test-public-key-value');

        $this->getJson('/api/v1/push/vapid-public-key')
            ->assertOk()
            ->assertJson(['public_key' => 'test-public-key-value']);
    });

    it('does not require authentication', function () {
        Config::set('services.vapid.public_key', 'test-key');

        $this->getJson('/api/v1/push/vapid-public-key')
            ->assertOk();
    });

    it('returns 503 when VAPID is not configured', function () {
        Config::set('services.vapid.public_key', null);

        $this->getJson('/api/v1/push/vapid-public-key')
            ->assertStatus(503);
    });
});

describe('first-party session-cookie auth (stateful SPA)', function () {
    it('applies Sanctum stateful middleware to the push subscribe/unsubscribe routes before auth:sanctum', function () {
        foreach (['api.push.subscribe', 'api.push.unsubscribe'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            expect($route)->not->toBeNull("Route [{$name}] must exist.");
            $middleware = $route->gatherMiddleware();
            expect($middleware)->toContain(EnsureFrontendRequestsAreStateful::class);

            // ORDER is the load-bearing half: middleware runs in list order,
            // and the stateful pipeline must boot the session BEFORE
            // auth:sanctum resolves the guard — appended, it would find no
            // session on the API route and the browser path stays broken.
            expect(array_search(EnsureFrontendRequestsAreStateful::class, $middleware, true))
                ->toBeLessThan(array_search('auth:sanctum', $middleware, true));
        }
    });

    it('authenticates a real browser session cookie on the push subscribe route', function () {
        // The §10.5 regression, pinned behaviorally: browser push subscribe
        // posts session cookies (no bearer), so it authenticates ONLY when
        // EnsureFrontendRequestsAreStateful boots the session pipeline on the
        // API route. actingAs-based tests cannot catch its absence — they
        // seed the web guard directly and the session middleware never runs —
        // so this test establishes a REAL web session via the login
        // controller, harvests its encrypted cookie, drops all guard state,
        // and re-issues the API request carrying only the cookie + a
        // first-party Origin header. Without the stateful middleware the
        // API request never decrypts the session and must 401.
        $password = 'push-cookie-secret-'.Str::random(8);
        $user = User::factory()->create(['password' => Hash::make($password)]);

        $login = $this->post('/en/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

        $cookieName = config('session.cookie');
        $sessionCookie = $login->getCookie($cookieName, decrypt: false);
        expect($sessionCookie)->not->toBeNull('the web login must set the session cookie.');

        // Drop any guard state the login request seeded: the API request
        // must authenticate from the cookie alone, exactly like a fetch from
        // push.js (credentials: 'same-origin', no Authorization header).
        auth()->forgetGuards();

        $this->withUnencryptedCookies([$cookieName => $sessionCookie->getValue()])
            ->withHeaders(['Origin' => rtrim(config('app.url'), '/')])
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/session-cookie-probe',
                'keys' => ['p256h' => 'BBERERERERERERERERERERERERERERERERERERERERERIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiI', 'auth' => 'q6urq6urq6urq6urq6urqw'],
            ])
            ->assertCreated();

        expect(PushSubscription::where('user_id', $user->id)
            ->where('endpoint', 'https://fcm.googleapis.com/fcm/send/session-cookie-probe')
            ->exists())->toBeTrue();
    });

    it('rejects cookie-less subscribe attempts with 401 even from a stateful origin', function () {
        // The real-browser path this pins: a cookie-bearing fetch from the app
        // origin is authenticated via EnsureFrontendRequestsAreStateful +
        // auth:sanctum's session fallback. A request WITHOUT a session cookie
        // must stay 401 regardless of the Origin header.
        $this->withHeaders(['Origin' => rtrim(config('app.url'), '/')])
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/cookieless-probe',
                'keys' => ['p256h' => 'a', 'auth' => 'b'],
            ])
            ->assertUnauthorized();
    });
});
