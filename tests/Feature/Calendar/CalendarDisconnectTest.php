<?php

use App\Exceptions\CalendarSyncRequestFailed;
use App\Jobs\DisconnectGoogleCalendarConnection;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\Calendar\Google\GoogleCalendarAuth;
use App\Services\Calendar\Google\GoogleCalendarSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Traits\CapturesLogRecords;

//
// Pins the remove-disconnect path (M083 post-review remediation): the
// marker-scoped purge (Google-side privateExtendedProperty filter +
// client-side guard so an unmarked event is never deleted), the
// purge-BEFORE-revoke ordering (after the revoke the credentials are
// dead — cleanup can only ever run first), the disconnecting-status
// race guard, the grant-died convergence, and the retryable transient
// failure that leaves the row honestly in "Disconnecting…".
//

uses(CapturesLogRecords::class);

beforeEach(function () {
    $this->captureLogRecords();
});

// ── Helpers ────────────────────────────────────────────

function calendarDisconnectEvent(string $id, string $roundupType = 'game'): array
{
    return [
        'id' => $id,
        'status' => 'confirmed',
        'start' => ['dateTime' => now()->addDays(3)->toRfc3339String()],
        'extendedProperties' => ['private' => [
            'roundup_uid' => 'game-fixture@roundup.games',
            'roundup_type' => $roundupType,
        ]],
    ];
}

/**
 * A connection in the remove-disconnect state — the row the Livewire
 * confirm leaves behind for the job to own.
 */
function calendarDisconnectingConnection(): GoogleCalendarConnection
{
    $user = User::factory()->create();

    return GoogleCalendarConnection::factory()->create([
        'user_id' => $user->id,
        'status' => GoogleCalendarConnection::STATUS_DISCONNECTING,
    ]);
}

/**
 * Execute the disconnect job synchronously with its real collaborators.
 */
function calendarDisconnectRun(GoogleCalendarConnection $connection): void
{
    (new DisconnectGoogleCalendarConnection($connection->id))->handle(
        app(GoogleCalendarSyncService::class),
        app(GoogleCalendarAuth::class),
    );
}

/**
 * The marked inventory this fake reports: one page of games, one page of
 * events; DELETEs and the revoke succeed. An unmarked event rides the
 * game page to prove the client-side guard refuses it.
 */
function calendarDisconnectFakeSuccessfulPurge(): void
{
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            $items = str_contains($request->url(), 'roundup_type%3Dgame')
                ? [
                    calendarDisconnectEvent('game-event-1'),
                    calendarDisconnectEvent('game-event-2'),
                    // Unmarked: Google's filter leaked it, the guard must refuse it.
                    ['id' => 'user-owned-event', 'status' => 'confirmed'],
                ]
                : [calendarDisconnectEvent('event-event-1', 'event')];

            return Http::response(['items' => $items], 200);
        }

        return Http::response([], 204);
    });
}

/**
 * Recorded requests in send order, normalized from the [request, response]
 * pairs Http::recorded() yields — the ordering assertions (deletes before
 * revoke) read this sequence.
 *
 * @return list<Request>
 */
function calendarDisconnectRequestSequence(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->values()
        ->all();
}

/**
 * @return Collection<int, Request>
 */
function calendarDisconnectDeleteRequests()
{
    return collect(calendarDisconnectRequestSequence())
        ->filter(fn (Request $request): bool => $request->method() === 'DELETE');
}

// ── The purge itself ───────────────────────────────────

it('purges every marked event across types, refuses unmarked ones, and forces sendUpdates=none on every delete', function () {
    $connection = GoogleCalendarConnection::factory()->create();

    calendarDisconnectFakeSuccessfulPurge();

    $removed = app(GoogleCalendarSyncService::class)->deleteAllRoundupEvents($connection);

    expect($removed)->toBe(3);

    $deletes = calendarDisconnectDeleteRequests();

    expect($deletes->count())->toBe(3);

    foreach (['game-event-1', 'game-event-2', 'event-event-1'] as $eventId) {
        expect($deletes->contains(fn (Request $request) => str_contains($request->url(), '/'.$eventId)))->toBeTrue();
    }

    expect($deletes->contains(fn (Request $request) => str_contains($request->url(), 'user-owned-event')))->toBeFalse();

    // sendUpdates=none is merged by the client on every mutation — a
    // purge must never notify guests.
    $deletes->each(fn (Request $request) => expect($request->url())->toContain('sendUpdates=none'));
});

it('paginates the purge listing to the last page', function () {
    $connection = GoogleCalendarConnection::factory()->create();

    Http::fake(function (Request $request) {
        if ($request->method() !== 'GET') {
            return Http::response([], 204);
        }

        if (str_contains($request->url(), 'roundup_type%3Dgame') && ! str_contains($request->url(), 'pageToken')) {
            return Http::response([
                'items' => [calendarDisconnectEvent('page-1-event')],
                'nextPageToken' => 'page-2',
            ], 200);
        }

        return Http::response(['items' => []], 200);
    });

    $removed = app(GoogleCalendarSyncService::class)->deleteAllRoundupEvents($connection);

    expect($removed)->toBe(1);

    expect(collect(calendarDisconnectRequestSequence())->contains(fn (Request $request) => str_contains($request->url(), 'pageToken=page-2')))->toBeTrue();
});

