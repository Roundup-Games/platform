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
     * response, resolved from the `notifications.content_rsvp_outcome_*` lang
     * keys in the clicker's locale (MEM1741 — the job carries the interaction
     * locale the controller resolved from the interaction payload). A null
     * $locale renders in the app locale. Copy canon per docs/GLOSSARY.md:
     * British 'cancelled' in EN values; DE uses Runde, nachrücken,
     * Ersatzbank, du-form.
     */
    public function confirmationContent(?string $locale = null): string
    {
        return __("notifications.content_rsvp_outcome_{$this->value}", [], $locale);
    }
}
