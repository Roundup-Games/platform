<?php

use App\Enums\NotificationCategory;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\User;
use App\Notifications\ApplicationApproved;
use App\Notifications\EntityCancelled;
use App\Notifications\EntityInvitation;
use App\Notifications\EntityUpdated;
use App\Notifications\EventCancelled;
use App\Notifications\EventRegistrationConfirmed;
use App\Notifications\SeatDemoted;
use App\Notifications\SessionAddedToCampaign;
use App\Notifications\SessionReminder;
use App\Notifications\WaitlistPromoted;
use App\Services\Calendar\IcsBuilder;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Part\DataPart;

// ══════════════════════════════════════════════════════
// iTIP calendar lifecycle attachments (D287, M081/S07).
//
// Every notification mail that carries calendar semantics
// attaches a hand-rolled RFC 5545/5546 ICS part: an initial
// or updated METHOD:REQUEST for the recipient (ATTENDEE +
// ORGANIZER, RSVP=FALSE — without an ATTENDEE Outlook
// ignores the invitation), and a METHOD:CANCEL +
// STATUS:CANCELLED twin with an advanced SEQUENCE when the
// event is revoked. The method matrix and privacy rules are
// modelled on LibreBooking PR #1790: per-email-type methods,
// data-driven assertions, and a privacy matrix (entity name
// + roundup link only — never a roster, never an address).
//
// Assertions run against the real dispatch surface
// (NotificationService + sync queue, array mailer) and
// capture the rendered Symfony message via MessageSending —
// the same pattern as MailCategoryTagTest.
// ══════════════════════════════════════════════════════

/**
 * The rendered .ics attachment of the n-th captured mail (null when the
 * mail carries no calendar part) — matching by filename suffix because
 * the unsubscribe flow attaches nothing else.
 */
function icsAttachment(int $mailIndex): ?DataPart
{
    $message = test()->capturedMails[$mailIndex];

    return collect($message->getAttachments())
        ->first(fn (DataPart $part) => str_ends_with((string) $part->getFilename(), '.ics'));
}

/**
 * The decoded ICS body of an attachment part. bodyToString() returns the
 * encoded wire body (DataPart defaults to base64); a base64 payload can
 * never contain the ":" of BEGIN:VCALENDAR, so the probe is unambiguous.
 */
function icsContent(DataPart $part): string
{
    $raw = $part->bodyToString();

    return str_contains($raw, 'BEGIN:VCALENDAR') ? $raw : base64_decode($raw);
}

/**
 * The unfolded logical content lines: RFC 5545 continuations (CRLF + a
 * single leading space) are rejoined, so a value that spans a fold — a
 * 53-character ATTENDEE mailto or a campaign-session UID — asserts as
 * one string again. Physical-line checks (CRLF endings, the 75-octet
 * limit) always run against the folded body instead.
 */
function icsUnfold(string $folded): string
{
    return str_replace("\r\n ", '', $folded);
}

beforeEach(function () {
    // Capture the Symfony message each notification mail produces.
    $this->capturedMails = collect();
    EventFacade::listen(MessageSending::class, function (MessageSending $event): void {
        $this->capturedMails->push($event->message);
    });
});

