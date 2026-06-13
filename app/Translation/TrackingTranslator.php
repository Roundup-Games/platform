<?php

namespace App\Translation;

use Illuminate\Translation\Translator;

/**
 * Decorates Laravel's translator to detect missing translation keys at runtime.
 *
 * When a dotted key is requested and the translator returns it unchanged
 * (the "key as fallback" behavior), we record it as missing via the
 * MissingTranslationCollector singleton.
 *
 * Only records when the collector is enabled (APP_ENV=local).
 *
 * The "dotted key" check is important: our app strings use PHP group files
 * with dotted notation (e.g., 'games.action_create'). JSON-style bare keys
 * like 'Brand Name' return unchanged but aren't "missing" — they're the
 * English fallback by design. We don't use that pattern, but the guard
 * prevents false positives if someone does.
 */
class TrackingTranslator extends Translator
{
    /**
     * @param  array<string, mixed>  $replace
     * @return array<string, mixed>|string
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        $result = parent::get($key, $replace, $locale, $fallback);

        $collector = app(MissingTranslationCollector::class);

        if ($collector->isEnabled() && $result === $key && str_contains($key, '.')) {
            $collector->record($key, $locale);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $number
     * @param  array<string, mixed>  $replace
     */
    public function choice($key, $number, array $replace = [], $locale = null)
    {
        $result = parent::choice($key, $number, $replace, $locale);

        $collector = app(MissingTranslationCollector::class);

        if ($collector->isEnabled() && $result === $key && str_contains($key, '.')) {
            $collector->record($key, $locale);
        }

        return $result;
    }
}
