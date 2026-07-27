<?php

namespace App\Services\Discord;

/**
 * Viewer/guild context + per-game roster counts carried into
 * {@see DiscordDigestRenderer}.
 *
 * Mirrors {@see DiscordCardContext}: the digest renderer is a pure transformer
 * (MEM917) — zero DB queries, zero Discord I/O, zero filesystem access. It
 * reads only the Games' attributes and *pre-loaded* relations (owner,
 * linkedLocation, gameSystems) and takes everything that depends on external
 * computation (roster counts, the roundup app URL, locale) through this DTO.
 *
 * {@see DiscordDigestPublisher} (T03) owns the I/O: it runs the eligibility
 * query, computes the per-game approved roster counts from the participant
 * pipeline, eager-loads the relations, and builds this context before calling
 * the renderer. The renderer never mutates global state.
 *
 * The digest renders MANY games in one message (unlike the card path which is
 * one game per context), so the per-game roster counts travel as a
 * `{gameId => count}` map rather than a scalar.
 */
final class DiscordDigestContext
{
    /**
     * @param  array<string, int>  $approvedCounts  Map of game id => approved
     *                                              (confirmed) participant count, populated by the publisher from the
     *                                              participant pipeline. Games absent from the map render with a 0
     *                                              approved count (the publisher is expected to populate every eligible
     *                                              game, but the renderer degrades gracefully).
     * @param  ?string  $appUrl  Base URL for deep links into roundup
     *                           (`{appUrl}/games/{id}`). Falls back to config('app.url') when null.
     * @param  ?string  $locale  Locale for translatable reads + date headings.
     *                           Falls back to the application locale when null.
     * @param  ?string  $guildName  Display name of the guild the digest is
     *                              posted to. Reserved for digest header/footer framing; the renderer
     *                              does not require it.
     */
    public function __construct(
        public readonly array $approvedCounts = [],
        public readonly ?string $appUrl = null,
        public readonly ?string $locale = null,
        public readonly ?string $guildName = null,
    ) {}

    /**
     * Approved participant count for a game, or null when the publisher did not
     * supply one (the caller decides how to degrade — the renderer treats a
     * null as 0 so the one-liner always shows a roster state).
     */
    public function approvedCountFor(string $gameId): ?int
    {
        if (! array_key_exists($gameId, $this->approvedCounts)) {
            return null;
        }

        $count = $this->approvedCounts[$gameId];

        return $count >= 0 ? $count : null;
    }
}
