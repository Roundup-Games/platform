<?php

namespace App\Services\Calendar;

use App\Enums\EventStatus;
use App\Enums\GameStatus;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Hand-rolled RFC 5545 / RFC 5546 (iTIP) calendar writer (D287, M081/S07).
 *
 * One ICS writer, two consumers: the iTIP lifecycle attachments carried by
 * notification mails (METHOD:REQUEST / METHOD:CANCEL) and — from S07/T02 —
 * the personal webcal feed (METHOD:PUBLISH). Deliberately dependency-free:
 * the iTIP matrix is a handful of properties, and the parts that make
 * calendar clients actually accept an attachment — CRLF line endings,
 * octet-exact folding, an ATTENDEE on every REQUEST (without one Outlook
 * silently ignores the invitation), and a monotonic SEQUENCE per UID
 * (required by MS-STANXICAL for CANCEL processing) — are exactly the
 * behavior a generic library would hide behind its own abstractions.
 *
 * UID family — stable across a whole lifecycle so a client correlates the
 * invitation with its updates and its cancellation (and, from T02, with
 * the same event re-published by the personal webcal feed):
 *   game-{id}@roundup.games                       standalone Game
 *   campaign-{id}-session-{gameId}@roundup.games  Game scheduled inside a Campaign
 *   event-{id}@roundup.games                      Event
 *
 * A bare Campaign resolves to NO VEVENT: a campaign is a container whose
 * sessions are independently scheduled Games (D287), so its lifecycle
 * mails carry no calendar payload and both entry points return null.
 *
 * SEQUENCE policy: one calendar_lifecycle_counters row per UID. The first
 * build for a UID emits SEQUENCE 0 (the initial REQUEST); every later
 * build increments, so update REQUESTs and CANCELs are always a
 * monotonically increasing revision of the same UID. Builds are
 * per-recipient-monotonic — a fan-out of one revision to N recipients may
 * emit N distinct increasing values, which is fine: each mail client only
 * ever compares the SEQUENCE values it received itself.
 */
class IcsBuilder
{
    public const string PROD_ID = '-//Roundup//Roundup Calendar//EN';

    public const string UID_DOMAIN = 'roundup.games';

    /** RFC 5545 §3.1: no content line may exceed 75 octets after folding. */
    private const int LINE_OCTET_LIMIT = 75;

    /**
     * Machine-readable column names whose change alters an entity's
     * calendar payload — the EntityUpdated attachment gate intersects
     * EntityUpdated::$changedColumns with this set. 'location' is the
     * freeform location-details column; 'location_id' the linked venue;
     * 'expected_duration' feeds DTEND (occurrenceLines derives it from
     * date_time + duration), so a duration edit must re-issue the REQUEST
     * or attendees keep the old end time (M081 fifth-pass review).
     * A future reschedule surface that edits date_time behaves correctly
     * for free.
     */
    public const array CALENDAR_RELEVANT_COLUMNS = ['date_time', 'expected_duration', 'location', 'location_id'];

    /**
     * iTIP METHOD:REQUEST — the calendar invitation for the recipient:
     * the initial invite, or a same-UID update (SEQUENCE bump) when the
     * recipient already holds the event.
     */
    public function request(Game|Campaign|Event $entity, User $attendee, ?string $descriptionUrl = null): ?IcsAttachment
    {
        return $this->build('REQUEST', $entity, $attendee, $descriptionUrl, cancelled: false);
    }

    /**
     * iTIP METHOD:CANCEL — STATUS:CANCELLED plus a SEQUENCE increment so
     * clients drop the event they hold for this UID (seat revocations,
     * cancellations).
     */
    public function cancel(Game|Campaign|Event $entity, User $attendee, ?string $descriptionUrl = null): ?IcsAttachment
    {
        return $this->build('CANCEL', $entity, $attendee, $descriptionUrl, cancelled: true);
    }

