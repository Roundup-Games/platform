<?php

use App\Exceptions\CalendarSyncRequestFailed;
use App\Exceptions\CalendarSyncRevoked;
use App\Models\GoogleCalendarConnection;
use App\Services\Calendar\Google\GoogleCalendarAuth;
use App\Services\Calendar\Google\GoogleCalendarClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Traits\CapturesLogRecords;

//
// Pins the managed-sync transport layer (M083/S02-T04): token freshness
// (expiry + safety-buffer refresh through GoogleCalendarAuth), the
// revocation translator (invalid_grant at the token endpoint and 401 at
// the API → revoked row + calendar_sync.revocation_detected + typed
// throw), the retryable failure surface (CalendarSyncRequestFailed
// carrying status + body, connection row untouched), and the structural
// sendUpdates=none enforcement on every mutating verb (D287: roundup
// notifications remain the only notification path).
//
// Lives in Unit/Calendar (the task's planned path), wired as a
// DB-wrapping unit dir in tests/Pest.php ($unitDirsNeedingTransactions):
// the cases need factory-persisted connections and assert persisted
// revocation writes, so they ride DatabaseTransactions like Unit/Services.
//

uses(CapturesLogRecords::class);

function calendarTransportClient(): GoogleCalendarClient
{
    return new GoogleCalendarClient(app(GoogleCalendarAuth::class));
}

/**
 * Test OAuth credentials for the token-endpoint call-shape assertions —
 * same helper posture as CalendarLinkFlowTest.
 */
function calendarTransportCredentials(): void
{
    config([
        'services.google.client_id' => 'calendar-test-client-id',
        'services.google.client_secret' => 'calendar-test-client-secret',
    ]);
}

// ── Happy path: fresh token, call shape ────────────────

it('sends an authorized GET with the stored token and no sendUpdates while the token is fresh', function () {
    $connection = GoogleCalendarConnection::factory()->create([
        'token' => 'fresh-access-token',
    ]);

    Http::fake([
        'https://www.googleapis.com/calendar/v3/*' => Http::response(['items' => [['id' => 'evt-1']]], 200),
    ]);

    $result = calendarTransportClient()->get($connection, 'calendars/primary/events', ['maxResults' => 10]);

    expect($result)->toBe(['items' => [['id' => 'evt-1']]]);

    Http::assertSent(function (Request $request): bool {
        // Request::url() carries the query string in this framework version.
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://www.googleapis.com/calendar/v3/calendars/primary/events')
            && str_contains($request->url(), 'maxResults=10')
            && ! str_contains($request->url(), 'sendUpdates')
            && $request->hasHeader('Authorization', 'Bearer fresh-access-token');
    });

    // A fresh token means no token-endpoint hop at all.
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token');
});

it('forces sendUpdates=none onto every mutating verb, overriding any caller attempt to widen it', function () {
    $connection = GoogleCalendarConnection::factory()->create([
        'token' => 'fresh-access-token',
    ]);

    Http::fake([
        'https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt-9'], 200),
    ]);

    $client = calendarTransportClient();

    // The POST deliberately asks for sendUpdates=all — the client must
    // refuse: Google notifications stay off, roundup's remain the only
    // notification path (D287).
    $client->post($connection, 'calendars/primary/events', ['summary' => 'Runde'], ['sendUpdates' => 'all']);
    $client->patch($connection, 'calendars/primary/events/evt-9', ['summary' => 'Runde 2']);
    $client->put($connection, 'calendars/primary/events/evt-9', ['summary' => 'Runde 3']);
    $client->delete($connection, 'calendars/primary/events/evt-9');

    foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
        Http::assertSent(function (Request $request) use ($method): bool {
            return $request->method() === $method
                && str_contains($request->url(), 'sendUpdates=none')
                && ! str_contains($request->url(), 'sendUpdates=all')
                && $request->hasHeader('Authorization', 'Bearer fresh-access-token');
        });
    }

    // Body-bearing verbs carry the payload as JSON.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['summary'] === 'Runde');
});

// ── Token freshness ────────────────────────────────────

it('refreshes an expired access token before the call and proceeds with the refreshed token', function () {
    calendarTransportCredentials();

    $connection = GoogleCalendarConnection::factory()->expiredToken()->create([
        'token' => 'stale-access-token',
        'refresh_token' => 'stored-refresh-token',
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'refreshed-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
        'https://www.googleapis.com/calendar/v3/*' => Http::response(['kind' => 'calendar#events'], 200),
    ]);

    $result = calendarTransportClient()->get($connection, 'calendars/primary/events');

    expect($result)->toBe(['kind' => 'calendar#events'])
        ->and($connection->fresh()->token)->toBe('refreshed-access-token')
        // Google never rotates the refresh token on this grant.
        ->and($connection->fresh()->refresh_token)->toBe('stored-refresh-token');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://oauth2.googleapis.com/token'
            && $request->method() === 'POST'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'stored-refresh-token'
            && $request['client_id'] === 'calendar-test-client-id'
            && $request['client_secret'] === 'calendar-test-client-secret';
    });

    // The API call rode the REFRESHED token, not the stale one.
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events'
        && $request->hasHeader('Authorization', 'Bearer refreshed-access-token'));
});

