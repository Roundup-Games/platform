<?php

namespace App\Dto;

/**
 * Result for invite-friends batch operations.
 */
class InviteBatchResult
{
    public function __construct(
        public readonly int $invitedCount,
        public readonly int $skippedCount,
    ) {}
}
