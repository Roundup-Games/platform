<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Single implementation of the platform's slug algorithm — shared by User
 * and Location (and any future slug-carrying model).
 *
 * The transliteration map is a hard-won cross-platform determinism fix:
 * iconv's ASCII//TRANSLIT output is locale- and system-dependent (on macOS
 * it emits combining-character sequences that downstream stripping handles
 * inconsistently), so the full Latin map runs first and iconv only sees the
 * residue. Never fork or localize this map — users and venues must slug
 * byte-identically for short-link and SEO surface consistency.
 */
class SlugService
{
    /**
     * Slugify a display name: transliterate to ASCII, strip non-slug
     * characters, hyphenate, lowercase.
     */
    public static function generate(string $name): string
    {
        // Transliterate to ASCII first (ü→ue, ö→oe, ä→ae, é→e, etc.)
        $slug = self::transliterate($name);
        // Remove anything that's not ASCII letters, numbers, spaces, or hyphens
        $slug = (string) preg_replace('/[^a-zA-Z0-9\s-]/', '', $slug);
        // Replace spaces with hyphens
        $slug = (string) preg_replace('/\s+/', '-', trim($slug));
        // Collapse consecutive hyphens
        $slug = (string) preg_replace('/-+/', '-', $slug);
        // Lowercase
        $slug = mb_strtolower($slug);
        // Trim leading/trailing hyphens
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * Generate a unique slug within the given query's table, appending
     * incremental digits on collision.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  A fresh query for the target model
     *                                  (cloned per collision probe)
     * @param  string  $column  The slug column name
     * @param  string  $emptyFallback  Base slug when generation yields ''
     *                                 ('user' for users, 'venue' for locations)
     * @param  string|null  $ignoreId  Primary key to exclude (updates)
     */
    public static function unique(Builder $query, string $column, string $name, string $emptyFallback, ?string $ignoreId = null): string
    {
        $baseSlug = static::generate($name);

        if ($baseSlug === '') {
            $baseSlug = $emptyFallback;
        }

        $slug = $baseSlug;
        $counter = 1;

        $exists = function (string $candidate) use ($query, $column, $ignoreId): bool {
            $check = (clone $query)->where($column, $candidate);

            if ($ignoreId !== null) {
                $check->where($query->getModel()->getKeyName(), '!=', $ignoreId);
            }

            return $check->exists();
        };

        while ($exists($slug)) {
            $counter++;
            $slug = $baseSlug.'-'.$counter;
        }

        return $slug;
    }

    /**
     * Transliterate Unicode characters to ASCII equivalents.
     * Covers Germanic (ä→ae, ö→oe, ü→ue, ß→ss), Nordic, Slavic,
     * and other common European characters using iconv with //TRANSLIT.
     */
    private static function transliterate(string $text): string
    {
        // Deterministic, platform-independent transliteration — see class
        // docblock for why iconv alone is not trusted.
        $map = [
            // German expansions (multi-char)
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
            // Nordic
            'æ' => 'ae', 'ø' => 'oe', 'å' => 'aa',
            'Æ' => 'Ae', 'Ø' => 'Oe', 'Å' => 'Aa',
            // Latin accented vowels
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'ñ' => 'n', 'ç' => 'c',
            // Slavic / Central European
            'ž' => 'z', 'š' => 's', 'č' => 'c', 'ř' => 'r',
            'ď' => 'd', 'ť' => 't', 'ň' => 'n',
            'ł' => 'l', 'ś' => 's', 'ź' => 'z',
            'Ž' => 'Z', 'Š' => 'S', 'Č' => 'C', 'Ř' => 'R',
            'Ď' => 'D', 'Ť' => 'T', 'Ň' => 'N',
            'Ł' => 'L', 'Ś' => 'S', 'Ź' => 'Z',
            // Uppercase accented vowels
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U',
            'Ý' => 'Y', 'Ñ' => 'N', 'Ç' => 'C',
        ];
        $text = strtr($text, $map);

        // iconv handles any remaining characters the map doesn't cover.
        // Worst case (no TRANSLIT support / unmappable char) it returns false
        // and we keep the pre-mapped text as-is.
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return $transliterated !== false ? $transliterated : $text;
    }
}
