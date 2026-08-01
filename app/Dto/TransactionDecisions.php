<?php

namespace App\Dto;

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
     * The resolved overflow disposition (bench vs waitlist) for a full entity.
     * Null when the entity is not at capacity or the applicant is non-public
     * (no overflow routing needed). Set inside the transaction so post-tx
     * notification/analytics code reads the authoritative decision.
     *
     * @see OverflowStatus::for()
     */
    public ?OverflowStatus $overflow = null;
}
