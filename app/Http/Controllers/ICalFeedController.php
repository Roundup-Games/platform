<?php

namespace App\Http\Controllers;

use App\Enums\ParticipantStatus;
use App\Models\CampaignParticipant;
use App\Models\Event;
use App\Models\Game;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ICal\ICalFeedRenderer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
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
 * The token is the ShortLink "code" — the same mechanism used for share links
 * (purpose='share'), reused with purpose='ical' rather than a new token table.
 */
class ICalFeedController extends Controller
{
    /**
     * Rolling feed window (D287): today through +90 days on the community
     * wall calendar. Keeps the document bounded for heavy members.
     */
    private const int FEED_WINDOW_DAYS = 90;

    public function __construct(
        private readonly ICalFeedRenderer $renderer,
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

        [$windowStart, $windowEnd] = $this->feedWindow();
        $games = $this->gamesForUser($user, $windowStart, $windowEnd);
        $events = $this->registeredEventsForUser($user, $windowEnd);

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
    private function feedWindow(): array
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
     * Games within the window that the user hosts OR is an approved
     * participant in OR that are sessions of a campaign the user is an
     * approved member of (D287: campaign membership is a standing commitment
     * to the campaign's schedule — sessions land on the member's calendar
     * even when their per-session game-participant row is benched or
     * waitlisted by capacity routing).
     *
     * Includes canceled games so the feed can emit STATUS:CANCELLED for sync.
     *
     * @return Collection<int, Game>
     */
    private function gamesForUser(User $user, Carbon $windowStart, Carbon $windowEnd): Collection
    {
        return Game::query()
            // Upper bound mirrors the events leg's inclusive community day
            // (+90 whole days): windowEnd is community MIDNIGHT of day +90,
            // so day +90's sessions need the end-of-day instant (midnight of
            // day +91) to match the docblock's "today through +90 inclusive"
            // and the events query's toDateString() comparison.
            ->whereBetween('date_time', [$windowStart, $windowEnd->copy()->addDay()->utc()])
            ->where(function ($query) use ($user) {
                // Games the user hosts.
                $query->whereBelongsTo($user, 'owner')
                    ->orWhereHas('participants', fn ($q) => $q
                        ->whereBelongsTo($user)
                        ->where('status', ParticipantStatus::Approved->value))
                    ->orWhereIn('campaign_id', $this->approvedCampaignIds($user));
            })
            // owner + linkedLocation are read per VEVENT by the renderer/
            // IcsBuilder — eager-loaded so the feed stays N+1-free.
            ->with(['owner', 'linkedLocation'])
            ->orderBy('date_time')
            ->get();
    }

    /**
     * Events within the window the user holds a CONFIRMED registration for
     * (D287): pending registrations are not commitments yet; cancelled ones
     * drop out of the feed entirely (the registration, not the event, is the
     * member's commitment — a cancelled event with a still-confirmed
     * registration publishes STATUS:CANCELLED so clients sync the removal).
     *
     * @return Collection<int, Event>
     */
    private function registeredEventsForUser(User $user, Carbon $windowEnd): Collection
    {
        return Event::query()
            ->whereHas('registrations', fn ($q) => $q
                ->whereBelongsTo($user)
                ->where('status', 'confirmed'))
            // start_date is a date column; compare against calendar dates on
            // the community wall calendar. The lower bound uses the COMMUNITY
            // today, not windowStart's UTC date: windowStart is Berlin
            // midnight converted to UTC (23:00Z the prior day in winter), so
            // its toDateString() would admit community-yesterday's events on
            // every render. The upper bound mirrors feedWindow()'s community
            // day +90.
            ->where('start_date', '>=', community_now()->toDateString())
            ->where('start_date', '<=', $windowEnd->toDateString())
            ->with('organizer')
            ->get();
    }

    /**
     * IDs of the campaigns the user is an approved member of.
     *
     * @return list<string>
     */
    private function approvedCampaignIds(User $user): array
    {
        $campaignIds = [];

        foreach (
            CampaignParticipant::query()
                ->whereBelongsTo($user)
                ->where('status', ParticipantStatus::Approved->value)
                ->pluck('campaign_id') as $campaignId
        ) {
            if (is_string($campaignId)) {
                $campaignIds[] = $campaignId;
            }
        }

        return $campaignIds;
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
