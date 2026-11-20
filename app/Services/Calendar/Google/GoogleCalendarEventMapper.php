<?php

namespace App\Services\Calendar\Google;

use App\Models\Event;
use App\Models\Game;
use App\Services\Calendar\IcsBuilder;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Pure mapping layer of the managed Google sync (M083/S03-T01, D287/D299):
 * a roundup entity → Google Calendar API event resource.
 *
 * Event identity is THE contract of this class: {@see eventId()} derives
 * from the SAME {@see IcsBuilder} UID family the iTIP mail attachments and
 * the webcal feed use (game-{id}@roundup.games /
 * campaign-{id}-session-{gameId}@roundup.games / event-{id}@roundup.games),
 * so one occurrence correlates on one identity across all three surfaces.
 * The raw UID is hashed (SHA-256 → lowercase base32hex) rather than emitted
 * as-is: Google restricts event ids to the base32hex charset a-v0-9 with
 * 5–1024 characters, and a raw stem like "game-42" would collide as a
 * PREFIX of "game-421" ids, not just a charset violation. The hash is
 * deterministic per entity, lands at a fixed 52 characters, and carries no
 * padding — identical inputs always produce identical ids, which is what
 * makes S02/S03's insert-then-patch idempotency possible. The derivation is
 * frozen by golden tests: changing it would orphan every already-synced
 * Google event, so it may only change as a deliberate, migration-planned
 * act.
 *
 * Privacy matrix (D287, inherited from IcsBuilder): the event body carries
 * the entity NAME, its venue NAME (linked location for games, venue_name
 * for events — never freeform location details), and the roundup page link
 * — no roster, no other participants, no addresses.
 */
class GoogleCalendarEventMapper
{
    /**
     * Google's event-id charset: RFC 4648 base32hex, lowercased
     * (0-9 then a-v) per the Events resource documentation.
     */
    private const string ID_ALPHABET = '0123456789abcdefghijklmnopqrstuv';

    public function __construct(private readonly IcsBuilder $builder) {}

    /**
     * The events().insert body for an entity.
     *
     * Occurrences map in the USER's timezone: the caller resolves
     * users.timezone (fallback community_timezone) and passes it in; a null
     * timezone falls back to community_timezone() here so the payload never
     * silently renders in UTC. Timed games carry RFC 3339 dateTimes (with
     * the offset of the rendered instant, plus the IANA zone for DST-safe
     * display); whole-day Events carry exclusive all-day dates, mirroring
     * IcsBuilder's all-day DTEND semantics.
     *
     * @param  string  $locale  locale to resolve translatable fields to (any → en fallback, mirroring ICalFeedRenderer).
     * @param  string|null  $timezone  the user's display timezone; null falls back to community_timezone().
     * @return array<string, mixed> the insert body: id, summary, description,
     *                              optional location, start/end blocks, and
     *                              extendedProperties.private carrying the
     *                              roundup uid + type for audit (and for the
     *                              reconcile sweep's "only touch our events"
     *                              filter).
     *
     * @throws InvalidArgumentException when the entity carries no schedulable occurrence (a game without a date, an event without a start date) — the sync callers gate on this before dispatching.
     */
    public function toGoogleEvent(Game|Event $entity, string $locale, ?string $timezone): array
    {
        $zone = $timezone ?? community_timezone();

        // Fail fast before any text/route resolution: an unschedulable
        // entity must surface its guard, not a downstream URL-generation
        // error from a half-built payload.
        $occurrence = $this->occurrence($entity, $zone);

        $summary = $this->resolveTranslation($entity, 'name', $locale);

        $payload = [
            'id' => $this->eventId($entity),
            // Explicit on every insert AND patch: patch semantics keep
            // unspecified fields unchanged, so a payload without status
            // could never restore an event Google holds cancelled — the
            // 409→patch convergence and the sweep's resurrect path ride
            // this explicit 'confirmed' (Google's documented undelete
            // mechanism is a status write, never field omission).
            'status' => 'confirmed',
            'summary' => $summary,
            'description' => $this->description($entity, $summary),
            'extendedProperties' => [
                'private' => [
                    'roundup_uid' => $this->builder->uidFor($entity),
                    'roundup_type' => $entity instanceof Event ? 'event' : 'game',
                ],
            ],
            ...$occurrence,
        ];

        $location = $this->location($entity);

        if ($location !== null) {
            $payload['location'] = $location;
        }

        return $payload;
    }

