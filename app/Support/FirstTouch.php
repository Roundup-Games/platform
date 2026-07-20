<?php

namespace App\Support;

use App\Services\PostHogAnalytics;

/**
 * Pure helpers for reducing signup first-touch attribution signals.
 *
 * Extracted from {@see PostHogAnalytics::identifyFirstTouch()}
 * so the same reduction logic is reused for both the analytics-tier PostHog
 * identify and the local persistence of the five write-once signup-attribution
 * columns on users (S02/T03). Without this extraction, the two consumers would
 * drift — the persisted attribution columns would diverge from the PostHog
 * person properties that drive the funnel, splitting the attribution signal
 * across two systems.
 *
 * Both methods are pure (no I/O, no framework bindings) so they can be
 * unit-tested in isolation without booting a request lifecycle.
 */
class FirstTouch
{
    /**
     * Extract the path component from a full URL or path-only string.
     *
     * Used to derive the SEO content context from session('url.intended')
     * — the protected URL Laravel's auth middleware stores when redirecting
     * a guest. The value may be a full URL (https://roundup.games/en/games/x)
     * or already a bare path; this returns just the path portion, or null
     * for empty/malformed input.
     *
     * Pure (no I/O, no framework bindings) so the session read stays in the
     * caller (PostHogAnalytics::extractIntendedPath keeps session('url.intended');
     * the signup controllers read the session directly). Centralizing the
     * path extraction here means the PostHog identify and the persisted
     * write-once signup-attribution columns derive content context from
     * the same logic.
     *
     * Examples:
     *   null                                       → null
     *   ''                                         → null
     *   'https://roundup.games/en/games/foo'       → '/en/games/foo'
     *   '/en/games/apply/foo'                      → '/en/games/apply/foo'
     *   'en/games/foo'                             → 'en/games/foo'
     *   'https://roundup.games' (no path)          → null
     *
     * Semantics match {@see PostHogAnalytics::extractIntendedPath()} byte
     * for byte (null when parse_url yields no non-empty path) so delegating
     * that method here is behavior-preserving by construction.
     */
    public static function extractPath(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        try {
            $path = parse_url($url, PHP_URL_PATH);

            return is_string($path) && $path !== '' ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reduce a raw Referer header to its hostname, when one can be resolved.
     *
     * Accepts a full URL (with scheme), a bare domain (no scheme), or
     * null/empty/malformed input. Returns null when no hostname can be
     * resolved, so callers can {@see array_filter()} the result into a
     * nullable write-once column without an extra guard.
     *
     * Privacy: returns the hostname only — full referer URLs may carry UTM
     * tags or PII in the query string, which are dropped here.
     *
     * Examples:
     *   null                                → null
     *   ''                                  → null
     *   'https://google.com/search?q=dnd'   → 'google.com'
     *   'http://example.co.uk:8080/path'    → 'example.co.uk'
     *   'google.com'                        → 'google.com'  (bare domain)
     *   'not a url'                         → null
     */
    public static function reduceDomain(?string $referer): ?string
    {
        if ($referer === null || $referer === '') {
            return null;
        }

        // parse_url resolves the host for full URLs that carry a scheme.
        $host = parse_url($referer, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }

        // Bare-domain input ('example.com', no scheme) has no host component
        // under parse_url — it parses as a path. Recognize it heuristically
        // so a Referer that arrived without a scheme still reduces to its
        // domain. Requires a dot + 2+ char alpha TLD, no spaces or path
        // separators, matching the shape of a real hostname.
        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $referer)) {
            return $referer;
        }

        return null;
    }

    /**
     * Detect the public content type and slug from a URL path.
     *
     * Matches known public-route patterns (after stripping an optional locale
     * prefix such as /en/):
     *   games/{slug} | games/apply/{slug}         → ['type' => 'game', …]
     *   campaigns/{slug} | campaigns/apply/{slug} → ['type' => 'campaign', …]
     *   venues/{slug}                             → ['type' => 'venue', …]
     *
     * Returns ['type' => null, 'slug' => null] for empty, unrecognized, or
     * malformed input. Callers {@see array_filter()} the result before
     * persisting so null fields are dropped rather than stored as explicit
     * nulls.
     *
     * @return array{type: string|null, slug: string|null}
     */
    public static function detectContentContext(?string $path): array
    {
        if ($path === null || $path === '') {
            return ['type' => null, 'slug' => null];
        }

        try {
            // Strip optional locale prefix: /en/... or en/... (Laravel's
            // $request->path() omits the leading slash).
            $stripped = preg_replace('#^/?[a-z]{2}/#', '', $path);

            if (preg_match('#^games/(?:apply/)?([^/]+)#', (string) $stripped, $m)) {
                return ['type' => 'game', 'slug' => $m[1]];
            }
            if (preg_match('#^campaigns/(?:apply/)?([^/]+)#', (string) $stripped, $m)) {
                return ['type' => 'campaign', 'slug' => $m[1]];
            }
            if (preg_match('#^venues/([^/]+)#', (string) $stripped, $m)) {
                return ['type' => 'venue', 'slug' => $m[1]];
            }
        } catch (\Throwable) {
            // Fall through to the null tuple — malformed input must never
            // break the signup flow.
        }

        return ['type' => null, 'slug' => null];
    }
}
