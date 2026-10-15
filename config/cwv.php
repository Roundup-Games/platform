<?php

/**
 * Core Web Vitals monitoring configuration (SEO track 2026-10-15).
 *
 * TEMPLATES: representative public pages — the home page, the two
 * discovery forks, one city hub, one game-system detail (the catalog
 * template that dominates the indexable surface), and one static page.
 * Each key maps to a locale-relative path; every locale is checked.
 *
 * Add or rotate templates here — no code changes needed.
 */

return [
    'templates' => [
        'home' => '/',
        'discovery-board-games' => '/discover/board-games',
        'discovery-adventures' => '/discover/adventures',
        'city-hub' => '/cities/berlin',
        'game-system' => '/game-systems/carcassonne',
        'static-pricing' => '/pricing',
    ],

    'locales' => ['en', 'de'],

    'strategies' => ['mobile', 'desktop'],

    /**
     * Alert thresholds — a check logs cwv.degraded when any of these fail.
     * Performance is the Lighthouse 0-100 score; the others are Google's
     * "good" CWV boundaries. Alerting is log-based v1 (structured lines
     * surface in error tracking); richer escalation can subscribe later.
     */
    'thresholds' => [
        'performance_score' => 80,
        'lcp_ms' => 2500,
        'inp_ms' => 200,
        'cls' => 0.1,
    ],
];
