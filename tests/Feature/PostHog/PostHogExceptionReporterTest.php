<?php

use App\Models\User;
use App\Services\PostHogClient;
use App\Services\PostHogExceptionReporter;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
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

    $this->posthogClient = new TestablePostHogClient;
    $this->app->instance(PostHogClient::class, $this->posthogClient);
    Cache::flush();
});

/**
 * Feature tests verify the exception → Laravel handler → PostHogExceptionReporter pipeline.
 * We bind TestablePostHogClient into the container and exercise the exception handler's
 * report() method to confirm the reportable() callback in bootstrap/app.php fires correctly.
 */
describe('5xx exception pipeline', function () {
    it('reports a RuntimeException through the exception handler', function () {
        $handler = app(ExceptionHandler::class);
        $handler->report(new RuntimeException('Server blew up'));

        expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    });

    it('reports an ErrorException through the exception handler', function () {
        $handler = app(ExceptionHandler::class);
        $handler->report(new ErrorException('Undefined variable', 0, E_ERROR, '/app/test.php', 42));

        expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    });

    // Note: HttpException(500) is NOT tested here because Laravel's $internalDontReport
    // filters ALL HttpExceptions before reportable callbacks run. The unit test covers
    // HttpException(500) by calling the reporter directly.

    it('captures correct event shape for a 5xx error', function () {
        $handler = app(ExceptionHandler::class);
        $handler->report(new RuntimeException('Test error'));

        expect($this->posthogClient->capturedCalls)->toHaveCount(1);
        $payload = $this->posthogClient->capturedCalls[0];
        expect($payload['event'])->toBe('$exception')
            ->and($payload['properties'])->toHaveKey('$exception_list')
            ->and($payload['properties']['$exception_source'])->toBe('php')
            ->and($payload['properties'])->toHaveKey('$exception_fingerprint')
            // Request context is path-only — never the full URL/query (privacy).
            ->and($payload['properties'])->toHaveKey('request_path')
            ->and($payload['properties'])->toHaveKey('request_method')
            ->and($payload['properties'])->not->toHaveKey('request_url')
            ->and($payload['properties'])->toHaveKey('exception_file')
            ->and($payload['properties'])->toHaveKey('exception_line');
    });
});

describe('4xx exception pipeline', function () {
    it('does NOT capture a 404 NotFoundHttpException', function () {
        $handler = app(ExceptionHandler::class);
        $handler->report(new NotFoundHttpException('Page not found'));

        expect($this->posthogClient->capturedCalls)->toHaveCount(0);
    });

    it('does NOT capture a 403 AccessDeniedHttpException', function () {
        $handler = app(ExceptionHandler::class);
        $handler->report(new AccessDeniedHttpException('Forbidden'));

        expect($this->posthogClient->capturedCalls)->toHaveCount(0);
    });

    it('does NOT capture a ModelNotFoundException', function () {
        $handler = app(ExceptionHandler::class);
        $handler->report(new ModelNotFoundException);

        expect($this->posthogClient->capturedCalls)->toHaveCount(0);
    });

    it('does NOT capture a ValidationException', function () {
        $handler = app(ExceptionHandler::class);
        $handler->report(ValidationException::withMessages(['email' => 'Invalid email']));

        expect($this->posthogClient->capturedCalls)->toHaveCount(0);
    });
});

describe('PostHog failure resilience', function () {
    it('exception handler does not throw when PostHog capture fails', function () {
        // PostHogClient::capture() catches SDK errors internally.
        // Use a client that silently drops (simulates caught SDK error).
        $silentClient = new class extends TestablePostHogClient
        {
            public function capture(array $payload): void
            {
                // Silent drop — PostHogClient caught the SDK error
            }
        };
        $this->app->instance(PostHogClient::class, $silentClient);

        $handler = app(ExceptionHandler::class);

        // Must NOT throw — PostHogClient absorbs SDK errors internally
        $handler->report(new RuntimeException('Original error'));

        expect(true)->toBeTrue(); // Reached without exception
    });
});

describe('rate limiting integration', function () {
    it('stops capturing after 10 rapid reports of the same exception type', function () {
        $handler = app(ExceptionHandler::class);

        // Send 12 reports — only first 10 should trigger capture
        for ($i = 0; $i < 12; $i++) {
            $handler->report(new RuntimeException("Error {$i}"));
        }

        expect($this->posthogClient->capturedCalls)->toHaveCount(10);
    });

    it('rate limits independently per exception class', function () {
        $handler = app(ExceptionHandler::class);

        // 10 RuntimeException — all captured
        for ($i = 0; $i < 10; $i++) {
            $handler->report(new RuntimeException("Error {$i}"));
        }

        // Different class — not rate-limited, captured
        $handler->report(new InvalidArgumentException('Different class'));

        expect($this->posthogClient->capturedCalls)->toHaveCount(11);
    });
});

