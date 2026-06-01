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
}
