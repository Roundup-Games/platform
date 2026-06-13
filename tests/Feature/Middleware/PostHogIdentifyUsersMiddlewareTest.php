<?php

use App\Http\Middleware\PostHogIdentifyUsers;
use App\Models\User;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

function makeMiddlewareResponse()
{
    return new Response('', 200);
}

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_test_consent_key');
});

describe('PostHog consent gating — middleware skips without consent', function () {
    test('middleware skips identify when no consent cookie is present', function () {
        $posthogClient = $this->mock(PostHogClient::class);
        $posthogClient->shouldNotReceive('identify');
        $this->app->instance(PostHogClient::class, $posthogClient);

        // Use real consent checker — no cookie set
        $this->app->instance(PostHogConsentChecker::class, new PostHogConsentChecker);

        $user = User::factory()->create();

        $request = Request::create('/games', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $response = $middleware->handle($request, fn ($req) => makeMiddlewareResponse());

        expect($response->status())->toBe(200);
        // No client-side data shared — consent denied
        expect(view()->shared('posthogIdentifyData'))->toBeNull();
    });

    test('middleware skips identify when analytics consent is explicitly denied', function () {
        $posthogClient = $this->mock(PostHogClient::class);
        $posthogClient->shouldNotReceive('identify');
        $this->app->instance(PostHogClient::class, $posthogClient);

        // Real consent checker with denied analytics
        $this->app->instance(PostHogConsentChecker::class, new PostHogConsentChecker);

        $user = User::factory()->create();

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        $symfonyRequest->cookies->set('cookie_consent', json_encode([
            'necessary' => true,
            'analytics' => false,
            'marketing' => false,
        ]));
        $request = Request::createFromBase($symfonyRequest);
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $response = $middleware->handle($request, fn ($req) => makeMiddlewareResponse());

        expect($response->status())->toBe(200);
        expect(view()->shared('posthogIdentifyData'))->toBeNull();
    });
});

describe('PostHog consent gating — middleware proceeds with consent', function () {
    test('middleware identifies user when analytics consent is granted', function () {
        $posthogClient = $this->mock(PostHogClient::class);
        $posthogClient->shouldReceive('isEnabled')->andReturn(true);
        $posthogClient->shouldReceive('identify')->once();
        $this->app->instance(PostHogClient::class, $posthogClient);

        // Real consent checker with granted analytics
        $this->app->instance(PostHogConsentChecker::class, new PostHogConsentChecker);

        $user = User::factory()->create();

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        $symfonyRequest->cookies->set('cookie_consent', json_encode([
            'necessary' => true,
            'analytics' => true,
            'marketing' => false,
        ]));
        $request = Request::createFromBase($symfonyRequest);
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $response = $middleware->handle($request, fn ($req) => makeMiddlewareResponse());

        expect($response->status())->toBe(200);
        $sharedData = view()->shared('posthogIdentifyData');
        expect($sharedData)->not->toBeNull()
            ->and($sharedData['id'])->toBe((string) $user->id);
    });
});

describe('PostHog consent gating — Do Not Track header', function () {
    test('middleware skips identify when DNT header is set even with consent', function () {
        $posthogClient = $this->mock(PostHogClient::class);
        $posthogClient->shouldNotReceive('identify');
        $this->app->instance(PostHogClient::class, $posthogClient);

        // Real consent checker with granted analytics
        $this->app->instance(PostHogConsentChecker::class, new PostHogConsentChecker);

        $user = User::factory()->create();

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        $symfonyRequest->cookies->set('cookie_consent', json_encode([
            'necessary' => true,
            'analytics' => true,
            'marketing' => false,
        ]));
        $symfonyRequest->headers->set('DNT', '1');
        $request = Request::createFromBase($symfonyRequest);
        $request->setUserResolver(fn () => $user);

        $middleware = app(PostHogIdentifyUsers::class);
        $response = $middleware->handle($request, fn ($req) => makeMiddlewareResponse());

        expect($response->status())->toBe(200);
        expect(view()->shared('posthogIdentifyData'))->toBeNull();
    });
});

describe('PostHog consent gating — consent checker integration', function () {
    test('consent checker reads cookie_consent from request correctly', function () {
        $checker = new PostHogConsentChecker;

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        $symfonyRequest->cookies->set('cookie_consent', json_encode([
            'necessary' => true,
            'analytics' => true,
            'marketing' => true,
        ]));
        $request = Request::createFromBase($symfonyRequest);

        expect($checker->hasAnalyticsConsent($request))->toBeTrue();
        expect($checker->getConsentState($request))->toBe([
            'necessary' => true,
            'analytics' => true,
            'marketing' => true,
        ]);
    });

    test('consent checker returns false for missing cookie', function () {
        $checker = new PostHogConsentChecker;

        $request = Request::create('/games', 'GET');

        expect($checker->hasAnalyticsConsent($request))->toBeFalse();
        expect($checker->getConsentState($request))->toBeNull();
    });

    test('consent checker returns false for malformed cookie', function () {
        $checker = new PostHogConsentChecker;

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        $symfonyRequest->cookies->set('cookie_consent', '{invalid json');
        $request = Request::createFromBase($symfonyRequest);

        expect($checker->hasAnalyticsConsent($request))->toBeFalse();
    });
});

describe('Consent revocation persists to user model', function () {
    test('terminate() sets analytics_consent false when cookie is absent', function () {
        $user = User::factory()->create(['analytics_consent' => true]);

        $posthogClient = $this->mock(PostHogClient::class);
        $posthogClient->shouldNotReceive('identify');
        $this->app->instance(PostHogClient::class, $posthogClient);

        // Real consent checker — no cookie set (revoked)
        $this->app->instance(PostHogConsentChecker::class, new PostHogConsentChecker);

        $middleware = $this->app->make(PostHogIdentifyUsers::class);

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        $request = Request::createFromBase($symfonyRequest);
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn () => makeMiddlewareResponse());

        // Call terminate — should detect consent revoked and update user
        $middleware->terminate($request, $response);

        expect($user->fresh()->analytics_consent)->toBeFalse();
    });

    test('terminate() does not update when consent cookie is present', function () {
        $user = User::factory()->create(['analytics_consent' => true]);

        $posthogClient = $this->mock(PostHogClient::class);
        $posthogClient->shouldReceive('isEnabled')->andReturn(false);
        $this->app->instance(PostHogClient::class, $posthogClient);

        // Consent checker with cookie present
        $this->app->instance(PostHogConsentChecker::class, new PostHogConsentChecker);

        $middleware = $this->app->make(PostHogIdentifyUsers::class);

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        $symfonyRequest->cookies->set('cookie_consent', json_encode(['analytics' => true]));
        $request = Request::createFromBase($symfonyRequest);
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn () => makeMiddlewareResponse());
        $middleware->terminate($request, $response);

        expect($user->fresh()->analytics_consent)->toBeTrue();
    });
});
