<?php

namespace App\Services\Calendar;

use App\Models\Game;
use Carbon\CarbonInterface;

/**
 * Google Calendar "add event" template deep link (D287, M081/S07/T02).
 *
 * The stateless zero-OAuth tier of the calendar strategy: a one-click add on
 * the game page and the Discord card via
 * https://calendar.google.com/calendar/render?action=TEMPLATE&… — the link
 * carries a full event description (title, UTC dates, link back, venue name)
 * as query params and Google prefills its create-event form from it. It has
 * no lifecycle (a cancelled game does not remove a deep-link-added event);
 * the lifecycle belongs to the iTIP attachments (IcsBuilder) and the
 * personal webcal feed. Pure function: no I/O, no state.
 */
class GoogleTemplateLink
{
    private const string BASE_URL = 'https://calendar.google.com/calendar/render';

    /**
     * Template link for a Game's calendar slot, or null when the game has no
     * date_time (there is nothing to prefill a slot with).
     *
     * Dates are UTC basic-format instants (…THHMMSSZ), matching the ICS
     * attachments so a manually added event lands on the same instant the
     * invitation/feed would. A game without a positive expected_duration
     * falls back to a one-hour slot — the template URL requires an end.
     *
     * @param  string  $detailsUrl  deep link carried in the details param (the game page URL).
     * @param  string|null  $locationName  venue NAME only — never the street address (privacy matrix, D287).
     * @param  int|null  $maxUrlLength  hard byte cap for carriers with a URL limit (Discord link
     *                                  buttons reject urls over 512 characters with a 400 that fails
     *                                  the whole card). Null (the default) means no cap — the game
     *                                  page link carries the full name and venue.
     */
    public static function forGame(Game $game, string $detailsUrl, ?string $locationName = null, ?int $maxUrlLength = null): ?string
    {
        $start = $game->date_time;

        if ($start === null) {
            return null;
        }

        $startUtc = $start->utc();
        $endUtc = $game->expected_duration > 0
            ? $startUtc->copy()->addHours($game->expected_duration)
            : $startUtc->copy()->addHour();

        return self::build($game->name, $startUtc, $endUtc, $detailsUrl, $locationName, $maxUrlLength);
    }

    /**
     * @param  CarbonInterface  $startUtc  start instant, already in UTC
     * @param  CarbonInterface  $endUtc  end instant, already in UTC
     */
    private static function build(string $text, CarbonInterface $startUtc, CarbonInterface $endUtc, string $detailsUrl, ?string $locationName, ?int $maxUrlLength): string
    {
        $params = [
            'action' => 'TEMPLATE',
            'text' => $text,
            'dates' => $startUtc->format('Ymd\THis\Z').'/'.$endUtc->format('Ymd\THis\Z'),
            'details' => $detailsUrl,
        ];

        if ($locationName !== null && $locationName !== '') {
            $params['location'] = $locationName;
        }

        $url = self::BASE_URL.'?'.http_build_query($params);

        if ($maxUrlLength === null || \strlen($url) <= $maxUrlLength) {
            return $url;
        }

        // Over the carrier's cap. Shed weight in reverse priority order:
        // the venue name first (nice-to-have), then the event title — the
        // dates and the details deep link are the load-bearing params and
        // are never trimmed. The 12-bytes-per-char budget is the worst-case
        // percent-encoding ratio (a 4-byte UTF-8 char encodes to four %XX
        // triplets), so the rebuilt URL is guaranteed back under the cap in
        // one pass.
        unset($params['location']);

        $fixedBytes = \strlen(self::BASE_URL)
            + \strlen(http_build_query(['action' => $params['action'], 'dates' => $params['dates'], 'details' => $params['details']]))
            + \strlen(http_build_query(['text' => '']))
            + 1; // the '?' join

        $textBudget = max(20, $maxUrlLength - $fixedBytes);
        $params['text'] = mb_strcut($text, 0, max(1, intdiv($textBudget, 12)));

        return self::BASE_URL.'?'.http_build_query($params);
    }
}
