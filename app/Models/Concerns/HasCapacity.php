<?php

namespace App\Models\Concerns;

use App\Enums\ParticipantStatus;

/**
 * Capacity primitives for participant-bearing entities (Game, Campaign).
 *
 * The single source of truth for "is this entity full?" and "how many approved
 * participants does it have?". Centralising the predicate here kills the drift
 * where the write-path services re-derived `approvedCount >= max_players` inline
 * — in two variants that disagreed on `max_players = 0`.
 *
 * Semantics (locked by {@see CapacityCountingTest} and
 * {@see ParticipantServiceTest}): `max_players` of null OR 0 means unlimited
 * capacity — {@see isAtCapacity()} returns false. Only a positive `max_players`
 * constrains the roster.
 *
 * Both methods issue a fresh relationship query, so they reflect post-lock state
 * when called inside a `lockForUpdate()` transaction on the entity.
 *
 * @property int|null $max_players
 */
trait HasCapacity
{
    /**
     * Count of approved participants. The owner is an explicit participant row,
     * so they are counted naturally — no +1 adjustment.
     */
    public function approvedParticipantCount(): int
    {
        return $this->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();
    }

    /**
     * Whether the entity has reached its max_players capacity.
     *
     * Returns false when `max_players` is null or 0 (unlimited capacity).
     * This is the single canonical predicate — every "is it full?" decision
     * routes through here rather than re-deriving the comparison inline.
     */
    public function isAtCapacity(): bool
    {
        if (! $this->max_players) {
            return false;
        }

        return $this->approvedParticipantCount() >= $this->max_players;
    }
}
