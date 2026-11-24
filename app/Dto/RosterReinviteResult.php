<?php

namespace App\Dto;

/**
 * Outcome of one roster re-invite (ParticipantService::inviteFormerRoster, D321).
 *
 * The four counts partition the eligible set — every approved non-owner
 * participant of the cancelled predecessor lands in exactly one:
 *
 *  - invited:          a Pending/Invited row was created and the invitation sent.
 *  - skippedCapacity:  left out because the replacement had no free seat by the
 *                      time their turn came (the predecessor's approval order decides).
 *  - skippedAlready:   already a participant of the replacement — joined on their own,
 *                      or a concurrent invitation won the unique-constraint race.
 *  - skippedMissing:   the row has no resolvable account to invite.
 */
class RosterReinviteResult
{
    /**
     * @param  list<string>  $changedFields  Machine column names of the Tier-1 fields that differ between the
     *                                       cancelled game and the replacement — what every invitation states.
     */
    public function __construct(
        public readonly int $invited,
        public readonly int $skippedCapacity,
        public readonly int $skippedAlready,
        public readonly int $skippedMissing,
        public readonly array $changedFields = [],
    ) {}

    /** Everyone who was eligible but did not get an invitation, whatever the reason. */
    public function skipped(): int
    {
        return $this->skippedCapacity + $this->skippedAlready + $this->skippedMissing;
    }
}
