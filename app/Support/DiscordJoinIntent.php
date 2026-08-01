<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Session-backed store for the Discord "My seat" join intent (M059/S02).
 *
 * When an UNLINKED Discord member clicks "My seat" on a session card, we want
 * to carry the game they were looking at all the way through the Discord OAuth
 * round-trip → registration → (minimal) onboarding → land-on-game-primed-to-join
 * flow, so they never have to re-navigate and the funnel stays intact.
 *
 * The intent is a single game id stored under {@see self::KEY} in the session.
 * It survives the OAuth round-trip (same session) and onboarding; it is
 * CONSUMED (cleared) at the moment the member lands on the game join target or
 * is auto-joined, so it is never replayed on a later unrelated visit.
 *
 * Pure session wrapper — no persistence, no models — so it is trivial to test
 * and has no side effects beyond the session bag.
 */
class DiscordJoinIntent
{
    /** The session key holding the targeted game id. */
    public const KEY = 'discord_join_intent';

    /** TTL in seconds — a stale intent (e.g. an abandoned tab) should not fire days later. */
    public const TTL_SECONDS = 3600;

    /**
     * Record a join intent for the given game. Silently no-ops when the request
     * has no session bound (never throws).
     */
    public function set(Request $request, string $gameId): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put(self::KEY, [
            'game_id' => $gameId,
            'set_at' => now()->getTimestamp(),
        ]);
    }

    /**
     * Read the intent game id WITHOUT clearing it. Returns null when absent,
     * stale (older than {@see TTL_SECONDS}), or the request has no session
     * bound (e.g. a non-browser Livewire sub-request) — never throws.
     */
    public function peek(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $payload = $request->session()->get(self::KEY);
        if (! is_array($payload)) {
            return null;
        }

        $gameId = $payload['game_id'] ?? null;
        if (! is_string($gameId) || $gameId === '') {
            return null;
        }

        $setAt = $payload['set_at'] ?? null;
        if (! is_int($setAt) || (now()->getTimestamp() - $setAt) > self::TTL_SECONDS) {
            return null;
        }

        return $gameId;
    }

    /**
     * Read AND clear the intent game id. Returns null when absent or stale.
     */
    public function consume(Request $request): ?string
    {
        $gameId = $this->peek($request);
        if ($gameId !== null) {
            $request->session()->forget(self::KEY);
        }

        return $gameId;
    }

    /**
     * The roundup web target for a Discord join intent: the game detail page
     * with the ?discord_join=1 flag the GameDetail component reads to prime the
     * join/apply affordance.
     */
    public function targetUrl(string $gameId): string
    {
        $locale = app()->getLocale();
        $base = is_string(config('app.url')) ? rtrim(config('app.url'), '/') : '';

        return $base.'/'.($locale !== '' ? $locale : 'en')
            .'/dashboard/games/'.$gameId.'?discord_join=1';
    }
}