    /**
     * Whether any of the given machine-readable changed columns affects
     * the entity's calendar payload (see CALENDAR_RELEVANT_COLUMNS).
     *
     * @param  list<string>  $columns
     */
    public static function columnsAffectCalendar(array $columns): bool
    {
        return array_intersect($columns, self::CALENDAR_RELEVANT_COLUMNS) !== [];
    }

    /**
     * METHOD:PUBLISH — the personal webcal feed view (S07/T02): every
     * schedulable occurrence the feed owner sees, in one VCALENDAR, ordered
     * by the caller. Unlike the iTIP methods a PUBLISH carries no ATTENDEE
     * (there is no invitee — the subscriber pulls the calendar) and no
     * SEQUENCE line: a feed is a whole-current-state snapshot re-rendered on
     * every poll, while the SEQUENCE counter belongs exclusively to the
     * iTIP lifecycle. It DOES carry ORGANIZER — required on published
     * VEVENTs by RFC 5546 §3.6.1 — bound to the platform's no-reply
     * identity (roundup is the publisher of the feed; user emails stay
     * private under the D287 matrix). UIDs come from the same family as
     * the iTIP attachments, so a client holding an invitation for a UID and
     * also subscribed to the feed correlates both to one calendar event.
     *
     * @param  iterable<Game|Event>  $entities  feed-scoped occurrences (host/approved/campaign-session games, registered events); the feed owner's scoping is the caller's concern
     * @param  callable(Game|Event): array{summary?: string, description?: string, url?: string|null}|null  $textResolver  optional locale-aware text resolution; defaults to the entity's own summary as SUMMARY and DESCRIPTION with no URL
     */
    public function publish(iterable $entities, ?callable $textResolver = null): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'PRODID:'.self::PROD_ID,
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($entities as $entity) {
            $eventLines = $this->publishEventLines($entity, $textResolver);

            if ($eventLines !== null) {
                $lines = [...$lines, ...$eventLines];
            }
        }

        $lines[] = 'END:VCALENDAR';

