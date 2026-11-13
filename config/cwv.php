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

        // ── 2026-11-12 expansion — representative URLs verified live (200,
        // expected template shape) before adding. Entity detail URLs are
        // PERMANENT per the retention model (public + non-canceled games and
        // campaigns stay indexable and guest-renderable indefinitely), so
        // these are set-and-forget; reselection would only ever be about
        // representativeness (e.g. wanting the completed-game lifecycle
        // layout once one exists), never URL death. Deferred until inventory
        // exists: campaign public detail (0 public URLs), events (0
        // published), game-detail-completed (no completed public game yet).
        // Quota: 13 templates × 2 locales × 2 strategies = 52 PSI runs/week.
        'game-systems-index' => '/game-systems',
        // Boardgame template shape — cover-backed system (hero webp path).
        'game-detail' => '/games/cb6bccb2-7de1-44b1-a2fd-c45de82b280f',
        // TTRPG template shape (player_range/creator/designers modules);
        // icon-fallback hero path — many TTRPG systems carry no cover art.
        'game-system-ttrpg' => '/game-systems/dungeons-and-dragons-5e',
        'venue-detail' => '/venue/spielwiese',
        // GM public profile — the fandom/adoption surface GMs link to from
        // external communities; badge + bio + reviews modules.
        'gm-public-profile' => '/u/vasile-burghelea',
        'gm-directory' => '/gms',
        'for-organizers' => '/for-organizers',
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
