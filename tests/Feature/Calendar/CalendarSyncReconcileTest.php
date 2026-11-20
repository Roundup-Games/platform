<?php

use App\Jobs\ReconcileGoogleCalendarConnection;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\Calendar\DesiredCalendarSet;
use App\Services\Calendar\Google\GoogleCalendarClient;
use App\Services\Calendar\Google\GoogleCalendarEventMapper;
use App\Services\Calendar\Google\GoogleCalendarSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Traits\CapturesLogRecords;

//
// Pins the managed-sync reconcile surface (M083/S03-T04): the per-
// connection drift-healing pass over the feed's commitment semantics
// (DesiredCalendarSet — the SAME query the iCal feed renders from),
// the Google-side inventory bounded to our privateExtendedProperty
// marker, ghost deletion (D299), cancel/resurrect convergence, source
// toggles subtracting from the desired set, the M082-mirrored failure
// bound, the sweep command's one-job-per-connection dispatch, and the
// connect-time initial backfill riding the same job.
//

uses(CapturesLogRecords::class);

// Log capture for the whole file: several tests assert the calendar_sync.*
// structured logs of a completed pass, and the capture must be in place
// before any factory/observer noise (assertions match exact messages).
beforeEach(function () {
    $this->captureLogRecords();
});

// ── Helpers ────────────────────────────────────────────

function calendarReconcileConnection(User $user, array $toggles = ['games' => true, 'campaign_sessions' => true, 'events' => true]): GoogleCalendarConnection
{
    return GoogleCalendarConnection::factory()->create([
        'user_id' => $user->id,
        'source_toggles' => $toggles,
    ]);
}

/**
 * A scheduled game hosted by the owner — the host rule is the cheapest
 * feed commitment to stage.
 *
 * @param  array<string, mixed>  $overrides
 */
function calendarReconcileGame(User $owner, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'owner_id' => $owner->id,
        'status' => 'scheduled',
        'date_time' => now()->addDays(7),
    ], $overrides));
}

/**
 * The deterministic Google event id for an entity — recomputed through
 * the mapper so the fixtures address the exact event the sync owns.
 */
function calendarReconcileEventId(Game|Event $entity): string
{
    return app(GoogleCalendarEventMapper::class)->eventId($entity);
}

/**
 * A Google-side event fixture carrying our private marker.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function calendarReconcileGoogleEvent(string $id, string $roundupType = 'game', array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'status' => 'confirmed',
        'start' => ['dateTime' => now()->addDays(3)->toRfc3339String()],
        'extendedProperties' => ['private' => [
            'roundup_uid' => 'game-fixture@roundup.games',
            'roundup_type' => $roundupType,
        ]],
    ], $overrides);
}

/**
 * The HTTP seam: GET listings answer per marker query (the fake matches
 * the URL the client builds — http_build_query encodes the marker value
 * with %3D), every mutation answers with the given status.
 *
 * @param  list<array<string, mixed>>  $gameListing
 * @param  list<array<string, mixed>>  $eventListing
 */
function calendarReconcileFake(array $gameListing = [], array $eventListing = [], int $mutationStatus = 200): void
{
    Http::fake(function (Request $request) use ($gameListing, $eventListing, $mutationStatus) {
        if ($request->method() === 'GET') {
            $items = str_contains($request->url(), 'roundup_type%3Dgame')
                ? $gameListing
                : $eventListing;

            return Http::response(['items' => $items], 200);
        }

        return Http::response(['status' => 'confirmed'], $mutationStatus);
    });
}

/**
 * Execute one reconcile pass synchronously — the pass under test, with
 * its real collaborators injected.
 */
function calendarReconcileRun(GoogleCalendarConnection $connection): void
{
    (new ReconcileGoogleCalendarConnection($connection->id))->handle(
        app(GoogleCalendarSyncService::class),
        app(GoogleCalendarEventMapper::class),
        app(DesiredCalendarSet::class),
        app(GoogleCalendarClient::class),
    );
}

/**
 * Recorded requests matching method + URL fragment.
 *
 * @return Collection<int, Request>
 */
function calendarReconcileRecorded(string $method, string $urlFragment)
{
    return collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => $request->method() === $method
            && str_contains($request->url(), $urlFragment));
}

/**
 * The Google Calendar events collection path every assertion addresses.
 */
function calendarReconcileEventsUrl(): string
{
    return 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
}

// ── Per-connection reconcile pass ──────────────────────