it('attaches an initial iTIP REQUEST for a game invitation with the full RFC 5545 shape', function () {
    $recipient = User::factory()->create(['name' => 'Ada Attendee']);
    $host = User::factory()->create(['name' => 'Hosty McHost']);
    $game = Game::factory()->create([
        'name' => ['en' => 'Dinner, Dragons; Escape'],
        'owner_id' => $host->id,
        'expected_duration' => 3.5,
    ]);

    app(NotificationService::class)->send(
        $recipient,
        new EntityInvitation($game, $host),
        NotificationCategory::GameInvitation,
    );

    expect($this->capturedMails)->toHaveCount(1);

    $ics = icsAttachment(0);
    expect($ics)->not->toBeNull()
        ->and($ics->getFilename())->toBe('invite-game.ics')
        ->and($ics->getPreparedHeaders()->toString())->toContain('method=REQUEST');

    $raw = icsContent($ics);
    $content = icsUnfold($raw);

    // VCALENDAR envelope + VEVENT identity.
    expect($content)
        ->toContain('BEGIN:VCALENDAR')
        ->toContain('PRODID:-//Roundup//Roundup Calendar//EN')
        ->toContain('VERSION:2.0')
        ->toContain('CALSCALE:GREGORIAN')
        ->toContain('METHOD:REQUEST')
        ->toContain('UID:game-'.$game->id.'@roundup.games')
        ->toContain('SEQUENCE:0')
        ->toContain('STATUS:CONFIRMED')
        // REQUEST without ATTENDEE is ignored by Outlook — RSVP=FALSE
        // (RFC 5545 §3.2.17: rsvpparam = "RSVP" "=" ("TRUE" / "FALSE"))
        // because roundup collects confirmations itself.
        ->toContain('ATTENDEE;RSVP=FALSE:mailto:'.$recipient->email)
        ->toContain('ORGANIZER;CN=Hosty McHost:mailto:'.$host->email)
        // UTC timestamps with the Z suffix; DTEND from expected_duration.
        ->toMatch('/^DTSTAMP:\d{8}T\d{6}Z\r?$/m')
        ->toMatch('/^DTSTART:\d{8}T\d{6}Z\r?$/m')
        ->toMatch('/^DTEND:\d{8}T\d{6}Z\r?$/m')
        // RFC 5545 §3.3.11 TEXT escaping of comma + semicolon.
        ->toContain('SUMMARY:Dinner\, Dragons\; Escape')
        ->toContain('END:VCALENDAR');

    // CRLF line endings throughout — a bare LF is the classic silent
    // killer of hand-rolled ICS (RFC 5545 §3.1).
    expect(substr_count($raw, "\r\n"))->toBe(substr_count($raw, "\n"));
});

it('folds every physical line to at most 75 octets', function () {
    $recipient = User::factory()->create();
    $host = User::factory()->create(['name' => str_repeat('H', 60)]);
    $game = Game::factory()->create([
        'name' => ['en' => 'The Extraordinarily Long Named Game About Folding Very Long Calendar Summary Lines'],
        'owner_id' => $host->id,
    ]);

    app(NotificationService::class)->send(
        $recipient,
        new EntityInvitation($game, $host),
        NotificationCategory::GameInvitation,
    );

    $raw = icsContent(icsAttachment(0));

    // The long SUMMARY folds mid-phrase: the logical line still carries
    // it whole, while every physical line stays within the 75-octet cap.
    expect(icsUnfold($raw))->toContain('Folding Very Long Calendar');
    $withinLimit = collect(explode("\r\n", $raw))
        ->every(fn (string $line) => strlen($line) <= 75);
    expect($withinLimit)->toBeTrue();
});

it('carries the venue name but never its address in LOCATION', function () {
    $recipient = User::factory()->create();
    $host = User::factory()->create();
    $location = Location::factory()->create([
        'name' => 'Café, Kosmos; Berlin',
        'address' => 'Geheime Gasse 1',
    ]);
    $game = Game::factory()->create(['owner_id' => $host->id, 'location_id' => $location->id]);

    app(NotificationService::class)->send(
        $recipient,
        new EntityInvitation($game, $host),
        NotificationCategory::GameInvitation,
    );

    $content = icsContent(icsAttachment(0));

    expect($content)
        ->toContain('LOCATION:Café\, Kosmos\; Berlin')
        ->not->toContain('Geheime Gasse 1')
        ->not->toContain($location->address);
});

