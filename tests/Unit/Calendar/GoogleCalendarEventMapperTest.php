<?php

use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Services\Calendar\Google\GoogleCalendarEventMapper;
use App\Services\Calendar\IcsBuilder;
use Carbon\Carbon;

//
// Pins the pure mapping layer of the managed Google sync (M083/S03-T01,
// D287/D299): the deterministic event-id family derived from the
// IcsBuilder UIDs — one identity per occurrence across mail attachments,
// webcal feed and Google Calendar — plus the RFC 3339 occurrence mapping
// into the user's timezone, locale-aware text resolution, and the cancel
// patch body.
//
// The golden constants freeze the id DERIVATION (SHA-256 → lowercase
// base32hex): changing it would orphan every already-synced Google event,
// so a change must be a deliberate, migration-planned act that updates
// these constants.
//

const GAME_UUID = '11111111-1111-1111-1111-111111111111';

const CAMPAIGN_UUID = '22222222-2222-2222-2222-222222222222';

const EVENT_UUID = '33333333-3333-3333-3333-333333333333';

const STANDALONE_GAME_GOLDEN = 'ok3iufmvr0pk6fk8sm5g58u9aidrk0mb6a68dbhimb9otu2okm0g';

const CAMPAIGN_SESSION_GOLDEN = 'ssth2r84q3sp0ana1ctkb5ntnggt165ggg53p23f7eftiksckai0';

const REGISTERED_EVENT_GOLDEN = '2isnjmqir8q4k3uequ98j2a43j1len3hqd9h3fvcr5hfpefrjc80';

function calendarEventMapper(): GoogleCalendarEventMapper
{
    return new GoogleCalendarEventMapper(new IcsBuilder);
}

/**
 * A scheduled game fixture with fixed identity + fixed dates (K014: exact
 * RFC 3339 expectations need a frozen clock, set by the timezone tests).
 */
function scheduledGame(array $attributes = []): Game
{
    return Game::factory()->create(array_merge([
        'id' => GAME_UUID,
        'date_time' => Carbon::parse('2026-06-15 18:00:00'),
        'expected_duration' => 3,
        'name' => ['en' => 'Dragonlance Session'],
    ], $attributes));
}

describe('deterministic event ids', function () {
    it('maps the same entity to the same id across reloads', function () {
        $mapper = calendarEventMapper();
        $game = scheduledGame();

        $reloaded = Game::query()->findOrFail($game->id);

        expect($mapper->eventId($reloaded))->toBe($mapper->eventId($game));
    });

    it('maps distinct entities to distinct ids', function () {
        $mapper = calendarEventMapper();

        $game = scheduledGame();
        $otherGame = Game::factory()->create(['date_time' => now()->addDays(3)]);
        $event = Event::factory()->create();

        $ids = [$mapper->eventId($game), $mapper->eventId($otherGame), $mapper->eventId($event)];

        expect(count(array_unique($ids)))->toBe(3);
    });

    it('derives from the IcsBuilder UID family — golden freeze (D287)', function () {
        $mapper = calendarEventMapper();

        // Standalone game: game-{id}@roundup.games.
        $standalone = scheduledGame();
        expect((new IcsBuilder)->uidFor($standalone))->toBe('game-'.GAME_UUID.'@roundup.games');
        expect($mapper->eventId($standalone))->toBe(STANDALONE_GAME_GOLDEN);

        // Registered event: event-{id}@roundup.games.
        $event = Event::factory()->create(['id' => EVENT_UUID]);
        expect((new IcsBuilder)->uidFor($event))->toBe('event-'.EVENT_UUID.'@roundup.games');
        expect($mapper->eventId($event))->toBe(REGISTERED_EVENT_GOLDEN);
    });

    it('derives campaign sessions from the campaign-{id}-session-{gameId} stem of the family', function () {
        $mapper = calendarEventMapper();

        $campaign = Campaign::factory()->create(['id' => CAMPAIGN_UUID]);
        $session = scheduledGame(['campaign_id' => $campaign->id]);

        // The SAME game id under the campaign-session stem must produce a
        // DIFFERENT id than the standalone stem — one identity per
        // occurrence, and updating one session never aliases its siblings
        // or the standalone game with the same numeric identity.
        expect((new IcsBuilder)->uidFor($session))
            ->toBe('campaign-'.CAMPAIGN_UUID.'-session-'.GAME_UUID.'@roundup.games');

        expect($mapper->eventId($session))
            ->toBe(CAMPAIGN_SESSION_GOLDEN)
            ->not->toBe(STANDALONE_GAME_GOLDEN);
    });

    it('emits only Google-legal event ids: base32hex charset, 5–1024 chars, no padding', function () {
        $mapper = calendarEventMapper();

        $game = Game::factory()->create(['date_time' => now()->addDays(2)]);
        $session = Game::factory()->create(['campaign_id' => Campaign::factory()->create()->id]);
        $event = Event::factory()->create();

        foreach ([$game, $session, $event] as $entity) {
            $id = $mapper->eventId($entity);

            expect($id)->toMatch('/^[0-9a-v]{5,1024}$/')
                ->and(strlen($id))->toBe(52)
                ->and($id)->not->toContain('=');
        }
    });
});

