<?php

namespace App\Support;

use Illuminate\Support\Facades\App;

/**
 * Table-locale resolution for GM-composed copy (Previously On digest,
 * one-shot Epilogue, recruitment summary).
 *
 * Composed copy always resolves under the TABLE's language — the
 * language the campaign/game plays in — never the acting user's
 * ambient UI locale, so the scheduler, an English-UI host, and the
 * table's own German host all produce byte-identical copy
 * (D221 composition contract; K002: format_date and enum labels read
 * the app locale, hence the strict set/restore discipline).
 *
 * Single source on purpose: this resolution was previously hand-copied
 * in PreviouslyOnService, OneShotEpilogueService, and
 * CampaignRecruitmentSummaryService.
 */
final class TableLocale
{
    /** The table's language, or the app fallback locale when blank. */
    public static function resolve(?string $language): string
    {
        $resolved = $language !== null && $language !== ''
            ? $language
            : config('app.fallback_locale', 'en');

        return is_string($resolved) && $resolved !== '' ? $resolved : 'en';
    }

    /**
     * Run $callback under the resolved table locale. The ambient locale
     * is always restored — finally — even when $callback throws or
     * loses a unique-index race.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function with(?string $language, callable $callback): mixed
    {
        $previous = App::getLocale();

        App::setLocale(self::resolve($language));

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}
