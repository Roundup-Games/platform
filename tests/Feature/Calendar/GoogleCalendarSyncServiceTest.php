<?php

use App\Enums\CalendarSyncOutcome;
use App\Enums\EventStatus;
use App\Enums\GameStatus;
use App\Exceptions\CalendarSyncRequestFailed;
use App\Exceptions\CalendarSyncRevoked;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GoogleCalendarConnection;
use App\Models\Location;
use App\Models\User;
use App\Services\Calendar\Google\GoogleCalendarEventMapper;
use App\Services\Calendar\Google\GoogleCalendarSyncService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Traits\CapturesLogRecords;

//
// Pins the managed-sync operation layer (M083/S03-T02): idempotent
// ensure/update/cancel/delete against Google Calendar through the S02
// client — deterministic event id in every URL/body, sendUpdates=none
// structurally, 409-then-patch convergence (at-least-once execution),
// the IcsBuilder::publishStatus mirror (future-dated COMPLETED games
// cancel; live entities never get cancelled by a stale redelivery), the
// defense-in-depth source gate (no HTTP at all for a disabled source —
// except delete, which IS the disabled-source cleanup path, D299), the
// connection-user locale/timezone context, and the calendar_sync.*
// structured logs.
//

uses(CapturesLogRecords::class);

function calendarSyncService(): GoogleCalendarSyncService
{
    return app(GoogleCalendarSyncService::class);
}

/**
 * The deterministic id the mapper freezes for an entity — recomputed
 * independently here so the tests pin the service's URLs/bodies to the
 * UID-family identity without trusting the service under test to derive
 * it the same way.
 */
function calendarSyncExpectedEventId(Game|Event $entity): string
{
    return app(GoogleCalendarEventMapper::class)->eventId($entity);
}

/**
 * The connection user with NO captured display context by default — the
 * per-test overrides are the point (locale + timezone resolution).
 */
function calendarSyncUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'timezone' => null,
        'preferred_language' => null,
    ], $attributes));
}

function calendarSyncConnection(User $user, array $attributes = []): GoogleCalendarConnection
{
    // source_toggles is explicit here (not relying on the DB default):
    // the factory contract leaves the attribute unset in the in-memory
    // model, and the model's validating accessor throws on a missing
    // value — a connection read in production always carries the stored
    // JSON from the row it was loaded from.
    return GoogleCalendarConnection::factory()->create(array_merge([
        'user_id' => $user->id,
        'token' => 'sync-access-token',
        'source_toggles' => ['games' => true, 'campaign_sessions' => true, 'events' => true],
    ], $attributes));
}

/**
 * A scheduled game with a fixed occurrence — K014: exact RFC 3339
 * expectations need the fixed June date (CEST/EDT, no DST transition).
 */
function calendarSyncGame(array $attributes = []): Game
{
    return Game::factory()->create(array_merge([
        'date_time' => Carbon::parse('2026-06-15 18:00:00'),
        'expected_duration' => 3,
        'name' => ['en' => 'Dragonlance Session', 'de' => 'Drachenlanze-Sitzung'],
    ], $attributes));
}

function calendarSyncFakeSuccess(): void
{
    Http::fake([
        'https://www.googleapis.com/calendar/v3/*' => Http::response(['status' => 'confirmed'], 200),
    ]);
}

describe('ensureEvent insert shape + connection-user context', function () {
    it('inserts with the deterministic id, payload in the user locale/timezone, and sendUpdates=none — one call on a fresh token', function () {
        $user = calendarSyncUser([
            'timezone' => 'America/New_York',
            'preferred_language' => 'de',
        ]);
        $connection = calendarSyncConnection($user);
        $game = calendarSyncGame();

        $this->captureLogRecords();
        calendarSyncFakeSuccess();

        $outcome = calendarSyncService()->ensureEvent($connection, $game);

        $expectedId = calendarSyncExpectedEventId($game);

        expect($outcome)->toBe(CalendarSyncOutcome::Upserted);

        // Fresh stored token → exactly ONE Calendar API call, no token hop.
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token');

        Http::assertSent(function (Request $request) use ($expectedId): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events?sendUpdates=none'
                && $request->hasHeader('Authorization', 'Bearer sync-access-token')
                && $request['id'] === $expectedId
                // User context: de locale summary, New York rendering of 18:00 UTC (EDT −04:00).
                && $request['summary'] === 'Drachenlanze-Sitzung'
                && $request['start'] === ['dateTime' => '2026-06-15T14:00:00-04:00', 'timeZone' => 'America/New_York']
                && $request['end'] === ['dateTime' => '2026-06-15T17:00:00-04:00', 'timeZone' => 'America/New_York'];
        });

        $record = $this->logRecord('calendar_sync.event_upserted');

        expect($record)->not->toBeNull()
            ->and($record['level_name'])->toBe('INFO')
            ->and($record['context']['connection_id'])->toBe($connection->id)
            ->and($record['context']['user_id'])->toBe($user->id)
            ->and($record['context']['uid'])->toBe('game-'.$game->id.'@roundup.games')
            ->and($record['context']['google_event_id'])->toBe($expectedId)
            ->and($record['context']['source'])->toBe('games');
    });

    it('falls back to the community timezone and the first available locale when the user carries neither', function () {
        $connection = calendarSyncConnection(calendarSyncUser());
        $game = calendarSyncGame();

        calendarSyncFakeSuccess();

        expect(calendarSyncService()->ensureEvent($connection, $game))->toBe(CalendarSyncOutcome::Upserted);

        Http::assertSent(function (Request $request): bool {
            // No user timezone → Europe/Berlin (CEST +02:00); no preferred
            // language → 'en'.
            return $request['summary'] === 'Dragonlance Session'
                && $request['start'] === ['dateTime' => '2026-06-15T20:00:00+02:00', 'timeZone' => 'Europe/Berlin'];
        });
    });
});