describe('occurrence mapping (RFC 3339 in the user timezone)', function () {
    it('renders timed games with exact offsets for the user timezone, summer and winter', function () {
        Carbon::setTestNow(Carbon::parse('2026-05-01 12:00:00', 'UTC'));

        $summer = calendarEventMapper()->toGoogleEvent(
            scheduledGame(['date_time' => Carbon::parse('2026-06-15 18:00:00')]),
            'en',
            'America/New_York',
        );

        expect($summer['start'])->toBe([
            'dateTime' => '2026-06-15T14:00:00-04:00', // EDT
            'timeZone' => 'America/New_York',
        ])->and($summer['end'])->toBe([
            'dateTime' => '2026-06-15T17:00:00-04:00', // 3h duration, same zone
            'timeZone' => 'America/New_York',
        ]);

        $winter = calendarEventMapper()->toGoogleEvent(
            scheduledGame(['id' => '44444444-4444-4444-4444-444444444444', 'date_time' => Carbon::parse('2026-01-15 18:00:00')]),
            'en',
            'America/New_York',
        );

        expect($winter['start']['dateTime'])->toBe('2026-01-15T13:00:00-05:00'); // EST
    });

    it('falls back to the community timezone when no user timezone is given', function () {
        Carbon::setTestNow(Carbon::parse('2026-05-01 12:00:00', 'UTC'));

        $payload = calendarEventMapper()->toGoogleEvent(
            scheduledGame(),
            'en',
            null,
        );

        expect($payload['start'])->toBe([
            'dateTime' => '2026-06-15T20:00:00+02:00', // Europe/Berlin CEST
            'timeZone' => 'Europe/Berlin',
        ]);
    });

    it('maps duration-less games to a zero-length event at the scheduled instant', function () {
        $payload = calendarEventMapper()->toGoogleEvent(
            scheduledGame(['expected_duration' => 0]),
            'en',
            'Europe/Berlin',
        );

        expect($payload['end'])->toBe($payload['start']);
    });

    it('maps whole-day events to all-day dates with an exclusive end', function () {
        $event = Event::factory()->create([
            'start_date' => '2026-06-20',
            'end_date' => '2026-06-21',
        ]);

        $payload = calendarEventMapper()->toGoogleEvent($event, 'en', 'America/New_York');

        expect($payload['start'])->toBe(['date' => '2026-06-20'])
            ->and($payload['end'])->toBe(['date' => '2026-06-22']); // exclusive, mirrors IcsBuilder DTEND

        $singleDay = Event::factory()->create([
            'start_date' => '2026-06-20',
            'end_date' => '2026-06-20', // end_date is NOT NULL — a single-day event stores start = end
        ]);

        $payload = calendarEventMapper()->toGoogleEvent($singleDay, 'en', null);

        expect($payload['end'])->toBe(['date' => '2026-06-21']);
    });
});

