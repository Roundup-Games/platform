<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Request;

/**
 * Resolve the preferred locale for the current request.
 *
 * Priority: session > Accept-Language header > app fallback.
 * Used by the root redirect and the locale-less URL catch-all.
 */
if (! function_exists('resolvePreferredLocale')) {
    function resolvePreferredLocale(): string
    {
        $locale = session('locale')
            ?? Request::getPreferredLanguage(config('app.available_locales'))
            ?? config('app.fallback_locale');

        if (! in_array($locale, config('app.available_locales'), true)) {
            $locale = config('app.fallback_locale');
        }

        return $locale;
    }
}

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

        $is24Hour = in_array($locale, ['de', 'fr', 'es', 'it', 'nl', 'pt', 'pl', 'cs', 'ru', 'ja', 'zh', 'ko'], true);

        return match ($type) {
            'date'            => $date->isoFormat($is24Hour ? 'D. MMMM YYYY' : 'MMM D, YYYY'),
            'short_date'      => $date->isoFormat($is24Hour ? 'D. MMM' : 'MMM D'),
            'datetime'        => $date->isoFormat($is24Hour ? 'D. MMMM YYYY, HH:mm' : 'MMM D, YYYY [at] h:mm A'),
            'short_month_day' => $date->isoFormat($is24Hour ? 'D. MMMM YYYY' : 'MMM D, YYYY'),
            default           => $date->isoFormat($is24Hour ? 'D. MMMM YYYY' : 'MMM D, YYYY'),
        };
    }
}

/**
 * Format a monetary value according to the current app locale.
 *
 * Zero values show "Free" / translated equivalent via __().
 *
 * @param  int|float  $amount   The amount to format (cents when $inCents=true, whole currency when false).
 * @param  bool       $inCents  True for event fees (stored as cents), false for game/campaign prices (stored as float dollars).
 */
if (! function_exists('format_currency')) {
    function format_currency(int|float $amount, bool $inCents = true): string
    {
        $value = $inCents ? $amount / 100 : $amount;

        if ($value == 0) {
            return __('billing.content_free');
        }

        $locale = app()->getLocale();

        // European locales use comma decimal, period thousands, € suffix
        $europeanLocales = ['de', 'fr', 'es', 'it', 'nl', 'pt', 'pl', 'cs', 'ru'];

        if (in_array($locale, $europeanLocales, true)) {
            return number_format($value, 2, ',', '.') . ' €';
        }

        // English and other locales: $ prefix, period decimal
        return '$' . number_format($value, 2);
    }
}

/**
 * Format bytes into a human-readable string (e.g. "1.5 MB").
 *
 * Shared by data export command and Filament ticket actions.
 */
function format_bytes(int $bytes): string
{
    if ($bytes < 0) {
        $bytes = 0;
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;

    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, 2).' '.$units[$i];
}