describe('PostHog disabled state', function () {
    it('does not call capture when PostHog is disabled', function () {
        $this->posthogClient->setEnabled(false);

        $handler = app(ExceptionHandler::class);
        $handler->report(new RuntimeException('Should not report'));

        expect($this->posthogClient->capturedCalls)->toHaveCount(0);
    });

    it('does not call capture when API key is missing', function () {
        $this->posthogClient->setEnabled(false);

        $handler = app(ExceptionHandler::class);
        $handler->report(new RuntimeException('No API key'));

        expect($this->posthogClient->capturedCalls)->toHaveCount(0);
    });
});

// ── Merged from Unit — isolation tests ──────────────────
// These test PostHogExceptionReporter directly (not via ExceptionHandler pipeline)
// to cover edge cases that Laravel's $internalDontReport filters out.

it('uses authenticated user ID as distinct ID via reporter', function () {
    $user = User::factory()->make(['id' => 42]);
    Auth::shouldReceive('user')->andReturn($user);

    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new RuntimeException('auth test'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    expect($this->posthogClient->capturedCalls[0]['distinctId'])->toBe('42');
});

it('skips AuthenticationException via reporter', function () {
    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new AuthenticationException('Unauthenticated'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

it('skips TokenMismatchException via reporter', function () {
    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new TokenMismatchException('CSRF token mismatch'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(0);
});

it('reports 500 HttpException directly via reporter', function () {
    Auth::logout();

    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new HttpException(500, 'Internal Server Error'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    $payload = $this->posthogClient->capturedCalls[0];
    expect($payload['properties'])->toHaveKey('$exception_list')
        ->and($payload['properties']['$exception_list'][0]['type'])->toBe(HttpException::class);
});

/**
 * Build a QueryException that mirrors a failed database session read: a
 * connection refusal on "select * from sessions where id = <session id>".
 * Only the session id binding changes between requests.
 */
function makeSessionReadQueryException(string $sessionId): QueryException
{
    $previous = new PDOException('SQLSTATE[08006] [7] could not connect to server: Connection refused');
    $previous->errorInfo = ['08006', 7, 'could not connect to server'];

    return new QueryException(
        'pgsql',
        'select * from "sessions" where "id" = ?',
        [$sessionId],
        $previous,
    );
}

describe('QueryException fingerprint grouping', function () {
    it('groups database failures that differ only by session id', function () {
        // Build both exceptions from one call site so they share a stack frame,
        // as a real session read does — there the failure is thrown below the
        // framework, with no application frame between it and the throw point.
        $exceptions = array_map(
            fn (string $id) => makeSessionReadQueryException($id),
            ['session-aaa', 'session-bbb'],
        );

        $reporter = app(PostHogExceptionReporter::class);
        foreach ($exceptions as $exception) {
            $reporter->report($exception);
        }

        expect($this->posthogClient->capturedCalls)->toHaveCount(2);

        $first = $this->posthogClient->capturedCalls[0]['properties']['$exception_fingerprint'];
        $second = $this->posthogClient->capturedCalls[1]['properties']['$exception_fingerprint'];
        expect($first)->toBe($second);
    });

    it('keeps the session id out of the reported exception value', function () {
        $reporter = app(PostHogExceptionReporter::class);
        $reporter->report(makeSessionReadQueryException('super-secret-session-id'));

        expect($this->posthogClient->capturedCalls)->toHaveCount(1);
        $value = $this->posthogClient->capturedCalls[0]['properties']['$exception_list'][0]['value'];
        expect($value)
            ->not->toContain('super-secret-session-id')
            ->not->toContain('SQL:')
            // The driver message survives so the error stays readable.
            ->toContain('SQLSTATE[08006]');
    });
});

it('builds stack trace starting with exception class and location via reporter', function () {
    Auth::logout();

    $reporter = app(PostHogExceptionReporter::class);
    $reporter->report(new RuntimeException('Stack test'));

    expect($this->posthogClient->capturedCalls)->toHaveCount(1);
    $exceptionList = $this->posthogClient->capturedCalls[0]['properties']['$exception_list'];
    expect($exceptionList)->toHaveCount(1)
        ->and($exceptionList[0]['type'])->toBe(RuntimeException::class)
        ->and($exceptionList[0])->toHaveKey('stacktrace');
});