it('strips CR from TEXT values so the CRLF line protocol stays intact', function () {
    // A game name is validated only as string|max:255 — a submitted CR
    // (bare or as CRLF) would otherwise land raw mid-line and corrupt the
    // RFC 5545 content-line protocol for strict parsers.
    $recipient = User::factory()->create();
    $host = User::factory()->create();
    $game = Game::factory()->create([
        'name' => ['en' => "Line\r\nBreak\rName"],
        'owner_id' => $host->id,
    ]);

    app(NotificationService::class)->send(
        $recipient,
        new EntityInvitation($game, $host),
        NotificationCategory::GameInvitation,
    );

    $raw = icsContent(icsAttachment(0));

    // CRLF survives ONLY as the line terminator: normalize the
    // terminators away and no CR may remain; embedded newlines (CRLF or
    // bare LF in the name) must map to the literal "\\n" escape.
    $content = str_replace("\r\n", "\n", icsUnfold($raw));
    expect($content)
        ->toContain('SUMMARY:Line\\nBreakName')
        ->not->toContain("\r")
        ->and(substr_count($raw, "\r\n"))->toBe(substr_count($raw, "\n"));
});

it('strips newlines from the ORGANIZER CN param so the line protocol stays intact', function () {
    // Display names pass ValidUserName, but its sanitizer keeps \s — which
    // includes \n — so "Host\nName" is a legal stored name. A raw newline in
    // the ORGANIZER;CN param would break the RFC 5545 content-line protocol
    // (CTL is excluded from param values) exactly like a CR in TEXT does.
    $recipient = User::factory()->create();
    $host = User::factory()->create(['name' => "Host\nName"]);
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'date_time' => now()->addDays(2),
    ]);

    app(NotificationService::class)->send(
        $recipient,
        new EntityInvitation($game, $host),
        NotificationCategory::GameInvitation,
    );

    $raw = icsContent(icsAttachment(0));

    expect(icsUnfold($raw))->toContain('ORGANIZER;CN=HostName:mailto:'.$host->email)
        // The ORGANIZER line itself must carry exactly one CRLF terminator:
        // no bare-LF mid-line may survive the param escape.
        ->and(substr_count($raw, "\r\n"))->toBe(substr_count($raw, "\n"));
});

it('attaches no calendar part for a bare campaign invitation', function () {
    $recipient = User::factory()->create();
    $inviter = User::factory()->create();
    $campaign = Campaign::factory()->create();

    app(NotificationService::class)->send(
        $recipient,
        new EntityInvitation($campaign, $inviter),
        NotificationCategory::CampaignInvitation,
    );

    expect($this->capturedMails)->toHaveCount(1)
        ->and(icsAttachment(0))->toBeNull();
});

it('addresses each campaign session as its own VEVENT UID', function () {
    // The session-added category defaults to database+push (no mail);
    // opt this recipient in so the ICS part is actually rendered.
    $recipient = User::factory()->create([
        'notification_settings' => ['session_added_to_campaign' => ['mail' => true]],
    ]);
    $campaign = Campaign::factory()->create();
    $session = Game::factory()->create(['campaign_id' => $campaign->id]);

    app(NotificationService::class)->send(
        $recipient,
        new SessionAddedToCampaign($session, $campaign),
        NotificationCategory::SessionAddedToCampaign,
    );

    $content = icsUnfold(icsContent(icsAttachment(0)));

    expect($content)
        ->toContain('METHOD:REQUEST')
        ->toContain('UID:campaign-'.$campaign->id.'-session-'.$session->id.'@roundup.games');
});

it('attaches a REQUEST for application approvals and waitlist promotions', function () {
    $recipient = User::factory()->create();
    $approver = User::factory()->create();
    $approvedGame = Game::factory()->create();

    app(NotificationService::class)->send(
        $recipient,
        new ApplicationApproved($approvedGame, 'game', $approver),
        NotificationCategory::ApplicationApproved,
    );

    expect(icsContent(icsAttachment(0)))
        ->toContain('METHOD:REQUEST')
        ->toContain('UID:game-'.$approvedGame->id.'@roundup.games');

    $this->capturedMails = collect();
    $promotedGame = Game::factory()->create();

    app(NotificationService::class)->send(
        $recipient,
        new WaitlistPromoted($promotedGame, Carbon::parse('next friday 18:00')),
        NotificationCategory::WaitlistPromoted,
    );

    expect(icsContent(icsAttachment(0)))
        ->toContain('METHOD:REQUEST')
        ->toContain('UID:game-'.$promotedGame->id.'@roundup.games');
});

