<?php

use Carbon\Carbon;

/**
 * Format a Carbon date according to the current app locale.
 *
 * Types:
 *  - 'date'            EN: "Apr 14, 2026"  / DE: "14. April 2026"
 *  - 'short_date'      EN: "Apr 14"        / DE: "14. Apr"
 *  - 'datetime'        EN: "Apr 14, 2026 at 2:30 PM" / DE: "14. April 2026, 14:30"
 *  - 'short_month_day' EN: "Apr 14, 2026"  / DE: same as 'date'
 */
if (! function_exists('format_date')) {
    function format_date(?Carbon $date, string $type = 'date'): string
    {
        if ($date === null) {
            return '';
        }

        $locale = app()->getLocale();

        if ($locale === 'de') {
            return match ($type) {
                'date'            => $date->isoFormat('D. MMMM YYYY'),
                'short_date'      => $date->isoFormat('D. MMM'),
                'datetime'        => $date->isoFormat('D. MMMM YYYY, HH:mm'),
                'short_month_day' => $date->isoFormat('D. MMMM YYYY'),
                default           => $date->isoFormat('D. MMMM YYYY'),
            };
        }

        // English (default)
        return match ($type) {
            'date'            => $date->format('M j, Y'),
            'short_date'      => $date->format('M j'),
            'datetime'        => $date->format('M j, Y \a\t g:i A'),
            'short_month_day' => $date->format('M d, Y'),
            default           => $date->format('M j, Y'),
        };
    }
}

/**
 * Format a monetary value according to the current app locale.
 *
 * EN: "$5.00"  /  DE: "5,00 €"
 * Zero values show "Free" / "Kostenlos" via __().
 *
 * @param  int|float  $amount   The amount to format (cents when $inCents=true, whole currency when false).
 * @param  bool       $inCents  True for event fees (stored as cents), false for game/campaign prices (stored as float dollars).
 */
if (! function_exists('format_currency')) {
    function format_currency(int|float $amount, bool $inCents = true): string
    {
        $value = $inCents ? $amount / 100 : $amount;

        if ($value == 0) {
            return __('Free');
        }

        $locale = app()->getLocale();

        if ($locale === 'de') {
            return number_format($value, 2, ',', '.') . ' €';
        }

        // English (default)
        return '$' . number_format($value, 2);
    }
}
