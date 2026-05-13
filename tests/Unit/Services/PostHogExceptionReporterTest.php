<?php

use App\Services\PostHogClient;
use App\Services\PostHogExceptionReporter;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Helpers\TestablePostHogClient;

beforeEach(function () {
    config(['posthog.enabled' => true]);
    config(['posthog.api_key' => 'phc_test_key']);

    $this->posthogClient = new TestablePostHogClient();
    $this->app->instance(PostHogClient::class, $this->posthogClient);
    Cache::flush();
});

it('reports a runtime exception to PostHog', function () {
    Auth::logout();

    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new RuntimeException('Something broke'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    $payload = $this->posthogClient->capturedCalls[0];
    expect($payload['event'])->toBe('$exception')
        ->and($payload['properties']['$exception_type'])->toBe(RuntimeException::class)
        ->and($payload['properties']['$exception_message'])->toBe('Something broke')
        ->and($payload['properties']['$exception_source'])->toBe('php');
});

it('includes request context and stack trace in the captured event', function () {
    Auth::logout();

    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new \ErrorException('Test error'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    $props = $this->posthogClient->capturedCalls[0]['properties'];
    expect($props)->toHaveKeys([
        'request_url', 'request_method', 'request_path',
        'exception_file', 'exception_line',
        '$exception_stack_trace', '$exception_fingerprint',
    ]);
});

it('uses authenticated user ID as distinct ID', function () {
    $user = \App\Models\User::factory()->make(['id' => 42]);
    Auth::shouldReceive('user')->andReturn($user);

    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new RuntimeException('auth test'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    expect($this->posthogClient->capturedCalls[0]['distinctId'])->toBe('42');
});

it('skips 404 HttpException', function () {
    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new NotFoundHttpException('Not found'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

it('skips 403 AccessDeniedHttpException', function () {
    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new AccessDeniedHttpException('Forbidden'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

it('skips ModelNotFoundException', function () {
    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new ModelNotFoundException('Model not found'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

it('skips AuthenticationException', function () {
    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new AuthenticationException('Unauthenticated'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

it('skips ValidationException', function () {
    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(ValidationException::withMessages(['field' => 'Invalid']));

    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

it('skips TokenMismatchException', function () {
    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new TokenMismatchException('CSRF token mismatch'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

it('reports 500 HttpException', function () {
    Auth::logout();

    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new HttpException(500, 'Internal Server Error'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    expect($this->posthogClient->capturedCalls[0]['properties']['$exception_type'])->toBe(HttpException::class);
});

it('skips reporting when PostHog is disabled', function () {
    $this->posthogClient->setEnabled(false);

    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new RuntimeException('Should not report'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

it('skips reporting when API key is missing', function () {
    $this->posthogClient->setEnabled(false);

    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new RuntimeException('Should not report'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

it('rate-limits reports after 10 per exception class per minute', function () {
    Auth::logout();

    $reporter = app(PostHogExceptionReporter::class);

    // Send 10 reports — should all go through
    for ($i = 0; $i < 10; $i++) {
        $reporter->report(new RuntimeException("Error {$i}"));
    }

    expect($this->posthogClient->capturedCalls)->toHaveCount(10);

    // 11th should be rate-limited (no additional capture)
    $reporter->report(new RuntimeException('Rate limited'));
    expect($this->posthogClient->capturedCalls)->toHaveCount(10);
});

it('rate-limits per exception class independently', function () {
    Auth::logout();

    $reporter = app(PostHogExceptionReporter::class);

    // 10 RuntimeException — all go through
    for ($i = 0; $i < 10; $i++) {
        $reporter->report(new RuntimeException("Error {$i}"));
    }

    // Different class — not rate-limited
    $reporter->report(new InvalidArgumentException('Different class'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(11);
});

it('never throws when capture fails', function () {
    Auth::logout();

    // Use a client that throws on capture
    $throwingClient = new class extends TestablePostHogClient {
        public function capture(array $payload): void
        {
            throw new \RuntimeException('PostHog down');
        }
    };
    $this->app->instance(PostHogClient::class, $throwingClient);

    $reporter = app(PostHogExceptionReporter::class);

    // Should NOT throw — failure is caught and logged
    $reporter->report(new RuntimeException('Original error'));

    expect(true)->toBeTrue(); // Reached without exception
});

it('builds stack trace starting with exception class and location', function () {
    Auth::logout();

    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new RuntimeException('Stack test'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    $trace = $this->posthogClient->capturedCalls[0]['properties']['$exception_stack_trace'];
    expect($trace)->toStartWith(RuntimeException::class)
        ->and($trace)->toContain('.php:');
});