        return $this->render($lines);
    }

    /**
     * One PUBLISH VEVENT for an entity, or null when the entity carries no
     * schedulable occurrence (the same resolution as the iTIP path).
     *
     * @param  callable(Game|Event): array{summary?: string, description?: string, url?: string|null}|null  $textResolver
     * @return list<string>|null
     */
    private function publishEventLines(Game|Event $entity, ?callable $textResolver): ?array
    {
        $event = $this->resolveEvent($entity);

        if ($event === null) {
            return null;
        }

        $text = $textResolver !== null ? $textResolver($entity) : [];
        $summary = \is_string($text['summary'] ?? null) && $text['summary'] !== ''
            ? $text['summary']
            : $event['summary'];
        $description = \is_string($text['description'] ?? null) && $text['description'] !== ''
            ? $text['description']
            : $summary;
        $url = \is_string($text['url'] ?? null) && $text['url'] !== ''
            ? $text['url']
            : null;

        $fromAddress = config('mail.from.address');

        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$event['uid'],
            'STATUS:'.$this->publishStatus($entity),
            'DTSTAMP:'.$this->utcStamp(now()),
            // RFC 5546 §3.6.1: ORGANIZER is required on published VEVENTs.
            // The platform is the feed's publisher — the no-reply identity
            // satisfies the conformance requirement without exposing any
            // user's email in a token-authenticated feed (D287 privacy matrix).
            'ORGANIZER;CN=Roundup:mailto:'.(is_string($fromAddress) && $fromAddress !== '' ? $fromAddress : 'noreply@roundup.games'),
            ...$this->occurrenceLines($event),
        ];

        $lines = [...$lines, ...$this->textLines($event, $summary, $description, $url)];

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Feed STATUS: terminal outcomes — canceled games and cancelled events —
     * publish STATUS:CANCELLED so subscribers' clients sync the deletion.
     * Completed maps to CANCELLED too (M081 sixth-pass review): the feed
     * window is forward-only (today → +90d), so a Completed game inside it
     * is a future session closed early — it will NOT take place, and
     * publishing it as CONFIRMED would keep asserting a phantom event on
     * every subscriber's calendar forever. Past completed sessions never
     * enter the window, so normal post-session completions are unaffected.
     */
    private function publishStatus(Game|Event $entity): string
    {
        $cancelled = $entity instanceof Game
            ? in_array($entity->status, [GameStatus::Canceled, GameStatus::Completed], true)
            : $entity->status === EventStatus::Cancelled;

        return $cancelled ? 'CANCELLED' : 'CONFIRMED';
    }

    /**
     * Stable UID for an entity — the same value across every lifecycle
     * mail so calendar clients correlate invitation, updates and
     * cancellation. Pure function of the entity, never of the
     * notification type carrying it.
     */
    public function uidFor(Game|Campaign|Event $entity): string
    {
        if ($entity instanceof Event) {
            return 'event-'.$entity->id.'@'.self::UID_DOMAIN;
        }

        if ($entity instanceof Campaign) {
            return 'campaign-'.$entity->id.'@'.self::UID_DOMAIN;
        }

        // A Game scheduled inside a campaign addresses its own VEVENT, so
        // updating or cancelling one session never touches its siblings.
        if ($entity->campaign_id !== null) {
            return 'campaign-'.$entity->campaign_id.'-session-'.$entity->id.'@'.self::UID_DOMAIN;
        }

        return 'game-'.$entity->id.'@'.self::UID_DOMAIN;
    }

    /**
     * Resolve an entity onto the VEVENT it represents, or null when the
     * entity carries no schedulable occurrence (a bare campaign, a game
     * without a date, an event without an organizer).
     *
     * @return array{uid: string, type: string, summary: string, start: CarbonInterface, end: CarbonInterface|null, all_day: bool, organizer: User, location: string|null}|null
     */
    private function resolveEvent(Game|Campaign|Event $entity): ?array
    {
        if ($entity instanceof Event) {
            $organizer = $entity->organizer;

            if ($entity->start_date === null || $organizer === null) {
                return null;
            }

            // Events are whole-day affairs (date-cast columns), rendered as
            // RFC 5545 all-day VEVENTs; DTEND is computed exclusive in build().
            return [
                'uid' => $this->uidFor($entity),
                'type' => 'event',
                'summary' => $entity->name,
                'start' => $entity->start_date,
                'end' => $entity->end_date,
                'all_day' => true,
                'organizer' => $organizer,
                'location' => $entity->venue_name !== null && $entity->venue_name !== ''
                    ? $entity->venue_name
                    : null,
            ];
        }

        if ($entity instanceof Campaign) {
            return null;
        }

        if ($entity->date_time === null || $entity->owner === null) {
            return null;
        }

        return [
            'uid' => $this->uidFor($entity),
            'type' => 'game',
            'summary' => $entity->name,
            'start' => $entity->date_time,
            'end' => $entity->expected_duration > 0
                ? $entity->date_time->copy()->addHours($entity->expected_duration)
                : null,
            'all_day' => false,
            'organizer' => $entity->owner,
            // Deliberately the LINKED VENUE NAME only — never freeform
            // location.details: the ICS privacy matrix exposes venue names,
            // not freeform host text (the mail body carries the details to
            // the same recipient; the calendar artifact may be shared
            // onward). Accepted tradeoff from the M081 fifth-pass review.
            'location' => $entity->linkedLocation?->name,
        ];
    }

    /**
     * Deterministic upsert-and-increment SEQUENCE policy: the first build
     * for a UID emits 0; every subsequent build emits the incremented value.
     *
     * One atomic statement (sixth-pass review): the former
     * insertOrIgnore + increment + separate read back was a race window —
     * two queue workers rendering consecutive lifecycle mails for one UID
     * could interleave increment/increment/read/read and emit EQUAL
     * SEQUENCE values, and an equal-SEQUENCE CANCEL is discarded by strict
     * clients (MS-STANXICAL), leaving the cancelled event on the
     * attendee's calendar. INSERT … ON CONFLICT (uid) DO UPDATE … RETURNING
     * makes allocate-and-read one statement: Postgres serializes the
     * conflict update on the unique row, so every caller observes its own
     * post-increment value. Skipped values under concurrency are fine —
     * only strict monotonicity per recipient is load-bearing.
     */
    private function nextSequence(string $uid, string $entityType, string $entityId, CarbonInterface $now): int
    {
        $row = DB::selectOne(
            'INSERT INTO calendar_lifecycle_counters (entity_type, entity_id, uid, sequence, created_at, updated_at)
             VALUES (:entity_type, :entity_id, :uid, 0, :created_at, :updated_at)
             ON CONFLICT (uid) DO UPDATE
                SET sequence = calendar_lifecycle_counters.sequence + 1,
                    updated_at = EXCLUDED.updated_at
             RETURNING sequence',
            [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'uid' => $uid,
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ],
        );

        return $row instanceof \stdClass && isset($row->sequence) ? (int) $row->sequence : 0;
    }

    private function build(
        string $method,
        Game|Campaign|Event $entity,
        User $attendee,
        ?string $descriptionUrl,
        bool $cancelled,
    ): ?IcsAttachment {
        $event = $this->resolveEvent($entity);

        if ($event === null) {
            return null;
        }

        $now = now();
        $sequence = $this->nextSequence($event['uid'], $event['type'], $entity->id, $now);

        $lines = [
            'BEGIN:VCALENDAR',
            'PRODID:'.self::PROD_ID,
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:'.$method,
            'BEGIN:VEVENT',
            'UID:'.$event['uid'],
            'SEQUENCE:'.$sequence,
            'STATUS:'.($cancelled ? 'CANCELLED' : 'CONFIRMED'),
            'DTSTAMP:'.$this->utcStamp($now),
            ...$this->occurrenceLines($event),
        ];

        $organizer = $event['organizer'];
        $organizerName = $organizer->name !== ''
            ? $organizer->name
            : $organizer->email;

        $lines[] = 'ORGANIZER;CN='.$this->escapeParam($organizerName).':mailto:'.$organizer->email;
        // RFC 5545 §3.2.17 param grammar: param-name "=" param-value, so the
        // explicit no-reply expectation is RSVP=FALSE (the colon form is a
        // vCard 2.1-ism strict calendar parsers reject).
        $lines[] = 'ATTENDEE;RSVP=FALSE:mailto:'.$attendee->email;

        // Privacy matrix (D287): entity name plus its roundup link only —
        // no roster, no other participants, no addresses anywhere.
        $description = $event['summary'];

        if ($descriptionUrl !== null && $descriptionUrl !== '') {
            $description .= "\n".$descriptionUrl;
        }

        $lines = [...$lines, ...$this->textLines($event, $event['summary'], $description, null)];

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return new IcsAttachment(
            content: $this->render($lines),
            filename: ($cancelled ? 'cancel-' : 'invite-').$event['type'].'.ics',
            mimeType: 'text/calendar; method='.$method,
        );
    }

    /**
     * The DTSTART/DTEND content lines for a resolved event — shared by the
     * iTIP attachments and the PUBLISH feed so both consumers emit identical
     * occurrences for the same entity.
     *
     * @param  array{uid: string, type: string, summary: string, start: CarbonInterface, end: CarbonInterface|null, all_day: bool, organizer: User, location: string|null}  $event
     * @return list<string>
     */
    private function occurrenceLines(array $event): array
    {
        if ($event['all_day']) {
            return [
                'DTSTART;VALUE=DATE:'.$event['start']->format('Ymd'),
                // All-day DTEND is exclusive (RFC 5545 §3.6.1): a two-day event
                // ending on the 6th carries DTEND of the 7th.
                'DTEND;VALUE=DATE:'.($event['end'] ?? $event['start'])->copy()->addDay()->format('Ymd'),
            ];
        }

        $lines = ['DTSTART:'.$this->utcStamp($event['start'])];

        if ($event['end'] !== null) {
            $lines[] = 'DTEND:'.$this->utcStamp($event['end']);
        }

        return $lines;
    }

    /**
     * The SUMMARY / LOCATION / URL / DESCRIPTION content lines. LOCATION
     * carries the venue NAME only, never the street address (privacy
     * matrix, D287). The URL property is feed-only deep link (iTIP mails
     * fold their link into DESCRIPTION instead).
     *
     * @param  array{uid: string, type: string, summary: string, start: CarbonInterface, end: CarbonInterface|null, all_day: bool, organizer: User, location: string|null}  $event
     * @return list<string>
     */
    private function textLines(array $event, string $summary, string $description, ?string $url): array
    {
        $lines = ['SUMMARY:'.$this->escapeText($summary)];

        if ($event['location'] !== null && $event['location'] !== '') {
            $lines[] = 'LOCATION:'.$this->escapeText($event['location']);
        }

        if ($url !== null) {
            $lines[] = 'URL:'.$url;
        }

        $lines[] = 'DESCRIPTION:'.$this->escapeText($description);

        return $lines;
    }

    /**
     * @param  list<string>  $lines  unfolded content lines
     */
    private function render(array $lines): string
    {
        $content = '';

        foreach ($lines as $line) {
            $content .= $this->fold($line);
        }

        return $content;
    }

    /**
     * Emit one content line as CRLF-terminated physical lines, folding at
     * 75 octets with the RFC 5545 continuation convention (leading space).
     * Cuts never split a UTF-8 sequence: if the octet that would begin
     * the next physical line is a continuation byte, it is handed back.
     */
    private function fold(string $line): string
    {
        if (\strlen($line) <= self::LINE_OCTET_LIMIT) {
            return $line."\r\n";
        }

        $length = \strlen($line);
        $folded = '';
        $offset = 0;
        $isFirstLine = true;

        while ($offset < $length) {
            // Continuation lines spend one octet on the leading space.
            $budget = $isFirstLine ? self::LINE_OCTET_LIMIT : self::LINE_OCTET_LIMIT - 1;
            $take = min($budget, $length - $offset);

            while (
                $take > 1
                && $offset + $take < $length
                && (ord($line[$offset + $take]) & 0xC0) === 0x80
            ) {
                $take--;
            }

            $folded .= ($isFirstLine ? '' : ' ').substr($line, $offset, $take)."\r\n";
            $offset += $take;
            $isFirstLine = false;
        }

        return $folded;
    }

    /**
     * RFC 5545 §3.3.11 TEXT escaping: CR is stripped first — it has no
     * TEXT escape (VALUE-CHAR excludes it) and a raw CR mid-line would
     * corrupt the CRLF line protocol every parser unfolds on; entity names
     * are user input validated only as string|max:255. Then backslash
     * first, then the structural characters, so inserted escapes are never
     * re-escaped.
     */
    private function escapeText(string $value): string
    {
        $value = str_replace("\r", '', $value);

        return str_replace(
            ['\\', "\n", ';', ','],
            ['\\\\', '\n', '\;', '\,'],
            $value,
        );
    }

    /**
     * RFC 5545 §3.1 param-value escaping for CN: control characters are
     * stripped first — CTL is excluded from param values entirely, and a
     * raw CR/LF mid-line would corrupt the CRLF line protocol every
     * parser unfolds on (the same posture as escapeText's CR strip;
     * display names arrive validated only as string-length, and the
     * ValidUserName sanitizer keeps \s — which includes newlines — so a
     * crafted "Alice\nBob" display name must not break the attachment).
     * Then backslash and DQUOTE are escaped, and the value is DQUOTE-wrapped
     * when it contains a structural character (; : ,) that would otherwise
     * end the parameter.
     */
    private function escapeParam(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        if (preg_match('/[;,:]/', $escaped) === 1) {
            return '"'.$escaped.'"';
        }

        return $escaped;
    }

    /**
     * UTC iCalendar DATE-TIME (…THHMMSSZ) — DTSTAMP and the game/session
     * DTSTART/DTEND family. All-day events use VALUE=DATE instead.
     */
    private function utcStamp(CarbonInterface $time): string
    {
        return $time->utc()->format('Ymd\THis\Z');
    }
}
