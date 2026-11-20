<?php

namespace App\Http\Controllers;

use App\Models\ShortLink;
use App\Models\User;
use App\Services\Calendar\DesiredCalendarSet;
use App\Services\ICal\ICalFeedRenderer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
// community_now() returns the base Carbon class, so the window params are
// hinted against it (Illuminate\Support\Carbon is a subclass and would
// reject the helper's return value).
use Illuminate\Support\Facades\Log;

/**
 * Serves the per-user tokenized iCal feed (D123; D287/T02 content extension).
 *
 * Flow: GET /calendar/{code} (outside the locale group) → resolve ShortLink
 *       (linkable=User, purpose='ical') → owner user → their calendar within
 *       a rolling window (next 90 days, today included) → METHOD:PUBLISH
 *       VCALENDAR (see ICalFeedRenderer) → text/calendar response.
 *
 * Feed content (D287): games the user hosts or is an approved participant
 * in, PLUS every session of a campaign they are an approved member of, PLUS
 * events they hold a confirmed registration for. Canceled games (and
 * cancelled events) emit STATUS:CANCELLED; everything is ordered by start.
 *
 * Snapshot semantics (accepted tradeoff, M081 fifth-pass review): a
 * commitment that ENDS without a cancellation — leaving a game, a dropped
 * registration — simply vanishes from the next render instead of publishing
 * a STATUS:CANCELLED VEVENT for the window. RFC 5546 §3.6.1 makes omission
 * behavior client-dependent (Apple Calendar is known to retain vanished
 * VEVENTs); retaining dropped commitments for a grace cycle would change
 * the renderer's set semantics. Accepted for now — the mail path carries
 * METHOD:CANCEL for host-driven removals (SeatDemoted, cancellations), and
 * user-initiated leaves are visible in-app.
 *
 * Calendar clients (Google/Apple) poll this raw URL with no locale prefix and
 * no session/cookies, so the route lives outside the {locale} group and locale
 * is resolved from the user's preferred_language (falling back to the app
 * default). An expired/unknown/revoked token returns 404 (no user enumeration).
 *
 * The commitment query itself lives in {@see DesiredCalendarSet} since
 * S03-T04: one definition shared with the Google reconcile sweep so the
 * feed and the managed sync can never disagree about what belongs on a
 * calendar. This controller keeps only the HTTP concerns (token
 * resolution, locale, caching) plus the shared window constant below.
 *
 * The token is the ShortLink "code" — the same mechanism used for share links
 * (purpose='share'), reused with purpose='ical' rather than a new token table.
 */
class ICalFeedController extends Controller
{
    /**
     * Rolling feed window (D287): today through +90 days on the community
     * wall calendar. Keeps the document bounded for heavy members.
     *
     * Public static: the managed-sync trigger seam (CalendarSyncDispatcher,
     * M083/S03-T03) and the desired-set resolution (DesiredCalendarSet,
     * S03-T04) reuse the EXACT same window so triggers and the reconcile
     * sweep never sync an occurrence the feed does not publish. One
     * definition, three consumers, zero drift.
     */
    public const int FEED_WINDOW_DAYS = 90;

    public function __construct(
        private readonly ICalFeedRenderer $renderer,
        private readonly DesiredCalendarSet $desiredSet,
    ) {}

    /**
     * Serve the iCal feed for a token code.
     */
    public function show(Request $request, string $code): Response
    {
        $link = $this->resolveToken($code);

        if ($link === null) {
            // No token fragment in logs: the code IS the credential — even a
            // 3-char prefix shrinks an attacker's remaining keyspace
            // 62³-fold against the 36-char space. The IP hash is enough to
            // correlate probe traffic.
            Log::debug('ical_feed.token_expired', [
                'ip_hash' => $this->ipHash($request),
            ]);

            abort(404);
        }

        /** @var User|null $user */
        $user = User::find($link->linkable_id);

        if ($user === null) {
            Log::debug('ical_feed.user_missing', [
                'link_id' => $link->id,
            ]);

            abort(404);
        }

        $locale = $this->resolveLocale($user);

        [$windowStart, $windowEnd] = self::feedWindow();
        $games = $this->desiredSet->gamesForUser($user, $windowStart, $windowEnd);
        $events = $this->desiredSet->registeredEventsForUser($user, $windowEnd);

        Log::debug('ical_feed.resolved', [
            'user_id' => $user->id,
            'locale' => $locale,
            'game_count' => $games->count(),
            'event_count' => $events->count(),
            'ip_hash' => $this->ipHash($request),
        ]);

        $body = $this->renderer->render($games->concat($events), $locale);

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="roundup-calendar.ics"',
            // Private (per-user, token-authenticated content — never a shared
            // cache) with a 1h freshness window: calendar clients poll on
            // their own schedule anyway (Google refreshes lazily), so an hour
            // of staleness trades for an order of magnitude fewer renders.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Resolve the ShortLink token to a valid (non-expired, non-revoked) ical link.
     */
    private function resolveToken(string $code): ?ShortLink
    {
        $link = ShortLink::where('code', $code)
            ->where('purpose', 'ical')
            ->where('linkable_type', (new User)->getMorphClass())
            ->first();

        // Expired tokens resolve to nothing (404) — mirrors ShortLinkService.
        // Soft-deleted (revoked) tokens are already excluded by SoftDeletes.
        if ($link !== null && $link->isExpired()) {
            return null;
        }

        return $link;
    }

    /**
     * Resolve the feed locale from the user's preferred language, defaulting
     * to the first available locale.
     */
    private function resolveLocale(User $user): string
    {
        $preferred = $user->preferred_language?->value;

        $available = config('app.available_locales', ['en']);
        if (! is_array($available) || $available === []) {
            $available = ['en'];
        }

        if (is_string($preferred) && in_array($preferred, $available, true)) {
            return $preferred;
        }

        return is_string($available[0] ?? null) ? $available[0] : 'en';
    }

    /**
     * The rolling feed window on the community wall calendar (R089): today's
     * community-midnight through the same day +90 (inclusive). The start
     * boundary converts to a UTC instant so today's early-morning sessions
     * stay in the feed instead of dropping at 22:00/23:00 UTC the evening
     * before; the end boundary stays on the community calendar for the
     * date-only event columns.
     *
     * @return array{0: Carbon, 1: Carbon} [windowStart (UTC instant), windowEnd (community calendar day +90)]
     */
    public static function feedWindow(): array
    {
        $today = community_now()->startOfDay();

        // Copies are load-bearing: Carbon is mutable, and the two bounds
        // must stay independent objects (->utc()/->addDays() mutate in
        // place — sharing one instance collapsed the window to empty in
        // the first cut of this method).
        return [
            $today->copy()->utc(),
            $today->copy()->addDays(self::FEED_WINDOW_DAYS),
        ];
    }

    /**
     * Hash the request IP for logging (mirrors ShortLinkService / ShortLinkController).
     */
    private function ipHash(Request $request): string
    {
        $key = config('app.key');

        return hash_hmac('sha256', (string) $request->ip(), is_string($key) ? $key : '');
    }
}
