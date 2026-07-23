<?php

namespace App\Services\ICal;

use App\Enums\GameStatus;
use App\Models\Game;
use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\Enum\EventStatus;
use Eluceo\iCal\Domain\ValueObject\Date;
use Eluceo\iCal\Domain\ValueObject\DateTime;
use Eluceo\iCal\Domain\ValueObject\GeographicPosition;
use Eluceo\iCal\Domain\ValueObject\Location;
use Eluceo\iCal\Domain\ValueObject\SingleDay;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\UniqueIdentifier;
use Eluceo\iCal\Domain\ValueObject\Uri;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Renders a collection of Game models into a RFC 5545 iCalendar document.
 *
 * One VEVENT per game. The renderer is locale-aware via {@see render()}:
 * translatable fields (name/description) are resolved to a single locale so a
 * given feed byte-stream is stable for that locale. UID is derived from the
 * game UUID so a re-render (re-sync) updates the same VEVENT rather than
 * creating a duplicate. Canceled games emit STATUS:CANCELLED so calendar
 * clients can sync the deletion.
 *
 * @see /markuspoerschke/ical v2.x — domain-entity + presentation-factory API.
 */
class ICalFeedRenderer
{
    /**
     * Render the games into an iCalendar string.
     *
     * @param  Collection<int, Game>  $games  Games already scoped to the feed owner.
     * @param  string  $locale  The locale to resolve translatable fields to.
     * @param  string  $prodId  Calendar product identifier (PRODID).
     */
    public function render(Collection $games, string $locale, string $prodId): string
    {
        $events = $games->map(fn (Game $game) => $this->buildEvent($game, $locale))->all();

        $calendar = new Calendar($events);
        $calendar->setProductIdentifier($prodId);

        return (string) (new CalendarFactory)->createCalendar($calendar);
    }

    /**
     * Map a single Game to an iCal Event (VEVENT).
     */
    private function buildEvent(Game $game, string $locale): Event
    {
        $uid = new UniqueIdentifier($game->id.'@roundup.games');

        $event = new Event($uid);

        // Translatable fields resolved to a single locale (fallback any → en
        // is configured globally via Spatie Translatable::fallback).
        $event->setSummary($this->resolveTranslation($game, 'name', $locale));
        $event->setDescription($this->resolveDescription($game, $locale));

        $this->applyOccurrence($event, $game);
        $this->applyLocation($event, $game);
        $this->applyUrl($event, $game);

        // Canceled games emit STATUS:CANCELLED so calendar clients sync the
        // deletion (per D123 / slice must-have). Scheduled/Completed → CONFIRMED.
        if ($game->status === GameStatus::Canceled) {
            $event->setStatus(EventStatus::CANCELLED());
        } else {
            $event->setStatus(EventStatus::CONFIRMED());
        }

        return $event;
    }

    /**
     * Resolve a Spatie-translatable field to a single locale string.
     */
    private function resolveTranslation(Game $game, string $field, string $locale): string
    {
        $value = $game->getTranslation($field, $locale, false);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $fallback = $game->getTranslation($field, 'en');

        return is_string($fallback) ? $fallback : '';
    }

    /**
     * Build the DESCRIPTION from the translated description + optional host note.
     */
    private function resolveDescription(Game $game, string $locale): string
    {
        $description = $this->resolveTranslation($game, 'description', $locale);

        if (is_string($game->host_note) && $game->host_note !== '') {
            $description = trim($description."\n\n".$game->host_note);
        }

        return $description;
    }

    /**
     * Set the occurrence (DTSTART/DTEND) from date_time + expected_duration.
     *
     * When expected_duration is null, fall back to a SingleDay occurrence
     * (all-day event). Otherwise DTEND = date_time + expected_duration hours.
     */
    private function applyOccurrence(Event $event, Game $game): void
    {
        if ($game->date_time === null) {
            return;
        }

        $start = $this->carbonToDateTime($game->date_time);

        if ($game->expected_duration > 0) {
            $end = $this->carbonToDateTime($game->date_time->copy()->addHours((float) $game->expected_duration));
            $event->setOccurrence(new TimeSpan($start, $end));
        } else {
            // All-day fallback — construct the eluceo Date directly from the
            // calendar date (no time component).
            $event->setOccurrence(new SingleDay(
                new Date(
                    $game->date_time->toDateTimeImmutable(),
                ),
            ));
        }
    }

    /**
     * Attach LOCATION + GEO when a linked Location with coordinates exists.
     */
    private function applyLocation(Event $event, Game $game): void
    {
        $location = $game->linkedLocation;

        if ($location === null) {
            return;
        }

        $addressParts = array_filter([
            $location->address,
            $location->city,
        ]);
        $addressString = implode(', ', $addressParts);

        $icalLocation = new Location($addressString ?: '', (string) ($location->name ?? ''));

        if ($location->latitude !== null && $location->longitude !== null) {
            $icalLocation = $icalLocation->withGeographicPosition(
                new GeographicPosition((float) $location->latitude, (float) $location->longitude),
            );
        }

        $event->setLocation($icalLocation);
    }

    /**
     * Attach a deep link to the public game detail page.
     */
    private function applyUrl(Event $event, Game $game): void
    {
        $event->setUrl(new Uri(route('games.detail', $game, absolute: true)));
    }

    /**
     * Convert a Laravel Carbon to an eluceo iCal DateTime (floating, UTC-stamped
     * from the stored timestamp — we pass the app timezone so the rendered value
     * reflects the configured local time).
     */
    private function carbonToDateTime(Carbon $carbon): DateTime
    {
        return new DateTime($carbon->toDateTimeImmutable(), true);
    }
}