describe('reconcile diff', function () {
    it('backfills an empty calendar with every desired commitment and stamps last_synced_at', function () {
        $user = User::factory()->create();
        $connection = calendarReconcileConnection($user);

        $gameA = calendarReconcileGame($user);
        $gameB = calendarReconcileGame($user);
        $event = Event::factory()->create();
        EventRegistration::factory()->confirmed()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);

        $this->captureLogRecords();
        calendarReconcileFake();

        calendarReconcileRun($connection);

        // Three commitments, zero Google events → three inserts, each with
        // the deterministic id and sendUpdates=none structurally in the URL.
        $inserts = calendarReconcileRecorded('POST', calendarReconcileEventsUrl().'?sendUpdates=none');
        expect($inserts)->toHaveCount(3)
            ->and($inserts->pluck('id')->sort()->values()->all())
            ->toBe(collect([
                calendarReconcileEventId($event),
                calendarReconcileEventId($gameA),
                calendarReconcileEventId($gameB),
            ])->sort()->values()->all());

        $summary = $this->logRecord('calendar_sync.reconcile_summary');
        expect($summary['context'])->toMatchArray([
            'connection_id' => $connection->id,
            'user_id' => $user->id,
            'desired' => 3,
            'google_events' => 0,
            'inserted' => 3,
            'cancelled' => 0,
            'resurrected' => 0,
            'deleted' => 0,
            'failures' => 0,
        ])
            ->and($connection->fresh()->last_synced_at)->not->toBeNull();
    });

    it('deletes a ghost the desired set no longer contains', function () {
        $user = User::factory()->create();
        $connection = calendarReconcileConnection($user);

        $ghostId = 'g'.str_repeat('a', 51);
        calendarReconcileFake(gameListing: [calendarReconcileGoogleEvent($ghostId)]);

        $this->captureLogRecords();
        calendarReconcileRun($connection);

        $deletes = calendarReconcileRecorded('DELETE', calendarReconcileEventsUrl().'/'.$ghostId);
        expect($deletes)->toHaveCount(1)
            ->and($deletes->first()->url())->toEndWith('?sendUpdates=none')
            ->and($this->logRecord('calendar_sync.event_deleted')['context'])->toMatchArray([
                'connection_id' => $connection->id,
                'google_event_id' => $ghostId,
                'source' => 'reconcile_ghost',
            ])
            ->and($connection->fresh()->last_synced_at)->not->toBeNull();
    });

    it('cancels a desired-but-cancelled entity that Google still holds confirmed', function () {
        $user = User::factory()->create();
        $connection = calendarReconcileConnection($user);

        $cancelledGame = calendarReconcileGame($user, ['status' => 'canceled']);
        $eventId = calendarReconcileEventId($cancelledGame);

        calendarReconcileFake(gameListing: [calendarReconcileGoogleEvent($eventId)]);

        $this->captureLogRecords();
        calendarReconcileRun($connection);

        $patches = calendarReconcileRecorded('PATCH', calendarReconcileEventsUrl().'/'.$eventId);
        expect($patches)->toHaveCount(1)
            ->and($patches->first()->data()['status'])->toBe('cancelled')
            ->and($this->logRecord('calendar_sync.reconcile_summary')['context']['cancelled'])->toBe(1)
            ->and($this->logRecord('calendar_sync.event_cancelled'))->not->toBeNull();
    });

    it('never inserts an occurrence that is already cancelled (nothing to show)', function () {
        $user = User::factory()->create();
        $connection = calendarReconcileConnection($user);

        calendarReconcileGame($user, ['status' => 'canceled']);

        calendarReconcileFake();

        calendarReconcileRun($connection);

        expect(calendarReconcileRecorded('POST', calendarReconcileEventsUrl()))->toBeEmpty()
            ->and(calendarReconcileRecorded('PATCH', calendarReconcileEventsUrl()))->toBeEmpty()
            ->and($this->logRecord('calendar_sync.reconcile_summary')['context']['inserted'])->toBe(0);
    });

    it('resurrects an occurrence Google holds cancelled but the feed publishes live', function () {
        $user = User::factory()->create();
        $connection = calendarReconcileConnection($user);

        $game = calendarReconcileGame($user, ['name' => ['en' => 'Uncancelled Session']]);
        $eventId = calendarReconcileEventId($game);

        calendarReconcileFake(gameListing: [
            calendarReconcileGoogleEvent($eventId, overrides: ['status' => 'cancelled']),
        ]);

        calendarReconcileRun($connection);

        $patches = calendarReconcileRecorded('PATCH', calendarReconcileEventsUrl().'/'.$eventId);
        expect($patches)->toHaveCount(1)
            // The full live payload goes back INCLUDING an explicit
            // status=confirmed: patch semantics keep unspecified fields,
            // so the resurrect patch must write the status or the event
            // stays cancelled on Google forever.
            ->and($patches->first()->data())->toHaveKey('start')
            ->and($patches->first()->data()['status'])->toBe('confirmed')
            ->and($this->logRecord('calendar_sync.reconcile_summary')['context']['resurrected'])->toBe(1);
    });

    it('never touches user-owned events without the roundup marker, and lists through the marker filter', function () {
        $user = User::factory()->create();
        $connection = calendarReconcileConnection($user);

        $ghostId = 'g'.str_repeat('b', 51);
        $userEventId = 'u'.str_repeat('c', 51);

        calendarReconcileFake(gameListing: [
            calendarReconcileGoogleEvent($ghostId),
            // A user-owned event that somehow landed in the response — no
            // extendedProperties marker: the client-side guard must refuse
            // to treat it as ours even if Google returned it.
            ['id' => $userEventId, 'status' => 'confirmed', 'start' => ['dateTime' => now()->addDays(2)->toRfc3339String()]],
        ]);

        calendarReconcileRun($connection);

        expect(calendarReconcileRecorded('DELETE', calendarReconcileEventsUrl().'/'.$ghostId))->toHaveCount(1)
            ->and(calendarReconcileRecorded('DELETE', calendarReconcileEventsUrl().'/'.$userEventId))->toBeEmpty();

        // The inventory itself was requested through the marker filter —
        // user-owned events are structurally out of scope at Google's side.
        $listings = calendarReconcileRecorded('GET', calendarReconcileEventsUrl());
        expect($listings)->toHaveCount(2)
            ->and($listings->every(fn (Request $request): bool => str_contains($request->url(), 'privateExtendedProperty=roundup_type%3D')))->toBeTrue()
            ->and($listings->every(fn (Request $request): bool => str_contains($request->url(), 'timeMin=')))->toBeTrue();
    });

    it('subtracts disabled sources from the desired set — their events become ghosts', function () {
        $user = User::factory()->create();
        $connection = calendarReconcileConnection($user, ['games' => false, 'campaign_sessions' => false, 'events' => true]);

        $game = calendarReconcileGame($user);
        $gameId = calendarReconcileEventId($game);

        $event = Event::factory()->create();
        EventRegistration::factory()->confirmed()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);

        // Google still holds the (now undesired) game event.
        calendarReconcileFake(
            gameListing: [calendarReconcileGoogleEvent($gameId)],
            eventListing: [],
        );

        calendarReconcileRun($connection);

        $summary = $this->logRecord('calendar_sync.reconcile_summary')['context'];
        expect($summary['desired'])->toBe(1)
            ->and($summary['deleted'])->toBe(1)
            ->and($summary['inserted'])->toBe(1)
            // The game was never inserted — its source is off.
            ->and(calendarReconcileRecorded('POST', calendarReconcileEventsUrl())->pluck('id')->values()->all())
            ->toBe([calendarReconcileEventId($event)])
            ->and(calendarReconcileRecorded('DELETE', calendarReconcileEventsUrl().'/'.$gameId))->toHaveCount(1);
    });

    it('tolerates a ghost delete answering 404 — the goal state already holds', function () {
        $user = User::factory()->create();
        $connection = calendarReconcileConnection($user);

        $ghostId = 'g'.str_repeat('e', 51);

        Http::fake(function (Request $request) use ($ghostId) {
            if ($request->method() === 'DELETE' && str_contains($request->url(), '/'.$ghostId)) {
                return Http::response(['error' => 'notFound'], 404);
            }

            if ($request->method() === 'GET') {
                $items = str_contains($request->url(), 'roundup_type%3Dgame')
                    ? [calendarReconcileGoogleEvent($ghostId)]
                    : [];

                return Http::response(['items' => $items], 200);
            }

            return Http::response(['status' => 'confirmed'], 200);
        });

        calendarReconcileRun($connection);

        // A 404 on delete is convergence, not failure: the event is already
        // gone, the pass completes and stamps — mirroring the trigger job's
        // tolerance for at-least-once redeliveries.
        expect($this->logRecord('calendar_sync.reconcile_aborted'))->toBeNull()
            ->and($this->logRecord('calendar_sync.reconcile_summary')['context']['failures'])->toBe(0)
            ->and($connection->fresh()->last_synced_at)->not->toBeNull();
    });

    it('leaves past occurrences alone — calendar history is not drift', function () {
        $user = User::factory()->create();
        $connection = calendarReconcileConnection($user);

        $pastGhostId = 'g'.str_repeat('d', 51);
        calendarReconcileFake(gameListing: [
            calendarReconcileGoogleEvent($pastGhostId, overrides: [
                'start' => ['dateTime' => now()->subDay()->toRfc3339String()],
            ]),
        ]);

        calendarReconcileRun($connection);

        expect(calendarReconcileRecorded('DELETE', calendarReconcileEventsUrl()))->toBeEmpty()
            ->and($this->logRecord('calendar_sync.reconcile_summary')['context']['google_events'])->toBe(1);
    });

    it('aborts after the bounded failure count without stamping last_synced_at', function () {
        $user = User::factory()->create();
        $connection = calendarReconcileConnection($user);

        // Six desired games against a calendar whose mutations all fail —
        // the M082-mirrored dead-letter bound stops the pass at five.
        for ($i = 0; $i < 6; $i++) {
            calendarReconcileGame($user);
        }

        $this->captureLogRecords();
        calendarReconcileFake(mutationStatus: 500);

        calendarReconcileRun($connection);

        expect(calendarReconcileRecorded('POST', calendarReconcileEventsUrl()))->toHaveCount(5)
            ->and($this->logRecord('calendar_sync.reconcile_aborted')['context'])
            ->toMatchArray(['connection_id' => $connection->id, 'failures' => 5, 'status' => 500])
            ->and($this->logRecord('calendar_sync.reconcile_summary'))->toBeNull()
            ->and($connection->fresh()->last_synced_at)->toBeNull();
    });

    it('skips terminally when Google refuses the grant mid-pass', function () {
        $user = User::factory()->create();
        $connection = calendarReconcileConnection($user);

        calendarReconcileGame($user);

        // The listing itself answers 401 — the client's revocation
        // translation marks the row before the typed signal reaches us.
        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response([], 401),
        ]);

        $this->captureLogRecords();
        calendarReconcileRun($connection);

        expect($connection->fresh()->status)->toBe(GoogleCalendarConnection::STATUS_REVOKED)
            ->and($this->logRecord('calendar_sync.reconcile_skipped_revoked'))->not->toBeNull()
            ->and($this->logRecord('calendar_sync.revocation_detected'))->not->toBeNull()
            ->and($connection->fresh()->last_synced_at)->toBeNull();
    });
});