    /**
     * The deterministic Google event id for an entity — one identity per
     * occurrence across mail attachments, webcal feed and Google Calendar
     * (D287/D299). Stable per entity: the same entity always maps to the
     * same id, so retried inserts converge onto the same Google event
     * instead of duplicating.
     */
    public function eventId(Game|Event $entity): string
    {
        return $this->deriveEventId($this->builder->uidFor($entity));
    }

    /**
     * The events().patch body marking an event cancelled: Google lowercases
     * the status values, so 'cancelled' — subscribers' calendars drop the
     * occurrence while the id stays reserved for this UID family.
     *
     * @return array<string, string>
     */
    public function cancelPayload(): array
    {
        return ['status' => 'cancelled'];
    }

    // ── Occurrence mapping ───────────────────────────────

    /**
     * The start/end blocks of the event body.
     *
     * @param  string  $zone  the resolved display timezone
     * @return array{start: array<string, string>, end: array<string, string>}
     */
    private function occurrence(Game|Event $entity, string $zone): array
    {
        if ($entity instanceof Event) {
            if ($entity->start_date === null) {
                throw new InvalidArgumentException("Event {$entity->id} has no start_date — not a schedulable occurrence.");
            }

            // Whole-day affair (date-cast columns): all-day dates, end
            // exclusive, mirroring IcsBuilder's DTEND;VALUE=DATE semantics.
            // The timezone is irrelevant for a calendar-day block.
            return [
                'start' => ['date' => $entity->start_date->format('Y-m-d')],
                'end' => ['date' => ($entity->end_date ?? $entity->start_date)->copy()->addDay()->format('Y-m-d')],
            ];
        }

        $start = $entity->date_time;

        if ($start === null) {
            throw new InvalidArgumentException("Game {$entity->id} has no date_time — not a schedulable occurrence.");
        }

        // Google requires an end on insert; a duration-less game maps to a
        // zero-length event at its scheduled instant (IcsBuilder omits DTEND
        // there, but the wire format here has no optional end).
        $end = $entity->expected_duration > 0
            ? $start->copy()->addHours($entity->expected_duration)
            : $start;

        return [
            'start' => $this->instant($start, $zone),
            'end' => $this->instant($end, $zone),
        ];
    }

    /**
     * One timed occurrence block: the RFC 3339 wall-clock string in the
     * user's timezone (offset of the rendered instant) plus the IANA zone
     * name so Google renders DST correctly.
     *
     * @return array{dateTime: string, timeZone: string}
     */
    private function instant(CarbonInterface $time, string $zone): array
    {
        return [
            'dateTime' => $time->copy()->setTimezone($zone)->toRfc3339String(),
            'timeZone' => $zone,
        ];
    }

    // ── Text mapping ─────────────────────────────────────

    /**
     * The venue NAME only, never freeform location details (D287 privacy
     * matrix, same posture as IcsBuilder::resolveEvent).
     */
    private function location(Game|Event $entity): ?string
    {
        if ($entity instanceof Event) {
            return $entity->venue_name !== null && $entity->venue_name !== ''
                ? $entity->venue_name
                : null;
        }

        return $entity->linkedLocation?->name;
    }

    /**
     * Entity name plus its roundup link only — the iTIP DESCRIPTION shape,
     * no roster or participant data (D287).
     */
    private function description(Game|Event $entity, string $summary): string
    {
        $url = null;

        if ($entity instanceof Event) {
            if ($entity->slug !== null && $entity->slug !== '') {
                $url = route('events.detail', ['slug' => $entity->slug], absolute: true);
            }
        } else {
            $url = route('games.detail', $entity, absolute: true);
        }

        return $url !== null ? $summary."\n".$url : $summary;
    }

    /**
     * Resolve a Spatie-translatable field to a single locale string
     * (any → en fallback, mirroring ICalFeedRenderer::resolveTranslation).
     */
    private function resolveTranslation(Game|Event $entity, string $field, string $locale): string
    {
        $value = $entity->getTranslation($field, $locale, false);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $fallback = $entity->getTranslation($field, 'en');

        return is_string($fallback) ? $fallback : '';
    }

    // ── Identity derivation ──────────────────────────────

    /**
     * SHA-256 digest → lowercase base32hex, no padding. 32 input bytes
     * yield 52 characters (256 bits → 51 full 5-bit groups + one final
     * partial group), comfortably inside Google's 5–1024 window.
     */
    private function deriveEventId(string $uid): string
    {
        $digest = hash('sha256', $uid, true);

        $id = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($digest) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $id .= self::ID_ALPHABET[($buffer >> $bits) & 0x1F];
            }
        }

        if ($bits > 0) {
            $id .= self::ID_ALPHABET[($buffer << (5 - $bits)) & 0x1F];
        }

        return $id;
    }
}
