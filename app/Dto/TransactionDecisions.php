<?php

namespace App\Dto;

use App\Enums\ParticipantStatus;
use App\Services\ParticipantLifecycle;

/**
 * Mutable carrier for decisions made inside a DB::transaction() closure.
 *
 * Initialized before the transaction with default values, then assigned
 * inside the closure. The object reference is captured by the closure,
 * so mutations are visible after the transaction completes.
 */
class TransactionDecisions
{
    public bool $isPublic = false;

    public bool $isFull = false;

    /**
     * The participant status the join intake resolved for this application:
     * Pending (Applicant) on protected entities, Approved when a public
     * entity has room, Benched/Waitlisted overflow when it is full.
     *
     * Set inside the transaction from the participant row
     * {@see ParticipantLifecycle::createOrReactivate()} wrote,
     * so post-transaction flash, notification, and analytics code reads the
     * authoritative outcome instead of re-deriving the overflow decision.
     */
    public ?ParticipantStatus $participantStatus = null;
}