describe('idempotent convergence', function () {
    it('converges onto a patch when the insert 409s on the deterministic id', function () {
        $connection = calendarSyncConnection(calendarSyncUser());
        $game = calendarSyncGame();

        Http::fake(function (Request $request) {
            return $request->method() === 'POST'
                ? Http::response(['error' => ['code' => 409, 'message' => 'alreadyExists']], 409)
                : Http::response(['status' => 'confirmed'], 200);
        });

        $outcome = calendarSyncService()->ensureEvent($connection, $game);

        $expectedId = calendarSyncExpectedEventId($game);

        expect($outcome)->toBe(CalendarSyncOutcome::Upserted);

        Http::assertSentCount(2);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['id'] === $expectedId);

        Http::assertSent(function (Request $request) use ($expectedId): bool {
            return $request->method() === 'PATCH'
                && $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events/'.$expectedId.'?sendUpdates=none'
                // The patch body carries the mutable fields only — the id
                // lives in the URL, not the body.
                && ! array_key_exists('id', $request->data())
                // Status rides the body: the patch must be able to restore
                // an event Google holds cancelled (patch semantics keep
                // unspecified fields — omission could never resurrect).
                && $request['status'] === 'confirmed'
                && $request['summary'] === 'Dragonlance Session'
                && $request['start'] === ['dateTime' => '2026-06-15T20:00:00+02:00', 'timeZone' => 'Europe/Berlin'];
        });
    });

    it('updateEvent patches the full current payload, never POSTing', function () {
        $connection = calendarSyncConnection(calendarSyncUser());
        $game = calendarSyncGame([
            // The "edit": venue linked + moved an hour later.
            'date_time' => Carbon::parse('2026-06-15 19:00:00'),
            'location_id' => Location::factory()->create(['name' => 'Café KopfAnschlag']),
        ]);

        calendarSyncFakeSuccess();

        $expectedId = calendarSyncExpectedEventId($game);

        expect(calendarSyncService()->updateEvent($connection, $game))->toBe(CalendarSyncOutcome::Upserted);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

        Http::assertSent(function (Request $request) use ($expectedId): bool {
            return $request->method() === 'PATCH'
                && $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events/'.$expectedId.'?sendUpdates=none'
                && ! array_key_exists('id', $request->data())
                && $request['location'] === 'Café KopfAnschlag'
                && $request['start'] === ['dateTime' => '2026-06-15T21:00:00+02:00', 'timeZone' => 'Europe/Berlin'];
        });
    });

    it('never inserts a confirmed event for an entity that maps to cancelled — ensure converges to the cancelled patch', function () {
        $connection = calendarSyncConnection(calendarSyncUser());
        $game = calendarSyncGame(['status' => GameStatus::Canceled]);

        $this->captureLogRecords();
        calendarSyncFakeSuccess();

        $outcome = calendarSyncService()->ensureEvent($connection, $game);

        expect($outcome)->toBe(CalendarSyncOutcome::Cancelled);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

        $expectedId = calendarSyncExpectedEventId($game);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events/'.$expectedId.'?sendUpdates=none'
            && $request->data() === ['status' => 'cancelled']);

        $record = $this->logRecord('calendar_sync.event_cancelled');

        expect($record)->not->toBeNull()
            ->and($record['context']['connection_id'])->toBe($connection->id)
            ->and($record['context']['uid'])->toBe('game-'.$game->id.'@roundup.games');

        // The same convergence protects updateEvent.
        expect(calendarSyncService()->updateEvent($connection, $game))->toBe(CalendarSyncOutcome::Cancelled);
    });
});