it('refreshes early when the token expires inside the safety buffer, not only once past expiry', function () {
    calendarTransportCredentials();

    $connection = GoogleCalendarConnection::factory()->create([
        'token' => 'almost-expired-token',
        'token_expires_at' => now()->addSeconds(30),
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'renewed-access-token', 'expires_in' => 3600]),
        'https://www.googleapis.com/calendar/v3/*' => Http::response([], 200),
    ]);

    calendarTransportClient()->get($connection, 'calendars/primary/events');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token');
});

// ── Revocation translation ─────────────────────────────

it('marks the connection revoked and throws when Google refuses the refresh grant with invalid_grant', function () {
    calendarTransportCredentials();

    $connection = GoogleCalendarConnection::factory()->expiredToken()->create();

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ], 400),
    ]);

    $this->captureLogRecords();

    expect(fn () => calendarTransportClient()->get($connection, 'calendars/primary/events'))
        ->toThrow(CalendarSyncRevoked::class);

    $fresh = $connection->fresh();

    expect($fresh->status)->toBe(GoogleCalendarConnection::STATUS_REVOKED)
        ->and($fresh->revoked_at)->not->toBeNull();

    // The API call never left — revocation stopped the transport before it.
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/calendar/v3'));

    $record = $this->logRecord('calendar_sync.revocation_detected');

    expect($record)->not->toBeNull()
        ->and($record['level_name'])->toBe('WARNING')
        ->and($record['context']['user_id'])->toBe($connection->user_id)
        ->and($record['context']['connection_id'])->toBe($connection->id)
        ->and($record['context']['trigger'])->toBe('refresh_refused');
});

it('marks the connection revoked and throws when the Calendar API answers 401', function () {
    $connection = GoogleCalendarConnection::factory()->create([
        'token' => 'dead-access-token',
    ]);

    Http::fake([
        'https://www.googleapis.com/calendar/v3/*' => Http::response([
            'error' => ['code' => 401, 'message' => 'Invalid Credentials'],
        ], 401),
    ]);

    $this->captureLogRecords();

    expect(fn () => calendarTransportClient()->get($connection, 'calendars/primary/events'))
        ->toThrow(CalendarSyncRevoked::class);

    $fresh = $connection->fresh();

    expect($fresh->status)->toBe(GoogleCalendarConnection::STATUS_REVOKED)
        ->and($fresh->revoked_at)->not->toBeNull();

    $record = $this->logRecord('calendar_sync.revocation_detected');

    expect($record)->not->toBeNull()
        ->and($record['context']['user_id'])->toBe($connection->user_id)
        ->and($record['context']['connection_id'])->toBe($connection->id)
        ->and($record['context']['trigger'])->toBe('api_401');
});

// ── Retryable failure surface ──────────────────────────

it('surfaces a 500 as CalendarSyncRequestFailed carrying status and body, without touching connection status', function () {
    $connection = GoogleCalendarConnection::factory()->create();

    Http::fake([
        'https://www.googleapis.com/calendar/v3/*' => Http::response([
            'error' => ['message' => 'Backend Error'],
        ], 500),
    ]);

    $failure = null;

    try {
        calendarTransportClient()->get($connection, 'calendars/primary/events');
    } catch (CalendarSyncRequestFailed $caught) {
        $failure = $caught;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->status)->toBe(500)
        ->and($failure->body)->toContain('Backend Error');

    $fresh = $connection->fresh();

    expect($fresh->status)->toBe(GoogleCalendarConnection::STATUS_ACTIVE)
        ->and($fresh->revoked_at)->toBeNull();
});

it('surfaces a rate-limited 403 through the same retryable failure exception', function () {
    $connection = GoogleCalendarConnection::factory()->create();

    Http::fake([
        'https://www.googleapis.com/calendar/v3/*' => Http::response([
            'error' => ['errors' => [['reason' => 'userRateLimitExceeded']]],
        ], 403, ['Retry-After' => '30']),
    ]);

    $failure = null;

    try {
        calendarTransportClient()->post($connection, 'calendars/primary/events', ['summary' => 'Runde']);
    } catch (CalendarSyncRequestFailed $caught) {
        $failure = $caught;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->status)->toBe(403)
        ->and($failure->body)->toContain('userRateLimitExceeded')
        ->and($connection->fresh()->status)->toBe(GoogleCalendarConnection::STATUS_ACTIVE);
});

it('keeps the connection active when the token endpoint fails transiently, so the queued job can retry', function () {
    calendarTransportCredentials();

    $connection = GoogleCalendarConnection::factory()->expiredToken()->create([
        'token' => 'still-stored-token',
        'refresh_token' => 'still-stored-refresh-token',
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['error' => 'temporarily_unavailable'], 503),
    ]);

    // A 503 is NOT a revocation signal — retryable failure, honest status kept.
    expect(fn () => calendarTransportClient()->get($connection, 'calendars/primary/events'))
        ->toThrow(CalendarSyncRequestFailed::class);

    $fresh = $connection->fresh();

    expect($fresh->status)->toBe(GoogleCalendarConnection::STATUS_ACTIVE)
        ->and($fresh->revoked_at)->toBeNull()
        ->and($fresh->token)->toBe('still-stored-token')
        ->and($fresh->refresh_token)->toBe('still-stored-refresh-token');
});
