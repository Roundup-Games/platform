<?php

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ICal\ICalFeedRenderer;
use Carbon\Carbon;

/**
 * M057/S05/T03 — per-user tokenized iCal feed (decision D123), upgraded in
 * M081/S07/T02 onto the shared IcsBuilder (decision D287).
 *
 * The feed reuses the existing ShortLink tokenization (linkable=User,
 * purpose='ical') rather than a new token table. One valid VEVENT per
 * schedulable occurrence the member is committed to — games they host or are
 * an approved participant in, campaign sessions of their approved campaigns,
 * and confirmed event registrations — capped to a rolling 90-day window;
 * canceled games/events emit STATUS:CANCELLED; METHOD:PUBLISH; UIDs share the
 * iTIP attachment family (game-{id}@roundup.games …) so clients correlate
 * invitations with feed events. Expired/unknown tokens return 404.
 */
describe('iCal feed token resolution', function () {
    it('returns 404 for an unknown token code', function () {
        $this->get('/calendar/unknown-code')
            ->assertNotFound();
    });

    it('returns 404 for an expired token', function () {
        $user = User::factory()->create();
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $user->id,
            'purpose' => 'ical',
            'expires_at' => now()->subDay(),
        ]);

        $this->get("/calendar/{$link->code}")
            ->assertNotFound();
    });

    it('returns 404 for a revoked (soft-deleted) token', function () {
        $user = User::factory()->create();
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $user->id,
            'purpose' => 'ical',
        ]);
        $link->delete();

        $this->get("/calendar/{$link->code}")
            ->assertNotFound();
    });

    it('ignores a non-ical-purpose short link code', function () {
        $user = User::factory()->create();
        // A share-purpose link must NOT grant calendar access.
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $user->id,
            'purpose' => 'share',
        ]);

        $this->get("/calendar/{$link->code}")
            ->assertNotFound();
    });
});

