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

    public bool $benchMode = false;
}
