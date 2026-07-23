<?php

namespace App\Enums;

use App\Jobs\ProcessDiscordRsvp;

/**
 * The resolution of a deferred Discord button RSVP, written by
 * {@see ProcessDiscordRsvp} after the participant write (M057/S03/T03).
 *
 * A closed set of terminal states that doubles as (a) the structured-log
 * `status` value in `discord_rsvp.completed` and (b) the selector for the
 * ephemeral confirmation copy PATCHed to the interaction's @original response.
 *
 * The five states map directly onto the slice's verification contract:
 *  - Approved      — a seat was open; the player joined the roster.
 *  - Waitlisted    — the game was full (non-bench-mode); routed to the waitlist.
 *  - Benched       — the game was full (bench-mode); routed to the bench.
 *  - AlreadyOnRoster — the clicker was already an active participant; no row written.
 *  - Refused       — a guard failed (owner, canceled/completed game, missing
 *                    game/user); no row written.
 */
enum DiscordRsvpOutcome: string
{
    case Approved = 'approved';
    case Waitlisted = 'waitlisted';
    case Benched = 'benched';
    case AlreadyOnRoster = 'already';
    case Refused = 'refused';

    /**
     * The structured-log `status` value for `discord_rsvp.completed`
     * (approved|waitlisted|benched|already|refused).
     */
    public function logValue(): string
    {
        return $this->value;
    }

    /**
     * Whether this outcome actually wrote a participant row. Used to decide
     * whether a card-roster refresh is worthwhile (no point refreshing when
     * nothing changed) and to keep the log honest.
     */
    public function wroteParticipant(): bool
    {
        return match ($this) {
            self::Approved, self::Waitlisted, self::Benched => true,
            self::AlreadyOnRoster, self::Refused => false,
        };
    }

    /**
     * The ephemeral confirmation copy PATCHed to the interaction's @original
     * response. Short, value-framed, and matches the slice's confirmation
     * vocabulary ('You're in' / 'Waitlisted' / 'On the bench' /
     * 'You're already on the roster'). Hardcoded English to match T02's
     * unlinked-deep-link copy precedent (localization is a follow-up — the job
     * does not carry the interaction locale).
     */
    public function confirmationContent(): string
    {
        return match ($this) {
            self::Approved => "You're in! Your seat is saved. See you at the game. ✅",
            self::Waitlisted => "You're on the waitlist — we'll bump you up here the moment a seat opens.",
            self::Benched => "You're on the bench for this one — hang tight, we'll promote you if a seat frees up.",
            self::AlreadyOnRoster => "You're already on the roster for this game. 🎲",
            self::Refused => "This RSVP couldn't be processed — the game may be full, canceled, or no longer accepting joins.",
        };
    }
}
