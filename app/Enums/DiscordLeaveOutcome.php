<?php

namespace App\Enums;

/**
 * The terminal outcome of a Discord-button LEAVE (drop) action.
 *
 * Parallel to {@see DiscordRsvpOutcome} (the join enum). Carries the
 * confirmation copy PATCHed to the interaction's @original response so the
 * clicker sees why their leave resolved the way it did. Hardcoded English to
 * match the join enum's precedent (localization is a follow-up — the deferred
 * job does not carry the interaction locale).
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
     * response.
     */
    public function confirmationContent(): string
    {
        return match ($this) {
            self::Left => "You've left this game. Your seat has been released — see you at the next one. 👋",
            self::NotAParticipant => "You're not on the roster for this game, so there's nothing to leave.",
            self::OwnerCannotLeave => "You're hosting this session — hosts can't leave their own game.",
            self::NotFound => "This game couldn't be found. It may have been removed.",
        };
    }
}
