<?php

namespace App\Services\Discord;

/**
 * Viewer/guild context carried into {@see DiscordCardRenderer}.
 *
 * The renderer is a pure transformer: it reads the Game's attributes and
 * pre-loaded relationships, and takes everything that depends on an external
 * computation (the participant pipeline, guild-membership intersection) through
 * this DTO. That keeps the renderer free of DB queries and Discord I/O —
 * {@see DiscordPublisher} (T05) owns the I/O and computes these values before
 * rendering.
 *
 * All fields are optional so the renderer degrades gracefully — a card can be
 * rendered with no roster counts and no cross-community data, omitting the
 * fields that have nothing to show.
 */
final class DiscordCardContext
{
    public function __construct(
        /**
         * Approved (confirmed) participant count for the roster field.
         * The publisher derives this from the participant pipeline.
         */
        public readonly int $approvedCount = 0,
        /**
         * Waitlisted participant count (overflow model Apollo lacks).
         */
        public readonly int $waitlistCount = 0,
        /**
         * Benched participant count (second overflow model).
         */
        public readonly int $benchedCount = 0,
        /**
         * Number of approved attendees who are NOT members of the target
         * Discord guild — the cross-community indicator. When zero, the
         * renderer omits the indicator entirely (the verify contract).
         * The publisher intersects approved participants' linked Discord
         * identities against the target guild's membership.
         */
        public readonly int $crossCommunityAttendeeCount = 0,
        /**
         * Base URL for deep links into roundup (RSVP fallback / "view on
         * roundup"). Falls back to config('app.url') when null.
         */
        public readonly ?string $appUrl = null,
        /**
         * Locale for translatable fields (game name/description, date). Falls
         * back to the application locale when null.
         */
        public readonly ?string $locale = null,
        /**
         * Display name of the guild the card is being posted to, used to frame
         * the cross-community indicator ("from outside {guild}"). Optional.
         */
        public readonly ?string $guildName = null,
        /**
         * Resolved cover image URL for the card thumbnail. The publisher (T05)
         * resolves this via ResolvesCoverImage (which does a filesystem
         * file_exists check) and passes it in — the renderer itself never
         * touches the filesystem, preserving its pure-transformer contract.
         * Null omits the thumbnail entirely.
         */
        public readonly ?string $coverImageUrl = null,
    ) {}
}