describe('iCal feed — successful rendering', function () {
    it('returns 200 with text/calendar content-type for a valid token', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'expected_duration' => 3,
            'name' => ['en' => 'Dragonlance Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $response = $this->get("/calendar/{$link->code}");

        $response->assertStatus(200);
        expect($response->headers->get('Content-Type'))->toStartWith('text/calendar');
    });

    it('emits a valid VEVENT for an upcoming hosted game with correct DTSTART/DTEND', function () {
        $owner = User::factory()->create();
        $start = now()->addDays(7)->setTimeFromTimeString('18:00:00');
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => $start,
            'expected_duration' => 3,
            'name' => ['en' => 'Dragonlance Session'],
            'description' => ['en' => 'An epic adventure'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->toContain('BEGIN:VEVENT')
            ->and($body)->toContain('END:VEVENT')
            ->and($body)->toContain('SUMMARY:Dragonlance Session')
            ->and($body)->toContain('DESCRIPTION:An epic adventure')
            ->and($body)->toContain('UID:game-'.$game->id.'@roundup.games')
            ->and($body)->toContain('STATUS:CONFIRMED');

        // DTSTART present (date portion). The feed stamps the start time.
        $startDate = $start->format('Ymd');
        expect($body)->toContain('DTSTART');
        expect($body)->toContain($startDate);
    });

    it('emits absolute UTC DTSTART/DTEND for the stored instants (R089)', function () {
        $owner = User::factory()->create();
        // A Berlin evening session stored as its true UTC instant: 18:00 UTC
        // (CET +1) is 19:00 Berlin wall. Kept future-relative — the feed
        // excludes past games, and the original fixed date (2026-11-05)
        // rolled into the past and broke this pin.
        $start = Carbon::parse(now()->addDays(7)->toDateString().' 18:00:00', 'UTC');
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => $start,
            'expected_duration' => 2,
            'name' => ['en' => 'Absolute Time Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        // Absolute times (...Z): the exact stored instants — under the old
        // wall-time-under-UTC-label convention this emitted 19:00Z for a
        // session that actually starts at 18:00Z.
        // Derive from the fixture instant, not a hardcoded date — the old
        // absolute pin (20261105T180000Z) rolled over at midnight and broke
        // the suite for every day after it.
        expect($body)->toContain('DTSTART:'.$start->copy()->utc()->format('Ymd\THis\Z'))
            ->and($body)->toContain('DTEND:'.$start->copy()->addHours(2)->utc()->format('Ymd\THis\Z'));
    });

    it('renders a 23:30 UTC duration-0 session at its exact instant (unified IcsBuilder semantics)', function () {
        $owner = User::factory()->create();
        // 23:30 UTC is already the next day in the community zone (Berlin,
        // UTC+1 in November). The pre-T02 eluceo renderer folded duration-0
        // games into an all-day VEVENT anchored to the community date (R089);
        // the unified IcsBuilder renders every game as a timed VEVENT at its
        // true UTC instant (no DTEND when no duration is known), so the exact
        // instant — not a reinterpreted wall date — is what ships. Anchored
        // relative to today: the feed renders from the community's start-of-day
        // forward, so a fixed date would age out of the window and break the
        // suite. Any positive community offset crosses midnight from 23:30
        // UTC, so the edge holds on every run date, DST or not.
        $start = now()->utc()->addDays(7)->setTime(23, 30);

        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => $start,
            'expected_duration' => 0,
            'name' => ['en' => 'Late Edge Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)
            ->toContain('DTSTART:'.$start->copy()->utc()->format('Ymd\\THis\\Z'))
            ->not->toContain('DTEND:')
            ->not->toContain('VALUE=DATE');
    });

    it('emits STATUS:CANCELLED for a canceled game', function () {
        $owner = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'status' => GameStatus::Canceled->value,
            'name' => ['en' => 'Canceled Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->toContain('STATUS:CANCELLED');
    });

    it('emits STATUS:CANCELLED for a future-dated game marked completed early', function () {
        // A host closing a still-future session early (GameStatus::Completed
        // inside the forward-only window) means the session will NOT take
        // place — the feed must revoke it on subscribers' calendars, not
        // assert a phantom CONFIRMED event forever (M081 sixth-pass review).
        $owner = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'status' => GameStatus::Completed->value,
            'name' => ['en' => 'Closed Early Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)
            ->toContain('STATUS:CANCELLED')
            ->not->toContain('STATUS:CONFIRMED');
    });

    it('renders a duration-0 game as a timed DTSTART without DTEND', function () {
        // The games.expected_duration column is NOT NULL; the unified
        // IcsBuilder treats a non-positive duration as "duration unknown" —
        // a timed DTSTART with no DTEND (RFC 5545 permits the omission), not
        // the pre-T02 all-day fallback.
        $owner = User::factory()->create();
        $start = Carbon::parse(now()->addDays(5)->toDateString().' 18:00:00', 'UTC');
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => $start,
            'expected_duration' => 0,
            'name' => ['en' => 'No Duration Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->toContain('BEGIN:VEVENT')
            ->and($body)->toContain('DTSTART:'.$start->copy()->utc()->format('Ymd\\THis\\Z'))
            ->and($body)->not->toContain('DTEND:');
    });

    it('includes a game the user is an approved participant in (not hosting)', function () {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(10),
            'name' => ['en' => 'Player Session'],
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $player->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->toContain('SUMMARY:Player Session');
    });

    it('excludes games where the user is only a non-approved participant', function () {
        $host = User::factory()->create();
        $pending = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(10),
            'name' => ['en' => 'Pending Only Session'],
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $pending->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $pending->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->not->toContain('Pending Only Session');
    });

    it('excludes past games', function () {
        $owner = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->subDays(3),
            'name' => ['en' => 'Past Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->not->toContain('Past Session');
    });
});

describe('iCal feed locale resolution', function () {
    it('resolves the summary to the user preferred language when present', function () {
        $owner = User::factory()->create(['preferred_language' => 'de']);
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'name' => ['en' => 'English Title', 'de' => 'German Title'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->toContain('SUMMARY:German Title');
    });

    it('falls back to the first available locale when user has no preferred language', function () {
        $owner = User::factory()->create(['preferred_language' => null]);
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'name' => ['en' => 'English Title'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)->toContain('SUMMARY:English Title');
    });
});

describe('ICalFeedRenderer (isolated unit)', function () {
    it('produces a well-formed PUBLISH envelope via the shared IcsBuilder', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'expected_duration' => 2,
            'name' => ['en' => 'Unit Test Game'],
        ]);

        $output = app(ICalFeedRenderer::class)->render(
            collect([$game]),
            'en',
        );

        expect($output)->toContain('BEGIN:VCALENDAR')
            ->and($output)->toContain('END:VCALENDAR')
            ->and($output)->toContain('PRODID:-//Roundup//Roundup Calendar//EN')
            ->and($output)->toContain('BEGIN:VEVENT')
            ->and($output)->toContain('SUMMARY:Unit Test Game');
    });
});

describe('iCal feed — PUBLISH upgrade (D287/S07/T02)', function () {
    it('publishes with METHOD:PUBLISH and the shared UID family, without iTIP-only properties', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(7),
            'expected_duration' => 3,
            'name' => ['en' => 'Publish Envelope Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        // PUBLISH envelope + the UID family shared with the iTIP mail
        // attachments (game-{id}@roundup.games) — no invitee on a PUBLISH
        // and no lifecycle SEQUENCE, but ORGANIZER IS required on published
        // VEVENTs (RFC 5546 §3.6.1) and binds to the platform no-reply
        // identity — never a user email in a token-authenticated feed.
        expect($body)
            ->toContain('METHOD:PUBLISH')
            ->toContain('VERSION:2.0')
            ->toContain('UID:game-'.$game->id.'@roundup.games')
            ->toContain('URL:'.route('games.detail', $game, absolute: true))
            ->toContain('ORGANIZER;CN=Roundup:mailto:'.config('mail.from.address'))
            ->not->toContain($owner->email)
            ->not->toContain('ATTENDEE')
            ->not->toContain('SEQUENCE');
    });

    it('includes a confirmed event registration as an all-day VEVENT with exclusive DTEND', function () {
        $user = User::factory()->create();
        $start = now()->addDays(14)->startOfDay();
        $event = Event::factory()->create([
            'name' => ['en' => 'Roundup Gala'],
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
        ]);
        EventRegistration::factory()->confirmed()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $user->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)
            ->toContain('UID:event-'.$event->id.'@roundup.games')
            ->toContain('SUMMARY:Roundup Gala')
            ->toContain('DTSTART;VALUE=DATE:'.$start->format('Ymd'))
            // Exclusive DTEND (RFC 5545 §3.6.1): the gala ends on the second
            // day, so the VEVENT carries the third.
            ->toContain('DTEND;VALUE=DATE:'.$start->copy()->addDays(2)->format('Ymd'));
    });

    it('excludes pending and cancelled event registrations', function () {
        $user = User::factory()->create();
        $start = now()->addDays(14)->startOfDay();
        $pending = Event::factory()->create([
            'name' => ['en' => 'Pending Gala'],
            'start_date' => $start->toDateString(),
        ]);
        EventRegistration::factory()->create([
            'user_id' => $user->id,
            'event_id' => $pending->id,
            'status' => 'pending',
        ]);
        $cancelled = Event::factory()->create([
            'name' => ['en' => 'Cancelled Registration Gala'],
            'start_date' => $start->toDateString(),
        ]);
        EventRegistration::factory()->cancelled()->create([
            'user_id' => $user->id,
            'event_id' => $cancelled->id,
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $user->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)
            ->not->toContain('Pending Gala')
            ->not->toContain('Cancelled Registration Gala');
    });

    it('emits STATUS:CANCELLED for a cancelled event the user still holds a confirmed registration for', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'name' => ['en' => 'Called Off Gala'],
            'start_date' => now()->addDays(14)->toDateString(),
            'status' => 'cancelled',
        ]);
        EventRegistration::factory()->confirmed()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $user->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)
            ->toContain('UID:event-'.$event->id.'@roundup.games')
            ->toContain('STATUS:CANCELLED');
    });

    it('includes campaign sessions for approved campaign members even without a game-participant row', function () {
        $gm = User::factory()->create();
        $member = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $member->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        // Session hosted by the GM; the member has NO game_participants row
        // — campaign membership alone must land it on their calendar.
        $session = Game::factory()->create([
            'owner_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'date_time' => now()->addDays(7),
            'name' => ['en' => 'Chapter One'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $member->id,
            'purpose' => 'ical',
        ]);

        $body = str_replace("\r\n ", '', $this->get("/calendar/{$link->code}")->getContent());

        // Session VEVENTs address their own UID so a reschedule of one
        // session never touches its siblings. The UID line exceeds 75
        // octets and ships RFC-folded — unfold first to assert the logical
        // content line (same pattern as the T01 attachment suite).
        expect($body)
            ->toContain('UID:campaign-'.$campaign->id.'-session-'.$session->id.'@roundup.games')
            ->toContain('SUMMARY:Chapter One');
    });

    it('excludes standalone games where the user is only waitlisted or benched', function () {
        $host = User::factory()->create();
        $overflow = User::factory()->create();
        $waitlisted = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(10),
            'name' => ['en' => 'Waitlisted Only Session'],
        ]);
        GameParticipant::create([
            'game_id' => $waitlisted->id,
            'user_id' => $overflow->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
        ]);
        $benched = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(11),
            'name' => ['en' => 'Benched Only Session'],
        ]);
        GameParticipant::create([
            'game_id' => $benched->id,
            'user_id' => $overflow->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched->value,
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $overflow->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)
            ->not->toContain('Waitlisted Only Session')
            ->not->toContain('Benched Only Session');
    });

    it('caps the feed to a rolling 90-day window', function () {
        $owner = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(45),
            'name' => ['en' => 'In Window Session'],
        ]);
        Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(120),
            'name' => ['en' => 'Far Future Session'],
        ]);
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $owner->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)
            ->toContain('In Window Session')
            ->not->toContain('Far Future Session');
    });

    it('excludes community-yesterday events from the window start', function () {
        // The feed window opens at community (Berlin) midnight converted to
        // UTC — 23:00Z the prior day in winter. Comparing event start_date
        // against that instant's UTC DATE admitted community-yesterday's
        // events on every render (M081 review); the lower bound must be the
        // COMMUNITY calendar date.
        $user = User::factory()->create();
        $yesterday = community_now()->subDay()->toDateString();
        $today = community_now()->toDateString();

        $yesterdaysEvent = Event::factory()->create([
            'name' => ['en' => 'Yesterday Gala'],
            'start_date' => $yesterday,
        ]);
        EventRegistration::factory()->confirmed()->create([
            'user_id' => $user->id,
            'event_id' => $yesterdaysEvent->id,
        ]);

        $todaysEvent = Event::factory()->create([
            'name' => ['en' => 'Today Gala'],
            'start_date' => $today,
        ]);
        EventRegistration::factory()->confirmed()->create([
            'user_id' => $user->id,
            'event_id' => $todaysEvent->id,
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $user->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)
            ->toContain('Today Gala')
            ->not->toContain('Yesterday Gala');
    });

    it('returns a valid empty calendar when the window is empty', function () {
        $user = User::factory()->create();
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $user->id,
            'purpose' => 'ical',
        ]);

        $body = $this->get("/calendar/{$link->code}")->getContent();

        expect($body)
            ->toContain('BEGIN:VCALENDAR')
            ->toContain('METHOD:PUBLISH')
            ->toContain('END:VCALENDAR')
            ->not->toContain('BEGIN:VEVENT');
    });

    it('serves the feed with private token-scoped cache headers', function () {
        $user = User::factory()->create();
        $link = ShortLink::factory()->create([
            'linkable_type' => (new User)->getMorphClass(),
            'linkable_id' => $user->id,
            'purpose' => 'ical',
        ]);

        $response = $this->get("/calendar/{$link->code}");

        $response->assertStatus(200);
        expect($response->headers->get('Content-Type'))->toStartWith('text/calendar');
        // Directive-level pins (Symfony reorders directives on read-back):
        // private is load-bearing — per-user, token-authenticated content
        // must never sit in a shared cache — and public must never sneak
        // back in via the edge-cache middleware; 1h freshness per D287
        // since calendar clients poll lazily on their own schedule anyway.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        expect($cacheControl)
            ->toContain('private')
            ->toContain('max-age=3600')
            ->not->toContain('public');
    });
});

it('includes sessions on the +90 boundary day (inclusive window, mirroring the events leg)', function () {
    $owner = User::factory()->create();
    Game::factory()->create([
        'owner_id' => $owner->id,
        'date_time' => now()->addDays(90)->setTime(19, 0),
        'name' => ['en' => 'Boundary Day Session'],
    ]);
    $link = ShortLink::factory()->create([
        'linkable_type' => (new User)->getMorphClass(),
        'linkable_id' => $owner->id,
        'purpose' => 'ical',
    ]);

    $body = $this->get("/calendar/{$link->code}")->getContent();

    expect($body)->toContain('Boundary Day Session');
});
