<?php

namespace App\Dto;

/**
 * Standardised result for service operations that succeed or fail with a reason.
 *
 * Replaces the ad-hoc array{success: bool, reason: string} shape used across
 * AttendanceService (4 methods), GmSocialLinkService (2 methods),
 * VenueProposalService, and others.
 */
class OperationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $reason = '',
    ) {}

    public static function ok(string $reason = ''): self
    {
        return new self(success: true, reason: $reason);
    }

    public static function fail(string $reason): self
    {
        return new self(success: false, reason: $reason);
    }

    public function failed(): bool
    {
        return ! $this->success;
    }
}