// ── Sweep command ──────────────────────────────────────

describe('sweep command', function () {
    it('dispatches one reconcile job per active connection and none for revoked ones', function () {
        Queue::fake();

        $activeA = calendarReconcileConnection(User::factory()->create());
        $activeB = calendarReconcileConnection(User::factory()->create());
        $revoked = GoogleCalendarConnection::factory()->revoked()->create([
            'user_id' => User::factory()->create()->id,
        ]);

        $this->artisan('calendar:sync-reconcile')
            ->expectsOutputToContain('2 connection pass(es) queued')
            ->assertSuccessful();

        Queue::assertPushed(ReconcileGoogleCalendarConnection::class, 2);
        Queue::assertPushed(ReconcileGoogleCalendarConnection::class, fn (ReconcileGoogleCalendarConnection $job) => $job->connectionId === $activeA->id);
        Queue::assertPushed(ReconcileGoogleCalendarConnection::class, fn (ReconcileGoogleCalendarConnection $job) => $job->connectionId === $activeB->id);
        Queue::assertNotPushed(ReconcileGoogleCalendarConnection::class, fn (ReconcileGoogleCalendarConnection $job) => $job->connectionId === $revoked->id);
    });
});

// ── Connect-time initial backfill ──────────────────────

describe('connect-time backfill', function () {
    it('queues the reconcile pass when the callback upserts the connection', function () {
        // Local copies of the CalendarLinkFlowTest round-trip helpers —
        // Pest loads helper files together only in full-suite runs, and
        // this file must stand alone when run filtered.
        config([
            'calendar_sync.enabled' => true,
            'calendar_sync.verification_mode' => 'test_user',
            'calendar_sync.test_user_allowlist' => ['reconcile-backfill@roundup.games'],
            'services.google.client_id' => 'calendar-test-client-id',
            'services.google.client_secret' => 'calendar-test-client-secret',
        ]);

        $user = User::factory()->create([
            'email' => 'reconcile-backfill@roundup.games',
            'profile_complete' => true,
        ]);

        Queue::fake();

        $connect = $this->actingAs($user)->get('/settings/calendar/google/connect');
        $location = (string) $connect->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $state = $params['state'];
        expect($state)->toBeString();

        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getEmail')->andReturn('reconcile-backfill@roundup.games');

        $driver = Mockery::mock(AbstractProvider::class);
        $driver->shouldReceive('redirectUrl')->andReturnSelf();
        $driver->shouldReceive('getAccessTokenResponse')->once()->andReturn([
            'access_token' => 'backfill-access-token',
            'refresh_token' => 'backfill-refresh-token',
            'expires_in' => 3600,
        ]);
        $driver->shouldReceive('userFromToken')->once()->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $response = $this->actingAs($user)
            ->get('/settings/calendar/google/callback?code=backfill-code&state='.urlencode($state));

        $response->assertRedirect('/en/settings');

        $connection = $user->googleCalendarConnection()->first();
        expect($connection)->not->toBeNull();

        // The SAME job the sweep dispatches — no separate backfill path.
        Queue::assertPushed(ReconcileGoogleCalendarConnection::class, fn (ReconcileGoogleCalendarConnection $job) => $job->connectionId === $connection->id);
    });
});