it('attaches a REQUEST for event registrations as an all-day VEVENT with exclusive DTEND', function () {
    $recipient = User::factory()->create();
    $event = Event::factory()->create([
        'name' => ['en' => 'Winter Gala'],
        'venue_name' => 'Roundup Hall',
        'start_date' => '2026-12-05',
        'end_date' => '2026-12-06',
    ]);
    $registration = EventRegistration::factory()->confirmed()->create([
        'user_id' => $recipient->id,
        'event_id' => $event->id,
    ]);

    app(NotificationService::class)->send(
        $recipient,
        new EventRegistrationConfirmed($registration),
        NotificationCategory::EventRegistration,
    );

    $ics = icsAttachment(0);
    expect($ics)->not->toBeNull()
        ->and($ics->getFilename())->toBe('invite-event.ics');

    expect(icsContent($ics))
        ->toContain('METHOD:REQUEST')
        ->toContain('UID:event-'.$event->id.'@roundup.games')
        ->toContain('DTSTART;VALUE=DATE:20261205')
        // All-day DTEND is exclusive (RFC 5545 §3.6.1): the gala ends on
        // the 6th, so the VEVENT carries the 7th.
        ->toContain('DTEND;VALUE=DATE:20261207')
        ->toContain('LOCATION:Roundup Hall');
});

it('re-issues a same-UID REQUEST with a bumped SEQUENCE only for calendar-relevant changes', function () {
    // Entity-update mails are opt-in per category; enable them so the
    // update lifecycle is actually rendered for this recipient.
    $recipient = User::factory()->create([
        'notification_settings' => [
            'game_invitation' => ['mail' => true],
            'game_updated' => ['mail' => true],
        ],
    ]);
    $host = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $host->id]);

    app(NotificationService::class)->send(
        $recipient,
        new EntityInvitation($game, $host),
        NotificationCategory::GameInvitation,
    );
    expect(icsContent(icsAttachment(0)))->toContain('SEQUENCE:0');

    // A venue change is calendar-relevant: same UID, SEQUENCE advanced.
    app(NotificationService::class)->send(
        $recipient,
        new EntityUpdated($game, ['location']),
        NotificationCategory::GameUpdated,
    );
    $update = icsContent(icsAttachment(1));
    expect($update)
        ->toContain('METHOD:REQUEST')
        ->toContain('UID:game-'.$game->id.'@roundup.games')
        ->toContain('SEQUENCE:1');

    // A date change re-issues too — the gate must keep working for a
    // future reschedule surface even though today's edit forms cannot
    // change date_time.
    app(NotificationService::class)->send(
        $recipient,
        new EntityUpdated($game, ['date_time']),
        NotificationCategory::GameUpdated,
    );
    expect(icsContent(icsAttachment(2)))->toContain('SEQUENCE:2');

    // A duration change is calendar-relevant too (it feeds DTEND): a host
    // shortening a 5h game must prompt attendees' calendars — the gate
    // previously omitted expected_duration, so every client kept the old
    // end time forever (M081 fifth-pass review).
    app(NotificationService::class)->send(
        $recipient,
        new EntityUpdated($game, ['expected_duration']),
        NotificationCategory::GameUpdated,
    );
    expect(icsContent(icsAttachment(3)))->toContain('SEQUENCE:3');

    // A title-only update must not prompt recipients' calendars again.
    app(NotificationService::class)->send(
        $recipient,
        new EntityUpdated($game, ['name']),
        NotificationCategory::GameUpdated,
    );
    expect($this->capturedMails)->toHaveCount(5)
        ->and(icsAttachment(4))->toBeNull();

    // An update with no calendar-relevant columns attaches no ICS.
    app(NotificationService::class)->send(
        $recipient,
        new EntityUpdated($game),
        NotificationCategory::GameUpdated,
    );
    expect(icsAttachment(5))->toBeNull();
});

