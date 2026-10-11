<?php

namespace App\Support;

use Symfony\Component\Intl\Countries;

/**
 * The platform country standard (D173): locations.countries (and every
 * intake that feeds them) stores ISO 3166-1 alpha-3 codes ('DEU'), the
 * form the geocoder already writes. Display names are localized through
 * symfony/intl — never hand-maintained.
 *
 * History: free-text intake let ISO-2 ('DE', 'CH', 'AT') and mixed-case
 * values into the column (prod audit: DE=15 vs DEU=7, AUT/CHE/AT/CH/US/
 * empty), splitting country grouping and filters. The 2026_10_09_190000
 * migration normalized stored values; this class keeps new writes clean:
 * always set country through a component that uses normalize(), and
 * validate with rule().
 */
final class Country
{
    /** Normalize raw input: trim + uppercase. Never invents a code. */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim($value));

        return $normalized === '' ? null : $normalized;
    }

    /** Is this a valid ISO 3166-1 alpha-3 country code? */
    public static function isValid(string $alpha3): bool
    {
        return Countries::alpha3CodeExists($alpha3);
    }

    /** Localized display name for a valid alpha-3 code. */
    public static function label(string $alpha3, ?string $locale = null): string
    {
        return Countries::getAlpha3Name($alpha3, $locale ?? app()->getLocale());
    }

    /**
     * All valid alpha-3 codes => localized names, sorted by name — the
     * single options source for every country select.
     *
     * @return array<string, string>
     */
    public static function options(?string $locale = null): array
    {
        $names = Countries::getAlpha3Names($locale ?? app()->getLocale());
        asort($names);

        return $names;
    }

    /**
     * Upgrade a legacy value to the standard: alpha-2 ('DE') becomes its
     * alpha-3 code; anything else passes through normalize() untouched
     * (unknown values are the caller's problem, never silently remapped).
     */
    public static function upgradeToAlpha3(?string $value): ?string
    {
        $normalized = self::normalize($value);

        if ($normalized === null) {
            return null;
        }

        if (strlen($normalized) === 2 && Countries::exists($normalized)) {
            return Countries::getAlpha3Code($normalized);
        }

        return $normalized;
    }
}