describe('event body mapping', function () {
    it('resolves summary locale-first with en fallback and links the entity page', function () {
        $game = scheduledGame(['name' => ['en' => 'English Name', 'de' => 'Deutscher Name']]);

        $german = calendarEventMapper()->toGoogleEvent($game, 'de', 'Europe/Berlin');
        $english = calendarEventMapper()->toGoogleEvent($game, 'en', 'Europe/Berlin');

        expect($german['summary'])->toBe('Deutscher Name')
            ->and($english['summary'])->toBe('English Name')
            ->and($english['description'])->toBe('English Name'.PHP_EOL.route('games.detail', $game, absolute: true));
    });

    it('falls back to the en name when the requested locale is missing', function () {
        $game = scheduledGame(['name' => ['en' => 'Only English']]);

        $payload = calendarEventMapper()->toGoogleEvent($game, 'de', 'Europe/Berlin');

        expect($payload['summary'])->toBe('Only English');
    });

    it('carries the linked location name and omits the key without one', function () {
        $withVenue = scheduledGame([
            'location_id' => Location::factory()->create(['name' => 'Café KopfAnschlag']),
        ]);

        $payload = calendarEventMapper()->toGoogleEvent($withVenue, 'en', 'Europe/Berlin');
        expect($payload['location'])->toBe('Café KopfAnschlag');

        $withoutVenue = scheduledGame(['id' => '55555555-5555-5555-5555-555555555555']);
        $payload = calendarEventMapper()->toGoogleEvent($withoutVenue, 'en', 'Europe/Berlin');
        expect($payload)->not->toHaveKey('location');
    });

    it('carries the event venue name and page link', function () {
        $event = Event::factory()->create([
            'id' => EVENT_UUID,
            'venue_name' => 'Messehalle',
            'start_date' => '2026-06-20',
        ]);

        $payload = calendarEventMapper()->toGoogleEvent($event, 'en', 'Europe/Berlin');

        expect($payload['location'])->toBe('Messehalle')
            ->and($payload['description'])->toBe($payload['summary'].PHP_EOL.route('events.detail', ['slug' => $event->slug], absolute: true));
    });

    it('stamps the deterministic id and the audit identity into every payload', function () {
        $mapper = calendarEventMapper();
        $game = scheduledGame();

        $payload = $mapper->toGoogleEvent($game, 'en', 'Europe/Berlin');

        expect($payload['id'])->toBe($mapper->eventId($game))
            // Explicit confirmed on every body — the resurrect invariant:
            // patch semantics keep unspecified fields, so status must ride
            // the payload or a cancelled event can never be restored.
            ->and($payload['status'])->toBe('confirmed')
            ->and($payload['extendedProperties'])->toBe([
                'private' => [
                    'roundup_uid' => 'game-'.GAME_UUID.'@roundup.games',
                    'roundup_type' => 'game',
                ],
            ]);

        $event = Event::factory()->create(['start_date' => '2026-06-20']);

        $payload = $mapper->toGoogleEvent($event, 'en', null);

        expect($payload['extendedProperties']['private']['roundup_type'])->toBe('event');
    });
});

describe('cancel payload', function () {
    it('is the cancelled patch body in Google lowercase status spelling', function () {
        expect(calendarEventMapper()->cancelPayload())->toBe(['status' => 'cancelled']);
    });
});

describe('unschedulable entities', function () {
    it('rejects a game without a date', function () {
        // date_time is NOT NULL at the storage layer, so the unschedulable
        // game only exists as an unpersisted model — exactly the shape a
        // late-arriving mapper call could observe.
        $game = Game::factory()->make(['date_time' => null]);

        expect(fn () => calendarEventMapper()->toGoogleEvent($game, 'en', null))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects an event without a start date', function () {
        $event = Event::factory()->make(['start_date' => null]);

        expect(fn () => calendarEventMapper()->toGoogleEvent($event, 'en', null))
            ->toThrow(InvalidArgumentException::class);
    });

    it('still assigns stable ids to unschedulable entities (identity precedes schedulability)', function () {
        $game = Game::factory()->make(['id' => GAME_UUID, 'date_time' => null]);
        $sameGame = Game::factory()->make(['id' => GAME_UUID, 'date_time' => null]);

        expect(calendarEventMapper()->eventId($game))
            ->toBe(calendarEventMapper()->eventId($sameGame))
            ->toBe(STANDALONE_GAME_GOLDEN);
    });
});