it('allocates strictly increasing SEQUENCE values with no duplicate emissions across rapid lifecycle builds', function () {
    $host = User::factory()->create();
    $recipient = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'date_time' => Carbon::parse('+3 days 18:00'),
        'expected_duration' => 3,
    ]);

    $builder = app(IcsBuilder::class);
    $sequences = [];

    // N consecutive lifecycle builds for one UID (the reschedule-then-
    // cancel blast a host produces in seconds). The ledger is now one
    // atomic INSERT … ON CONFLICT … RETURNING statement: the former
    // increment-then-read pair could interleave across queue workers and
    // emit EQUAL SEQUENCE values — and an equal-SEQUENCE CANCEL is
    // discarded by strict clients (MS-STANXICAL), leaving the cancelled
    // event on the attendee's calendar (M081 sixth-pass review). The pin
    // asserts the allocate-and-read contract: every build observes its
    // own post-increment value, never a sibling's.
    for ($i = 0; $i < 8; $i++) {
        $attachment = $builder->request($game, $recipient);
        expect($attachment)->not->toBeNull();
        $sequences[] = (string) Str::of($attachment?->content ?? '')
            ->match('/^SEQUENCE:(\d+)/m');
    }

    expect($sequences)->toBe(
        array_map(fn (int $i): string => (string) $i, range(0, 7)),
    );
});

it('sends no update ICS for campaigns even when the change gate passes', function () {
    $recipient = User::factory()->create([
        'notification_settings' => ['campaign_updated' => ['mail' => true]],
    ]);
    $campaign = Campaign::factory()->create();

    app(NotificationService::class)->send(
        $recipient,
        new EntityUpdated($campaign, ['location']),
        NotificationCategory::CampaignUpdated,
    );

    // A campaign is a container, not a scheduled occurrence: no VEVENT,
    // no attachment (D287).
    expect($this->capturedMails)->toHaveCount(1)
        ->and(icsAttachment(0))->toBeNull();
});

it('revokes the event with METHOD:CANCEL and STATUS:CANCELLED on cancellations', function () {
    $recipient = User::factory()->create();
    $host = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $host->id]);

    app(NotificationService::class)->send(
        $recipient,
        new EntityInvitation($game, $host),
        NotificationCategory::GameInvitation,
    );
    expect(icsContent(icsAttachment(0)))->toContain('SEQUENCE:0');

    app(NotificationService::class)->send(
        $recipient,
        new EntityCancelled($game),
        NotificationCategory::GameCancelled,
    );

    $ics = icsAttachment(1);
    expect($ics)->not->toBeNull()
        ->and($ics->getFilename())->toBe('cancel-game.ics')
        ->and($ics->getPreparedHeaders()->toString())->toContain('method=CANCEL');

    expect(icsContent($ics))
        ->toContain('UID:game-'.$game->id.'@roundup.games')
        // MS-STANXICAL requires the CANCEL to advance SEQUENCE for the
        // client to drop the event it holds.
        ->toContain('SEQUENCE:1')
        ->toContain('STATUS:CANCELLED');
});

