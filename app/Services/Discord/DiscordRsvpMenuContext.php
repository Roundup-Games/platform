<?php

namespace App\Services\Discord;

use App\Enums\ParticipantStatus;

/**
 * The clicker's current roster state for a game, pre-resolved by the controller
 * and carried into {@see DiscordRsvpMenuRenderer}.
 *
 * Mirrors the pure-transformer + context-DTO pattern ({@see DiscordCardContext}):
 * the renderer performs zero DB queries — the caller resolves the clicker's
 * identity, their current participant status (if any), their waitlist position,
 * and the roster counts, then hands them in here.
 *
 * All roster fields degrade gracefully: a null currentStatus renders the
 * "not on the roster" join path; a null maxPlayers renders an open-roster line.
 */
final class DiscordRsvpMenuContext
{
    public function __construct(
        /**
         * Whether the clicker is the host of this game. Hosts get a read-only
         * "you're hosting" menu (no self-RSVP / self-leave).
         */
        public readonly bool $isOwner = false,
        /**
         * The clicker's current participant status for this game, or null when
         * they are not on the roster. Only ACTIVE statuses (Approved /
         * Waitlisted / Benched / Pending) are carried here — Removed/Rejected
         * are treated as "not on roster" by the caller before constructing
         * this DTO.
         */
        public readonly ?ParticipantStatus $currentStatus = null,
        /**
         * 1-based waitlist position, or null when not waitlisted / unknown.
         * Pure presentational — the caller may leave it null if the position
         * is costly to compute; the menu degrades to a positionless message.
         */
        public readonly ?int $waitlistPosition = null,
        /**
         * Approved (confirmed) participant count for the roster line.
         */
        public readonly int $approvedCount = 0,
        /**
         * The game's max_players, or null for unlimited (open roster).
         */
        public readonly ?int $maxPlayers = null,
        /**
         * Base URL for the "View on roundup" deep link. Falls back to
         * config('app.url') in the menu when null.
         */
        public readonly ?string $appUrl = null,
    ) {}
}
