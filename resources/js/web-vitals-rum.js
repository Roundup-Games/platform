import { onCLS, onINP, onLCP, onTTFB } from 'web-vitals';

/**
 * First-party Core Web Vitals field data (RUM) via PostHog.
 *
 * Why this exists alongside cwv:check: Google's CrUX field data needs a
 * traffic threshold this site will not cross soon (PSI literally reports
 * "insufficient real-user data"), while the weekly PSI job samples 13
 * fixed representative URLs in the lab. This module reports what real
 * visitors actually experience, on every public page, segmented by the
 * SAME template keys as config/cwv.php — so field and lab data join on
 * one dimension later (trend: `web_vitals` grouped by `template`).
 *
 * Scope decisions (deliberate):
 * - Consent-gated: initialized from posthog.js only after the analytics
 *   cookie consent activates the SDK. Late consent still catches LCP/CLS/
 *   TTFB via buffered PerformanceObserver entries; early INP interactions
 *   before init are lost — acceptable, conservative, GDPR-honest.
 * - Full page loads only: wire:navigate soft swaps change the URL without
 *   a new document; attributing whole-document LCP/CLS to the new path
 *   would be wrong, so metrics are reported once per real navigation.
 * - Four events per pageview (LCP, INP, CLS, TTFB) with web-vitals'
 *   default reporting (final value per metric) — no event storm.
 */

/** Locales used in the URL scheme ({locale}/path). */
const LOCALES = ['en', 'de'];

/**
 * Route patterns → cwv.php-aligned template keys. Order matters: first
 * match wins. Applied AFTER the locale prefix is stripped.
 *
 * @type {Array<[RegExp, string]>}
 */
const TEMPLATE_ROUTES = [
    [/^\/?$/, 'home'],
    [/^\/discover\/board-games/, 'discovery-board-games'],
    [/^\/discover\/adventures/, 'discovery-adventures'],
    [/^\/discover/, 'discovery'],
    [/^\/game-systems\/.+/, 'game-system-detail'],
    [/^\/game-systems/, 'game-systems-index'],
    [/^\/games\/.+/, 'game-detail'],
    [/^\/campaigns\/.+/, 'campaign-detail'],
    [/^\/gms/, 'gm-directory'],
    [/^\/u\/.+/, 'profile-public'],
    [/^\/venue\/.+/, 'venue-detail'],
    [/^\/venues/, 'venues-index'],
    [/^\/events/, 'events'],
    [/^\/cities\/.+/, 'city-hub'],
    [/^\/cities/, 'city-hubs-index'],
    [/^\/pricing/, 'pricing'],
    [/^\/how-it-works/, 'static-how-it-works'],
    [/^\/for-organizers/, 'static-for-organizers'],
    [/^\/about/, 'static-about'],
];

/**
 * Map a pathname to its page-class key (cwv.php template naming) plus the
 * locale it was served under.
 *
 * @param {string} pathname e.g. "/de/game-systems/carcassonne"
 * @returns {{ template: string, locale: string }} template falls back to the
 *   first path segment (or 'home' for the bare root), never empty.
 */
export function classifyPath(pathname) {
    let path = pathname || '/';
    let locale = 'en';

    const localeMatch = path.match(/^\/(en|de)(?=\/|$)/);
    if (localeMatch) {
        locale = localeMatch[1];
        path = path.slice(localeMatch[0].length) || '/';
    }

    for (const [pattern, template] of TEMPLATE_ROUTES) {
        if (pattern.test(path)) {
            return { template, locale };
        }
    }

    const segment = path.split('/').filter(Boolean)[0];
    return { template: segment ? `other-${segment}` : 'home', locale };
}

/**
 * Start reporting web vitals through the given capture callback.
 *
 * @param {(event: string, properties: Record<string, string|number>) => void} capture
 *   Must be a no-op-safe wrapper (PostHog's capture on the consented SDK).
 * @returns {void}
 */
export function initWebVitalsRum(capture) {
    if (typeof capture !== 'function' || typeof window === 'undefined') return;

    const { template, locale } = classifyPath(window.location.pathname);

    /** @param {import('web-vitals').Metric} metric */
    const report = (metric) => {
        capture('web_vitals', {
            metric: metric.name,
            value: metric.name === 'CLS' ? Math.round(metric.value * 1000) / 1000 : Math.round(metric.value),
            rating: metric.rating,
            template,
            locale,
            path: window.location.pathname,
        });
    };

    onLCP(report);
    onINP(report);
    onCLS(report);
    onTTFB(report);
}
