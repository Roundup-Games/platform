<?php

namespace App\Enums;

/**
 * Campaign cadence. Cases are declared in calendar order (shortest to
 * longest interval, irregular last) — cases()/values() feed every user-facing
 * enumeration (create form, admin, discovery filter) in exactly this order.
 * tests/Unit/Enums/RecurrenceTest.php locks this; keep both in sync when
 * adding a cadence.
 */
enum Recurrence: string
{
    case Weekly = 'weekly';
    case BiWeekly = 'bi-weekly';
    case Monthly = 'monthly';
    case Custom = 'custom';

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
            self::Weekly => __('campaigns.content_weekly'),
            self::BiWeekly => __('campaigns.content_bi-weekly'),
            self::Monthly => __('campaigns.content_monthly'),
            self::Custom => __('campaigns.content_custom'),
        };
    }

    /**
     * Display label for a raw column value (campaigns.recurrence is a plain
     * string column). Falls back to the raw value for any value outside the
     * enum set.
     */
    public static function labelFor(string $value): string
    {
        return self::tryFrom($value)?->label() ?? $value;
    }
}
