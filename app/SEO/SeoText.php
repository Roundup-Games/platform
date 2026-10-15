<?php

namespace App\SEO;

/**
 * Bounds and hygiene for user-visible SEO text (titles, meta descriptions).
 *
 * Single authority for the SERP display limits enforced by the global
 * SEODataTransformer in AppServiceProvider — every rendered title passes
 * through title(), every meta description through description(). Generated
 * entity metadata (BGG catalog copy, lang-file statics) and curated
 * seo_title/seo_description overrides alike are guarded here, so no page
 * can serve an over-length title, an unbounded description, raw HTML
 * entities, or source copy wrapped in quotes.
 */
final class SeoText
{
    /** SERP-safe title budget, suffix included (Google truncates ~600px / ~60 chars). */
    public const TITLE_LIMIT = 60;

    /** SERP-safe meta description budget (Google truncates ~155-160 chars). */
    public const DESCRIPTION_LIMIT = 160;

    private const ELLIPSIS = '…';

    /** Quote characters a source text may be wrapped in (BGG copy, lang values). */
    private const WRAPPING_QUOTES = " \u{00A0}\"'«»‹›„“”‘’";

    /**
     * Normalize source text: decode HTML entities, strip markup, collapse
     * whitespace. Always safe for attribute contexts — never returns null.
     */
    public static function clean(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = strip_tags($decoded);

        return trim((string) preg_replace('/\s+/u', ' ', $stripped));
    }

    /**
     * SERP-safe title: cleaned and bounded, truncated at a word boundary.
     * The SEODataTransformer applies this to the final title (site suffix
     * already appended by the package), so TITLE_LIMIT covers the full tag.
     */
    public static function title(?string $text, int $limit = self::TITLE_LIMIT): string
    {
        return self::truncate(self::clean($text), $limit);
    }

    /**
     * SERP-safe meta description: cleaned, one layer of wrapping quotes
     * removed, bounded at a word boundary. Null when the source carries no
     * usable text (callers keep the null = package-fallback contract).
     */
    public static function description(?string $text, int $limit = self::DESCRIPTION_LIMIT): ?string
    {
        $clean = self::clean($text);

        if ($clean === '') {
            return null;
        }

        $unwrapped = trim($clean, self::WRAPPING_QUOTES);

        if ($unwrapped === '') {
            return null;
        }

        return self::truncate($unwrapped, $limit);
    }

    /**
     * Bound text at a word boundary, appending an ellipsis only when a cut
     * happened. Never returns an empty string for non-empty input: a single
     * word longer than the limit is hard-cut rather than erased.
     */
    private static function truncate(string $text, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit - 1); // reserve one char for the ellipsis

        $space = mb_strrpos($cut, ' ');

        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }

        $trimmed = rtrim($cut, " \t.,;:!?—–-".self::ELLIPSIS);

        if ($trimmed === '') {
            $trimmed = $cut;
        }

        return $trimmed.self::ELLIPSIS;
    }
}
