<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;

/**
 * The single agreement-lock predicate for participant-bearing entities
 * (Game, Campaign) — decisions D314 (games) and D317 (campaigns).
 *
 * The agreement is the set of Tier-1 fields (system, date/time, price,
 * capacity floor — classified in App\Support\Edit\FieldTiers). It locks the
 * moment the FIRST non-owner participant is confirmed and never unlocks:
 * the predicate is "has EVER had a confirmed non-owner participant", read as
 * the earliest approved_at among participants whose user_id differs from
 * owner_id.
 *
 * Deliberate properties, each pinned by tests/Feature/Models/AgreementLockTest:
 *  - NEVER filtered on current status. A member who was approved and later
 *    left, was removed or was benched still counts: a join-then-leave must
 *    not re-unlock the agreement (approved_at is never cleared on departure).
 *  - The owner row never locks. Owners are Approved participants from
 *    creation (OwnerParticipantService), so excluding user_id = owner_id is
 *    load-bearing — without it every entity would lock at creation.
 *  - LIVE query on every call. Nothing is cached or stored on the model: a
 *    stale "unlocked" is a silent bypass of a consent guard, so the lock
 *    must be visible the instant a participant is approved.
 *  - Members who were never approved (pending, waitlisted, benched) do not
 *    lock: only approved_at counts, and only Approved rows are ever stamped.
 *
 * Both participant tables carry approved_at, so the trait is identical for
 * both models. There is deliberately NO column probe and no "column missing
 * → unlocked" branch: that would be a fail-open path inside a consent guard.
 *
 * @property string $owner_id
 */
trait HasAgreementLock
{
    /**
     * When the agreement locked: the earliest approved_at among this
     * entity's non-owner participants, or null while nobody but the owner
     * has ever been confirmed (the agreement fields are still editable).
     *
     * Read through the participant model rather than as an aggregate so the
     * datetime cast applies — the owner UI renders this timestamp as
     * "Locked since …".
     */
    public function agreementLockedAt(): ?Carbon
    {
        return $this->participants()
            ->whereNotNull('approved_at')
            ->where('user_id', '!=', $this->owner_id)
            ->oldest('approved_at')
            ->first()?->approved_at;
    }

    /**
     * Whether the Tier-1 agreement fields are locked.
     *
     * The ONE predicate every enforcement surface (policy, owner form, admin
     * form) must read, so they cannot drift from each other.
     */
    public function isAgreementLocked(): bool
    {
        return $this->agreementLockedAt() !== null;
    }
}