describe('cancel semantics (IcsBuilder::publishStatus mirror)', function () {
    it('patches status=cancelled for a canceled game', function () {
        $connection = calendarSyncConnection(calendarSyncUser());
        $game = calendarSyncGame(['status' => GameStatus::Canceled]);

        $this->captureLogRecords();
        calendarSyncFakeSuccess();

        expect(calendarSyncService()->cancelEvent($connection, $game))->toBe(CalendarSyncOutcome::Cancelled);

        $expectedId = calendarSyncExpectedEventId($game);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events/'.$expectedId.'?sendUpdates=none'
            && $request->data() === ['status' => 'cancelled']);

        $record = $this->logRecord('calendar_sync.event_cancelled');

        expect($record)->not->toBeNull()
            ->and($record['context']['connection_id'])->toBe($connection->id)
            ->and($record['context']['uid'])->toBe('game-'.$game->id.'@roundup.games')
            ->and($record['context']['google_event_id'])->toBe($expectedId)
            ->and($record['context']['source'])->toBe('games');
    });

    it('patches cancelled for a cancelled registered event', function () {
        $connection = calendarSyncConnection(calendarSyncUser());
        $event = Event::factory()->create(['status' => EventStatus::Cancelled]);

        calendarSyncFakeSuccess();

        expect(calendarSyncService()->cancelEvent($connection, $event))->toBe(CalendarSyncOutcome::Cancelled);

        $expectedId = calendarSyncExpectedEventId($event);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events/'.$expectedId.'?sendUpdates=none');
    });

    it('maps future-dated COMPLETED games to cancelled — the sixth-pass semantic', function () {
        Carbon::setTestNow(Carbon::parse('2026-05-01 12:00:00', 'UTC'));

        $connection = calendarSyncConnection(calendarSyncUser());
        $game = calendarSyncGame([
            'status' => GameStatus::Completed,
            'date_time' => Carbon::parse('2026-05-02 18:00:00'), // tomorrow — a session closed early
        ]);

        calendarSyncFakeSuccess();

        expect(calendarSyncService()->cancelEvent($connection, $game))
            ->toBe(CalendarSyncOutcome::Cancelled);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->data() === ['status' => 'cancelled']);
    });

    it('maps a completed game dated TODAY to cancelled (the feed window starts at community midnight) but not one from before the window', function () {
        Carbon::setTestNow(Carbon::parse('2026-05-01 12:00:00', 'UTC')); // 14:00 Berlin

        $connection = calendarSyncConnection(calendarSyncUser());
        $today = calendarSyncGame([
            'status' => GameStatus::Completed,
            'date_time' => Carbon::parse('2026-05-01 08:00:00', 'Europe/Berlin'), // inside the window
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response(['status' => 'cancelled'], 200),
        ]);

        expect(calendarSyncService()->cancelEvent($connection, $today))
            ->toBe(CalendarSyncOutcome::Cancelled);

        $before = calendarSyncGame([
            'status' => GameStatus::Completed,
            'date_time' => Carbon::parse('2026-04-29 18:00:00'), // outside the window — it took place
        ]);

        expect(calendarSyncService()->cancelEvent($connection, $before))
            ->toBe(CalendarSyncOutcome::SkippedNotCancellable);

        // One PATCH total: the before-window game never reached Google.
        Http::assertSentCount(1);
    });

    it('skips a cancel for a live entity — a stale redelivery must not cancel a confirmed event', function () {
        $connection = calendarSyncConnection(calendarSyncUser());
        $game = calendarSyncGame(); // Scheduled

        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response([], 500),
        ]);

        expect(calendarSyncService()->cancelEvent($connection, $game))
            ->toBe(CalendarSyncOutcome::SkippedNotCancellable);

        Http::assertNothingSent(); // the 500 fake proves the gate precedes HTTP
    });
});

