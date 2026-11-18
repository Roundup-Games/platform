<?php

namespace App\Enums;

/**
 * The terminal outcome of a Discord-button LEAVE (drop) action.
 *
 * Parallel to {@see DiscordRsvpOutcome} (the join enum). Carries the
 * confirmation copy PATCHed to the interaction's @original response so the
 * clicker sees why their leave resolved the way it did — resolved from the
 * `notifications.content_rsvp_outcome_*` lang keys in the clicker's
 * interaction locale (MEM1741 — the deferred job carries the locale the
 * controller resolved from the interaction payload).
 */
enum DiscordLeaveOutcome: string
{
    /** The clicker successfully left the roster / waitlist / bench. */
    case Left = 'left';

    /** The clicker was not on the roster (nothing to leave). */
    case NotAParticipant = 'not_a_participant';

    /** The clicker is the game's host — hosts cannot leave their own game. */
    case OwnerCannotLeave = 'owner_cannot_leave';

    /** The game or user could not be resolved (defensive — missing data). */
    case NotFound = 'not_found';

    public function logValue(): string
    {
        return $this->value;
    }

    /**
     * Whether the departure actually changed the roster — gates the
     * best-effort card refresh (no point refreshing when nothing changed).
     */
    public function changedRoster(): bool
    {
        return $this === self::Left;
    }

    /**
     * The ephemeral confirmation copy PATCHed to the interaction's @original
     * response, resolved from the `notifications.content_rsvp_outcome_*` lang
     * keys in the clicker's locale. A null $locale renders in the app locale.
     */
    public function confirmationContent(?string $locale = null): string
    {
        return __("notifications.content_rsvp_outcome_{$this->value}", [], $locale);
    }
}
