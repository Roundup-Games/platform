<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\Game;
use Carbon\Carbon;

/**
 * Format a Game or Campaign into a tight plain-text share snippet (S07).
 *
 * The snippet is intentionally platform-agnostic — no Discord markdown, no
 * Twitter-specific tokens. Plain text + emoji reads correctly on Discord,
 * Twitter/X, Mastodon, email, Signal, WhatsApp, and anywhere else an
 * organizer might paste it. Format is constrained to ~5 lines and ~110
 * characters so it fits within Twitter's character budget even with a
 * quote-retweet margin, and renders cleanly in Discord's mobile preview.
 *
 * Output shape (5 lines):
 *
 *     🎲 <Entity name>
 *     <Date and time, locale-aware>
 *     <Capacity> · <Game systems, comma-separated>
 *     <Location city or "Online">
 *     Hosted by <Owner name>
 *     <short URL>
 *
 * The URL is the canonical share URL passed by the caller (typically the
 * ShortLink's roundup.games/l/<code> form). The caller owns the URL choice
 * so the formatter stays a pure function of the entity + URL.
 */
class ShareSnippetFormatter
{
    /**
     * Format the share snippet for a Game or Campaign.
     *
     * @param  Game|Campaign  $entity  Must have its owner, gameSystems (Game only),
     *                                 and linkedLocation relationships loaded.
     * @param  string  $shareUrl  Absolute URL the recipient will click.
     * @return string Multi-line plain text, no trailing newline.
     */
    public static function format(Game|Campaign $entity, string $shareUrl): string
    {
        $lines = [];
        $lines[] = '🎲 '.self::truncate((string) $entity->name, 80);

        $lines[] = self::formatDateTime($entity);

        $lines[] = self::formatCapacityAndSystems($entity);

        $lines[] = self::formatLocation($entity);

        $owner = $entity->owner;
        if ($owner) {
            $lines[] = 'Hosted by '.self::truncate((string) $owner->name, 40);
        }

        $lines[] = $shareUrl;

        return implode("\n", $lines);
    }

    /**
     * Locale-aware date/time line. Falls back to 'Date TBD' when the entity
     * has no scheduled time (drafts, campaigns without a fixed next session).
     */
    private static function formatDateTime(Game|Campaign $entity): string
    {
        if ($entity instanceof Game) {
            $dateTime = $entity->date_time;
        } else {
            // Campaigns don't carry a top-level date_time — they are a
            // container for sessions. Use the earliest upcoming session's
            // date_time when available; fall back to 'Date TBD' for
            // newly-created campaigns with no sessions yet.
            /** @var Carbon|null $dateTime */
            $dateTime = $entity->sessions()
                ->whereNotNull('date_time')
                ->orderBy('date_time')
                ->value('date_time');
        }

        if ($dateTime instanceof Carbon) {
            return $dateTime->isoFormat('ddd, MMM D · HH:mm');
        }

        return 'Date TBD';
    }

    /**
     * Capacity + game-systems line. Examples:
     *   3/5 · D&D 5e
     *   0/4 · Board Games, Card Games
     *   Open · D&D 5e
     */
    private static function formatCapacityAndSystems(Game|Campaign $entity): string
    {
        $parts = [];

        $parts[] = self::formatCapacity($entity);

        $systems = self::formatGameSystems($entity);
        if ($systems !== null) {
            $parts[] = $systems;
        }

        return implode(' · ', $parts);
    }

    /**
     * 'X/Y' capacity for games with participants, or 'Open' when the entity
     * has no upper cap.
     */
    private static function formatCapacity(Game|Campaign $entity): string
    {
        $max = $entity->max_players ?? null;

        if ($entity instanceof Game) {
            $approved = $entity->participants()
                ->where('status', 'approved')
                ->count();

            return $max !== null && $max > 0 ? "{$approved}/{$max}" : 'Open';
        }

        // Campaigns don't surface a per-campaign capacity the same way —
        // individual sessions do. For the share snippet, Open is the
        // honest summary at the campaign grain.
        return $max !== null && $max > 0 ? "0/{$max}" : 'Open';
    }

    /**
     * Comma-separated game system names, truncated to 2 systems + 'et al.'
     * when there are more. Null when the entity has no systems attached.
     */
    private static function formatGameSystems(Game|Campaign $entity): ?string
    {
        $systems = $entity->gameSystems()->limit(3)->get();

        if ($systems->isEmpty()) {
            return null;
        }

        $names = $systems->take(2)->map(fn ($s) => (string) $s->name)->implode(', ');

        if ($systems->count() > 2) {
            $names .= ' et al.';
        }

        return $names;
    }

    /**
     * City from the entity's linkedLocation, or 'Location TBD' when no
     * location is set. (Roundup games are in-person by default — there's
     * no 'is_online' flag today.)
     */
    private static function formatLocation(Game|Campaign $entity): string
    {
        $location = $entity->linkedLocation;

        if ($location && $location->city) {
            return self::truncate((string) $location->city, 30);
        }

        return 'Location TBD';
    }

    /**
     * Truncate a string to $limit characters, appending an ellipsis when
     * truncation occurs. Keeps the snippet within platform character budgets.
     */
    private static function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 1).'…';
    }
}
