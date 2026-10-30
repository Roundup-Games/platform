<?php

namespace App\Enums;

enum ParticipantStatus: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Pending = 'pending';
    case Waitlisted = 'waitlisted';
    case Benched = 'benched';
    case Removed = 'removed';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Case set that blocks a (re)join on every surface — an active row
     * (Approved / Pending / Waitlisted / Benched) or a host removal
     * (Removed, a moderation block that only the host can reverse).
     *
     * Rejected rows deliberately stay reactivatable: depart() keeps them so
     * a self-leave can rejoin through the intake. Single source for
     * EntityJoinGate's eligibility predicate, ShareLinkJoinService's locked
     * re-check, and the apply / share-intent pre-checks — keep the copies
     * collapsed (M072 review follow-up).
     */
    public const BLOCKS_REJOIN = [
        self::Approved,
        self::Pending,
        self::Waitlisted,
        self::Benched,
        self::Removed,
    ];

    public function blocksRejoin(): bool
    {
        return in_array($this, self::BLOCKS_REJOIN, true);
    }

    /**
     * Raw column values of the rejoin-blocking set, for whereIn() guards.
     *
     * @return list<string>
     */
    public static function rejoinBlockingValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::BLOCKS_REJOIN);
    }

    public function label(): string
    {
        return match ($this) {
            self::Approved => __('common.status_approved'),
            self::Rejected => __('common.status_rejected'),
            self::Pending => __('common.status_pending'),
            self::Waitlisted => __('common.status_waitlisted'),
            self::Benched => __('common.status_benched'),
            self::Removed => __('common.status_removed'),
        };
    }

    /**
     * Display label for a raw string status value (application models store
     * plain strings rather than the enum). Falls back to the raw value for
     * any legacy value outside the enum set.
     */
    public static function labelFor(string $value): string
    {
        return self::tryFrom($value)?->label() ?? $value;
    }
}
