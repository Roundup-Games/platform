<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * City name normalization (D174) — the matching key for alias rules and
 * suggestions. Deterministic, locale-agnostic:
 *
 *   ascii-fold (München -> Munchen, already Laravel's Str behavior)
 *   -> lower -> German transliteration folding (ue/oe/ae -> u/o/a)
 *   -> slug
 *
 * "München", "Muenchen", "muenchen " all produce "munchen"; a confirmed
 * merge alias (e.g. "munchin") is stored under the same key so intake
 * resolves it to the surviving entity without any fuzzy guessing.
 */
final class CityNormalizer
{
    public static function key(string $city): string
    {
        $ascii = Str::ascii(mb_strtolower(trim($city)));

        $folded = str_replace(
            ['ue', 'oe', 'ae', 'ss'],
            ['u', 'o', 'a', 's'],
            $ascii,
        );

        return Str::slug($folded);
    }
}