it('revokes a registered event’s calendar entry with METHOD:CANCEL on event cancellation', function () {
    // EventRegistrationConfirmed attaches the event-{id} REQUEST; the
    // cancellation must revoke the SAME UID with a bumped SEQUENCE —
    // previously EventCancelled carried no ICS at all, leaving every
    // registrant with a CONFIRMED calendar entry and reminders for a
    // cancelled event (M081 fifth-pass review).
    $recipient = User::factory()->create();
    $event = Event::factory()->create([
        'name' => ['en' => 'Winter Gala'],
        'start_date' => '2026-12-05',
        'end_date' => '2026-12-06',
    ]);
    $registration = EventRegistration::factory()->confirmed()->create([
        'user_id' => $recipient->id,
        'event_id' => $event->id,
    ]);

    app(NotificationService::class)->send(
        $recipient,
        new EventRegistrationConfirmed($registration),
        NotificationCategory::EventRegistration,
    );
    expect(icsContent(icsAttachment(0)))
        ->toContain('METHOD:REQUEST')
        ->toContain('SEQUENCE:0');

    app(NotificationService::class)->send(
        $recipient,
        new EventCancelled($event),
        NotificationCategory::EventRegistration,
    );

    $ics = icsAttachment(1);
    expect($ics)->not->toBeNull()
        ->and($ics->getPreparedHeaders()->toString())->toContain('method=CANCEL');

    expect(icsContent($ics))
        ->toContain('UID:event-'.$event->id.'@roundup.games')
        ->toContain('SEQUENCE:1')
        ->toContain('STATUS:CANCELLED')
        // All-day shape preserved on the revoke so clients match the entry.
        ->toContain('DTSTART;VALUE=DATE:20261205');
});

it('revokes the calendar event when a seat is demoted', function () {
    $recipient = User::factory()->create();
    $host = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $host->id]);

    app(NotificationService::class)->send(
        $recipient,
        new EntityInvitation($game, $host),
        NotificationCategory::GameInvitation,
    );

    app(NotificationService::class)->send(
        $recipient,
        new SeatDemoted($game, 'roster reduced'),
        NotificationCategory::SeatDemoted,
    );

    expect(icsContent(icsAttachment(1)))
        ->toContain('METHOD:CANCEL')
        ->toContain('UID:game-'.$game->id.'@roundup.games')
        ->toContain('SEQUENCE:1')
        ->toContain('STATUS:CANCELLED');
});

it('never carries calendar data on session reminders', function () {
    $game = Game::factory()->create();

    // D287: reminders attach no ICS (duplicate Add-to-calendar prompt
    // risk). The structural guarantee is stronger than that: the
    // reminder is not a mail notification at all, so no mail channel —
    // and therefore no calendar part — can ride along.
    expect(method_exists(SessionReminder::class, 'toMail'))->toBeFalse()
        ->and((new SessionReminder($game))->via(User::factory()->make()))
        ->not->toContain(MailChannel::class);
});

it('leaks no roster or participant data in the ICS payload', function () {
    $recipient = User::factory()->create();
    $host = User::factory()->create();
    $otherPlayer = User::factory()->create([
        'name' => 'Mallory Otherplayer',
        'email' => 'mallory.otherplayer@example.org',
    ]);
    $game = Game::factory()->create(['owner_id' => $host->id]);

    // The roster holds more than the recipient and the host; the ICS
    // must not mention any of them beyond the organizer line.
    GameParticipant::factory()->create([
        'game_id' => $game->id,
        'user_id' => $otherPlayer->id,
    ]);

    app(NotificationService::class)->send(
        $recipient,
        new EntityInvitation($game, $host),
        NotificationCategory::GameInvitation,
    );

    $content = icsUnfold(icsContent(icsAttachment(0)));

    expect($content)
        ->not->toContain('Mallory Otherplayer')
        ->not->toContain('mallory.otherplayer@example.org')
        // DESCRIPTION carries the entity name plus its roundup link only.
        ->toContain('SUMMARY:')
        ->toContain('DESCRIPTION:');

    // The description is exactly name + link: nothing between the
    // DESCRIPTION line and the folded continuation but the entity URL.
    expect(preg_match('/^DESCRIPTION:.+$/m', $content, $description))->toBe(1);
    $unescapedDescription = str_replace(['\\,', '\\;', '\\n'], [',', ';', "\n"], $description[0]);
    expect($unescapedDescription)
        ->toContain($game->name)
        ->not->toContain($otherPlayer->email);
});
