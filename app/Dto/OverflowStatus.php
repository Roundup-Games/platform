<?php

namespace App\Dto;

use App\Enums\ParticipantStatus;
use App\Services\OverflowRouter;

/**
 * The overflow disposition for a participant who cannot enter the Approved
 * roster because the entity is at capacity.
 *
 * Replaces the array{status, timestamp_column} shape previously returned by
 * ParticipantService::resolveOverflowStatus and re-derived inline in the
 * GameDetail share-link apply path. The decision is bench-mode-driven: a
 * bench-mode entity routes overflow to Benched; everything else routes to
 * Waitlisted.
 *
 * @see OverflowRouter::resolve()
 */
final readonly class OverflowStatus
{
    public function __construct(
        public ParticipantStatus $status,
        public string $timestampColumn,
    ) {}

    /**
     * Resolve the overflow status for a full entity.
     *
     * Bench-mode entities (campaign sessions with bench_mode=true) route to
     * Benched; everything else routes to Waitlisted. This is the single
     * decision point — every overflow placement path routes through here.
     */
    public static function for(bool $isBenchMode): self
    {
        if ($isBenchMode) {
            return new self(
                status: ParticipantStatus::Benched,
                timestampColumn: 'benched_at',
            );
        }

        return new self(
            status: ParticipantStatus::Waitlisted,
            timestampColumn: 'waitlisted_at',
        );
    }

    public function isWaitlist(): bool
    {
        return $this->status === ParticipantStatus::Waitlisted;
    }

    public function isBench(): bool
    {
        return $this->status === ParticipantStatus::Benched;
    }

    /**
     * The status value as stored in the database column.
     */
    public function statusValue(): string
    {
        return $this->status->value;
    }
}
