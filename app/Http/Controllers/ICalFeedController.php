<?php

namespace App\Http\Controllers;

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ICal\ICalFeedRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Serves the per-user tokenized iCal feed (D123).
 *
 * Flow: GET /calendar/{code} (outside the locale group) → resolve ShortLink
 *       (linkable=User, purpose='ical') → owner user → their upcoming games
 *       (hosting + approved participant) → VEVENTs → text/calendar response.
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
            Log::debug('ical_feed.token_expired', [
                'code_prefix' => substr($code, 0, 3).'…',
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
        $games = $this->gamesForUser($user);

        Log::debug('ical_feed.resolved', [
            'user_id' => $user->id,
            'locale' => $locale,
            'game_count' => $games->count(),
            'ip_hash' => $this->ipHash($request),
        ]);

        $appName = config('app.name', 'Roundup');

        $body = $this->renderer->render(
            $games,
            $locale,
            (is_string($appName) ? $appName : 'Roundup').' iCal Feed',
        );

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="roundup-calendar.ics"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Resolve the ShortLink token to a valid (non-expired, non-revoked) ical link.
     */
    private function resolveToken(string $code): ?ShortLink
    {
        $link = ShortLink::where('code', $code)
            ->where('purpose', 'ical')
            ->where('linkable_type', User::class)
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
     * Upcoming games the user hosts OR is an approved participant in.
     *
     * Includes canceled games so the feed can emit STATUS:CANCELLED for sync.
     *
     * @return Collection<int, Game>
     */
    private function gamesForUser(User $user): Collection
    {
        return Game::where('date_time', '>=', now()->startOfDay())
            ->where(function ($query) use ($user) {
                // Games the user hosts.
                $query->whereBelongsTo($user, 'owner')
                    ->orWhereHas('participants', fn ($q) => $q
                        ->whereBelongsTo($user)
                        ->where('status', ParticipantStatus::Approved->value));
            })
            ->orderBy('date_time')
            ->get();
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