it('treats a 404 delete as convergence, not failure', function () {
    $connection = GoogleCalendarConnection::factory()->create();

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(['items' => [calendarDisconnectEvent('already-gone')]], 200);
        }

        return Http::response(['error' => 'notFound'], 404);
    });

    expect(fn () => app(GoogleCalendarSyncService::class)->deleteAllRoundupEvents($connection))
        ->not->toThrow(CalendarSyncRequestFailed::class);
});

// ── The job's ordering and outcomes ────────────────────

it('purges, then revokes, then deletes the row — cleanup never runs on dead credentials', function () {
    $connection = calendarDisconnectingConnection();

    calendarDisconnectFakeSuccessfulPurge();

    calendarDisconnectRun($connection);

    expect(GoogleCalendarConnection::count())->toBe(0);

    $sequence = array_map(
        fn (Request $request): string => $request->method().' '.$request->url(),
        calendarDisconnectRequestSequence(),
    );

    $lastDeleteIndex = array_reduce(
        array_keys($sequence),
        fn (int $carry, int $index): int => str_starts_with($sequence[$index], 'DELETE ') ? $index : $carry,
        -1,
    );
    $revokeIndex = array_search('POST https://oauth2.googleapis.com/revoke', $sequence, true);

    expect($revokeIndex)->not->toBeFalse()
        ->and($lastDeleteIndex)->not->toBe(-1)
        ->and($lastDeleteIndex)->toBeLessThan($revokeIndex);

    $summary = $this->logRecord('calendar_sync.disconnect_purge_summary');

    expect($summary)->not->toBeNull()
        ->and($summary['context']['events_removed'])->toBe(3)
        ->and($this->logRecord('calendar_sync.disconnected'))->not->toBeNull();
});

it('aborts without touching anything when the row is no longer pending — the reconnect race', function () {
    $connection = GoogleCalendarConnection::factory()->create([
        'status' => GoogleCalendarConnection::STATUS_ACTIVE,
    ]);

    Http::fake();

    calendarDisconnectRun($connection);

    expect(GoogleCalendarConnection::count())->toBe(1)
        ->and($connection->fresh()->status)->toBe(GoogleCalendarConnection::STATUS_ACTIVE);

    expect(Http::recorded())->toBeEmpty();

    expect($this->logRecord('calendar_sync.disconnect_skipped_not_pending'))->not->toBeNull()
        ->and($this->logRecord('calendar_sync.disconnect_purge_summary'))->toBeNull();
});

it('keeps a fresh consent that reconnected WHILE the purge was running — the destructive tail re-checks the guard', function () {
    $connection = calendarDisconnectingConnection();

    Http::fake(function (Request $request) use ($connection) {
        if ($request->method() === 'GET') {
            // The reconnect races the purge: between the job's start-check
            // and its revoke+delete tail the row was reactivated with the
            // consent the user just gave (mass update bypasses model casts,
            // exactly the reconnect's updateOrCreate outcome on user_id).
            GoogleCalendarConnection::query()
                ->where('id', $connection->id)
                ->update(['status' => GoogleCalendarConnection::STATUS_ACTIVE]);

            return Http::response(['items' => []], 200);
        }

        return Http::response([], 204);
    });

    calendarDisconnectRun($connection);

    // The reactivated row survives with its fresh consent; no revoke ever
    // fires (that would kill the new grant), and the purge's deletions are
    // healed by the reconnect's backfill reconcile / the periodic sweep.
    expect(GoogleCalendarConnection::count())->toBe(1)
        ->and($connection->fresh()->status)->toBe(GoogleCalendarConnection::STATUS_ACTIVE);

    expect(collect(calendarDisconnectRequestSequence())
        ->contains(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke'))
        ->toBeFalse();

    expect($this->logRecord('calendar_sync.disconnect_aborted_reconnected'))->not->toBeNull()
        ->and($this->logRecord('calendar_sync.disconnected'))->toBeNull();
});

it('finishes the disconnect honestly when the grant dies mid-purge', function () {
    $connection = calendarDisconnectingConnection();

    Http::fake([
        'https://www.googleapis.com/calendar/v3/*' => Http::response([], 401),
    ]);

    calendarDisconnectRun($connection);

    // The row goes (the user asked to disconnect); what remained on the
    // calendar stays as unmanaged events — logged, never retried.
    expect(GoogleCalendarConnection::count())->toBe(0);

    expect($this->logRecord('calendar_sync.disconnect_grant_died'))->not->toBeNull()
        ->and($this->logRecord('calendar_sync.disconnect_purge_summary'))->toBeNull();

    expect(collect(calendarDisconnectRequestSequence())->contains(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/revoke'))->toBeFalse();
});

it('leaves the row honestly in Disconnecting on a transient failure so the retry ladder converges', function () {
    $connection = calendarDisconnectingConnection();

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(['items' => [calendarDisconnectEvent('doomed-event')]], 200);
        }

        return Http::response(['error' => 'backendError'], 500);
    });

    expect(fn () => calendarDisconnectRun($connection))->toThrow(CalendarSyncRequestFailed::class);

    expect(GoogleCalendarConnection::count())->toBe(1)
        ->and($connection->fresh()->status)->toBe(GoogleCalendarConnection::STATUS_DISCONNECTING);
});
