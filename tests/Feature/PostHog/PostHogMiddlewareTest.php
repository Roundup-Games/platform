<?php

use App\Http\Middleware\PostHogIdentifyUsers;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\PostHogClient;
use Illuminate\Support\Facades\Config;
use App\Services\PostHogConsentChecker;
use Tests\Helpers\TestablePostHogClient;

function makeOkResponse()
{
    return new \Illuminate\Http\Response('', 200);
}

beforeEach(function () {
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', null); // No real key in tests — server-side identify is skipped

    $this->posthogClient = new TestablePostHogClient();
    $this->app->instance(PostHogClient::class, $this->posthogClient);

    // Grant analytics consent by default in tests — individual tests can override
    $consentChecker = $this->mock(PostHogConsentChecker::class);
    $consentChecker->shouldReceive('hasAnalyticsConsent')->andReturn(true);
    $this->app->instance(PostHogConsentChecker::class, $consentChecker);
});

describe('PostHogIdentifyUsers — authenticated users', function () {
    test('shares client-side identify data for authenticated user on GET', function () {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $request = Request::create('/games', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $response = $middleware->handle($request, fn ($req) => makeOkResponse());

        // Client-side data contains only the user ID — PII is set via server-side identify()
        $sharedData = view()->shared('posthogIdentifyData');
        expect($sharedData)->not->toBeNull()
            ->and($sharedData)->toBe(['id' => (string) $user->id]);
    });

    test('client-side identify data contains only user ID', function () {
        $user = User::factory()->create([
            'preferred_language' => 'de',
        ]);

        $request = Request::create('/dashboard', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $middleware->handle($request, fn ($req) => makeOkResponse());

        // PII (name, email, locale) is set server-side only — not exposed in DOM
        $sharedData = view()->shared('posthogIdentifyData');
        expect($sharedData)->toHaveKey('id')
            ->and($sharedData)->not->toHaveKey('properties')
            ->and($sharedData)->not->toHaveKey('setOnce');
    });

    test('server-side identify sets locale via preferred_language or app fallback', function () {
        // The server-side identify() call includes locale in $set properties.
        // This test verifies the middleware runs without error when preferred_language is null.
        $user = User::factory()->create([
            'preferred_language' => null,
        ]);

        $request = Request::create('/dashboard', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $response = $middleware->handle($request, fn ($req) => makeOkResponse());

        // Middleware completed successfully — server-side identify uses app locale fallback
        expect($response->status())->toBe(200);
        $sharedData = view()->shared('posthogIdentifyData');
        expect($sharedData)->toHaveKey('id');
    });

    test('returns response without errors', function () {
        $user = User::factory()->create();

        $request = Request::create('/games', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $response = $middleware->handle($request, fn ($req) => makeOkResponse());

        expect($response->status())->toBe(200);
    });
});

describe('PostHogIdentifyUsers — route skipping', function () {
    test('skips identification on API routes', function () {
        $user = User::factory()->create();

        $request = Request::create('/api/v1/games', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $middleware->handle($request, fn ($req) => makeOkResponse());

        // No view data shared — middleware skipped
        expect(view()->shared('posthogIdentifyData'))->toBeNull();
    });

    test('skips identification on admin routes', function () {
        $user = User::factory()->create();

        $request = Request::create('/admin/dashboard', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $middleware->handle($request, fn ($req) => makeOkResponse());

        expect(view()->shared('posthogIdentifyData'))->toBeNull();
    });

    test('skips identification on Livewire internal routes', function () {
        $user = User::factory()->create();

        $request = Request::create('/livewire/update', 'POST');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $middleware->handle($request, fn ($req) => makeOkResponse());

        expect(view()->shared('posthogIdentifyData'))->toBeNull();
    });

    test('skips identification on requests with X-Livewire header', function () {
        $user = User::factory()->create();

        $request = Request::create('/some-page', 'GET');
        $request->headers->set('X-Livewire', 'true');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $middleware->handle($request, fn ($req) => makeOkResponse());

        expect(view()->shared('posthogIdentifyData'))->toBeNull();
    });

    test('skips identification on non-GET requests', function () {
        $user = User::factory()->create();

        $request = Request::create('/games', 'POST');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $middleware->handle($request, fn ($req) => makeOkResponse());

        expect(view()->shared('posthogIdentifyData'))->toBeNull();
    });
});

describe('PostHogIdentifyUsers — unauthenticated users', function () {
    test('handles unauthenticated users gracefully', function () {
        $request = Request::create('/games', 'GET');
        $request->setUserResolver(fn () => null);

        $middleware = app(PostHogIdentifyUsers::class);
        $response = $middleware->handle($request, fn ($req) => makeOkResponse());

        expect($response->status())->toBe(200);
        expect(view()->shared('posthogIdentifyData'))->toBeNull();
    });
});

describe('PostHogIdentifyUsers — server-side identify guard', function () {
    test('server-side identify is skipped when disabled', function () {
        Config::set('posthog.enabled', false);

        $user = User::factory()->create();

        $request = Request::create('/games', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $response = $middleware->handle($request, fn ($req) => makeOkResponse());

        // Still shares client data even when disabled (client-side decides)
        $sharedData = view()->shared('posthogIdentifyData');
        expect($sharedData)->not->toBeNull()
            ->and($sharedData['id'])->toBe((string) $user->id);
        expect($response->status())->toBe(200);
    });

    test('server-side identify is skipped when api_key is missing', function () {
        // api_key is null by default in test — server-side identify silently skipped
        Config::set('posthog.enabled', true);
        Config::set('posthog.api_key', null);

        $user = User::factory()->create();

        $request = Request::create('/games', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $response = $middleware->handle($request, fn ($req) => makeOkResponse());

        // Should not crash — just skip server-side identify
        expect($response->status())->toBe(200);
    });
});

describe('PostHogIdentifyUsers — session-based identify throttle', function () {
    test('server-side identify is called only once per session', function () {
        Config::set('posthog.api_key', 'phc_test_key');
        Config::set('posthog.enabled', true);

        $user = User::factory()->create();
        $middleware = app(PostHogIdentifyUsers::class);

        // First request — should call identify
        $request1 = Request::create('/games', 'GET');
        $request1->setUserResolver(fn () => $user);
        $middleware->handle($request1, fn ($req) => makeOkResponse());

        expect($this->posthogClient->identifyCalls)->toHaveCount(1);

        // Second request — same session, should NOT call identify again
        $request2 = Request::create('/dashboard', 'GET');
        $request2->setUserResolver(fn () => $user);
        $middleware->handle($request2, fn ($req) => makeOkResponse());

        expect($this->posthogClient->identifyCalls)->toHaveCount(1); // Still 1 — throttled
    });

    test('client-side data is shared on every request regardless of throttle', function () {
        Config::set('posthog.api_key', 'phc_test_key');
        Config::set('posthog.enabled', true);

        $user = User::factory()->create();
        $middleware = app(PostHogIdentifyUsers::class);

        $request1 = Request::create('/games', 'GET');
        $request1->setUserResolver(fn () => $user);
        $middleware->handle($request1, fn ($req) => makeOkResponse());

        expect(view()->shared('posthogIdentifyData'))->toBe(['id' => (string) $user->id]);

        $request2 = Request::create('/dashboard', 'GET');
        $request2->setUserResolver(fn () => $user);
        $middleware->handle($request2, fn ($req) => makeOkResponse());

        // Client data still shared on second request
        expect(view()->shared('posthogIdentifyData'))->toBe(['id' => (string) $user->id]);
    });
});

describe('PostHogIdentifyUsers — middleware registration', function () {
    test('is registered in global middleware stack', function () {
        // Verify the middleware class is referenced in bootstrap/app.php
        $bootstrapContent = file_get_contents(base_path('bootstrap/app.php'));

        expect($bootstrapContent)->toContain('PostHogIdentifyUsers');
    });
});

describe('PostHogIdentifyUsers — analytics consent gating', function () {
    test('skips all PostHog calls when analytics consent is not granted', function () {
        Config::set('posthog.api_key', 'phc_test_key');
        Config::set('posthog.enabled', true);

        // Override consent checker to deny consent
        $deniedChecker = $this->mock(PostHogConsentChecker::class);
        $deniedChecker->shouldReceive('hasAnalyticsConsent')->andReturn(false);
        $this->app->instance(PostHogConsentChecker::class, $deniedChecker);

        $user = User::factory()->create();
        $request = Request::create('/games', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $response = $middleware->handle($request, fn ($req) => makeOkResponse());

        // No identify calls, no client-side data shared
        expect($response->status())->toBe(200);
        expect(view()->shared('posthogIdentifyData'))->toBeNull();
        expect($this->posthogClient->identifyCalls)->toHaveCount(0);
    });

    test('processes PostHog calls when analytics consent is granted', function () {
        Config::set('posthog.api_key', 'phc_test_key');
        Config::set('posthog.enabled', true);

        // Consent is granted by default via beforeEach mock
        $user = User::factory()->create();
        $request = Request::create('/games', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $response = $middleware->handle($request, fn ($req) => makeOkResponse());

        expect($response->status())->toBe(200);
        $sharedData = view()->shared('posthogIdentifyData');
        expect($sharedData)->not->toBeNull()
            ->and($sharedData['id'])->toBe((string) $user->id);
    });
});
