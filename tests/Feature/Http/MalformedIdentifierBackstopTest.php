<?php

use App\Services\PostHogExceptionReporter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;

// Backstop in bootstrap/app.php: SQLSTATE 22P02 (a client value that cannot
// be a uuid column value) renders as the same 404 a well-formed unknown id
// returns, logs a warning, and is not reported to error tracking. These
// exercise the handler contract directly — the site's {path} .* catch-all
// (routes/web.php) outranks any route registered at test time, so real-route
// probing cannot reach an unguarded direct-where bind from a test.
function malformedIdentifierException(): QueryException
{
    $previous = new PDOException('SQLSTATE[22P02]: Invalid text representation');
    $previous->errorInfo = ['22P02', 7, 'ERROR: invalid input syntax for type uuid'];

    return new QueryException('pgsql', 'select * from games where id = ?', ['not-a-uuid'], $previous);
}

function otherQueryException(): QueryException
{
    $previous = new PDOException('SQLSTATE[42703]: Undefined column');
    $previous->errorInfo = ['42703', 4, 'ERROR: column no_such_column does not exist'];

    return new QueryException('pgsql', 'select no_such_column from games', [], $previous);
}

it('renders a malformed identifier as 404 html', function () {
    Log::spy();

    $response = app(ExceptionHandler::class)->render(
        Request::create('/en/games/not-a-uuid', 'GET'),
        malformedIdentifierException(),
    );

    expect($response->getStatusCode())->toBe(404);
});

it('renders a malformed identifier as 404 json for api requests', function () {
    Log::spy();

    $response = app(ExceptionHandler::class)->render(
        Request::create('/api/v1/games', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
        malformedIdentifierException(),
    );

    // Debug mode appends exception/trace keys to the framework's error array;
    // the contract is the message, matching an unknown route's 404 json.
    $payload = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(404)
        ->and($payload['message'])->toBe('Not Found');
});

it('does not report a malformed identifier but logs a warning', function () {
    // TestCase arms Exceptions::fake() suite-wide; put the real handler back
    // (same pattern as PostHogExceptionReporterTest) so report() runs its chain.
    Exceptions::swap(app(ExceptionHandler::class)->handler());
    Log::spy();
    $reporter = $this->spy(PostHogExceptionReporter::class);

    app(ExceptionHandler::class)->report(malformedIdentifierException());

    Log::shouldHaveReceived('warning')->once()->with('Malformed identifier rejected at the database boundary', Mockery::any());
    $reporter->shouldNotHaveReceived('report');
});

it('still reports and errors for unrelated query failures', function () {
    Exceptions::swap(app(ExceptionHandler::class)->handler());
    Log::spy();
    $reporter = $this->spy(PostHogExceptionReporter::class);

    app(ExceptionHandler::class)->report(otherQueryException());

    $reporter->shouldHaveReceived('report');

    $response = app(ExceptionHandler::class)->render(
        Request::create('/en/games', 'GET'),
        otherQueryException(),
    );

    expect($response->getStatusCode())->toBe(500);
});