describe('deleteEvent (D299 real deletion)', function () {
    it('deletes via events.delete with the deterministic id in the URL', function () {
        $connection = calendarSyncConnection(calendarSyncUser());
        $game = calendarSyncGame();

        $this->captureLogRecords();
        calendarSyncFakeSuccess();

        $expectedId = calendarSyncExpectedEventId($game);

        expect(calendarSyncService()->deleteEvent($connection, $game))->toBe(CalendarSyncOutcome::Deleted);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events/'.$expectedId.'?sendUpdates=none');

        $record = $this->logRecord('calendar_sync.event_deleted');

        expect($record)->not->toBeNull()
            ->and($record['context']['connection_id'])->toBe($connection->id)
            ->and($record['context']['uid'])->toBe('game-'.$game->id.'@roundup.games')
            ->and($record['context']['google_event_id'])->toBe($expectedId);
    });

    it('is NOT source-gated — deletion is the cleanup path for a disabled source', function () {
        $connection = calendarSyncConnection(calendarSyncUser(), [
            'source_toggles' => ['games' => false, 'campaign_sessions' => true, 'events' => true],
        ]);
        $game = calendarSyncGame();

        calendarSyncFakeSuccess();

        expect(calendarSyncService()->deleteEvent($connection, $game))->toBe(CalendarSyncOutcome::Deleted);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    });
});

describe('source gating (defense-in-depth)', function () {
    it('skips ensure/update/cancel with no HTTP at all when the entity source is disabled', function () {
        $connection = calendarSyncConnection(calendarSyncUser(), [
            'source_toggles' => ['games' => false, 'campaign_sessions' => true, 'events' => true],
        ]);
        $game = calendarSyncGame();
        $canceled = calendarSyncGame(['status' => GameStatus::Canceled]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response([], 500),
        ]);

        expect(calendarSyncService()->ensureEvent($connection, $game))->toBe(CalendarSyncOutcome::SkippedSourceDisabled)
            ->and(calendarSyncService()->updateEvent($connection, $game))->toBe(CalendarSyncOutcome::SkippedSourceDisabled)
            // A canceled entity gated the same way — cancel also refuses.
            ->and(calendarSyncService()->cancelEvent($connection, $canceled))
            ->toBe(CalendarSyncOutcome::SkippedSourceDisabled);

        Http::assertNothingSent();
    });

    it('maps campaign sessions to the campaign_sessions toggle, not games', function () {
        $connection = calendarSyncConnection(calendarSyncUser(), [
            'source_toggles' => ['games' => true, 'campaign_sessions' => false, 'events' => true],
        ]);
        $session = calendarSyncGame(['campaign_id' => Campaign::factory()->create()->id]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response([], 500),
        ]);

        expect(calendarSyncService()->ensureEvent($connection, $session))->toBe(CalendarSyncOutcome::SkippedSourceDisabled);

        Http::assertNothingSent();
    });

    it('syncs a standalone game on a connection whose campaign_sessions toggle is off — the gate is per-source', function () {
        $connection = calendarSyncConnection(calendarSyncUser(), [
            'source_toggles' => ['games' => true, 'campaign_sessions' => false, 'events' => true],
        ]);
        $standalone = calendarSyncGame();

        calendarSyncFakeSuccess();

        expect(calendarSyncService()->ensureEvent($connection, $standalone))->toBe(CalendarSyncOutcome::Upserted);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
    });

    it('maps registered events to the events toggle', function () {
        $connection = calendarSyncConnection(calendarSyncUser(), [
            'source_toggles' => ['games' => true, 'campaign_sessions' => true, 'events' => false],
        ]);
        $event = Event::factory()->create();

        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response([], 500),
        ]);

        expect(calendarSyncService()->ensureEvent($connection, $event))->toBe(CalendarSyncOutcome::SkippedSourceDisabled);

        Http::assertNothingSent();
    });
});

describe('failure propagation (S02 client contracts hold through the service)', function () {
    it('propagates CalendarSyncRevoked from a 401 and leaves the connection marked revoked', function () {
        $connection = calendarSyncConnection(calendarSyncUser());
        $game = calendarSyncGame();

        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response([
                'error' => ['code' => 401, 'message' => 'Invalid Credentials'],
            ], 401),
        ]);

        expect(fn () => calendarSyncService()->ensureEvent($connection, $game))
            ->toThrow(CalendarSyncRevoked::class);

        expect($connection->fresh()->status)->toBe(GoogleCalendarConnection::STATUS_REVOKED);

        // Short-circuit: one refused call, no retry at this layer.
        Http::assertSentCount(1);
    });

    it('propagates retryable CalendarSyncRequestFailed (a 500 insert is not converged, not swallowed)', function () {
        $connection = calendarSyncConnection(calendarSyncUser());
        $game = calendarSyncGame();

        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response([
                'error' => ['message' => 'Backend Error'],
            ], 500),
        ]);

        expect(fn () => calendarSyncService()->ensureEvent($connection, $game))
            ->toThrow(CalendarSyncRequestFailed::class);

        expect($connection->fresh()->status)->toBe(GoogleCalendarConnection::STATUS_ACTIVE);

        Http::assertSentCount(1); // no convergence patch after a 500
    });
});
