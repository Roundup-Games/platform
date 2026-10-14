<?php

return [

    /*
    |--------------------------------------------------------------------------
    | City Hub Pages (/cities/{slug})
    |--------------------------------------------------------------------------
    |
    | Copy for the public city hub landing pages (M062). :city is replaced
    | with the canonical cluster display name (CitySummary::city). T03 adds
    | section list/empty-state keys; T04 adds SEO title/description keys.
    | Keys are flat with recognized prefixes per the i18n:check convention.
    |
    */

    'label_city_hub' => 'City hub',

    // ── Index page (/cities, M066/S06/T04) ─────────────────
    // The crawlable front door listing every live hub; cards reuse the
    // per-hub count labels below.
    'label_city_hubs' => 'City hubs',

    'heading_city_hubs_index' => 'Tabletop gaming by city',

    'content_index_intro' => 'City hubs bundle a local scene\'s public activity into one page: upcoming game sessions, campaigns, and events, plus the verified venues to play at. Hubs go live automatically once a city has enough upcoming public activity or verified venues — the directory grows as local scenes do.',

    'label_featured' => 'Featured',

    'empty_city_index' => 'No city hubs are live yet — they appear automatically as local scenes build up public activity and verified venues.',

    'empty_city_index_cta' => 'Discover games near you',

    'nav_cities' => 'Cities',

    'heading_city_hub' => 'Tabletop gaming in :city',

    'content_intro' => 'Upcoming public game sessions, campaigns, and events in and around :city — plus verified venues to play at.',

    'label_upcoming_sessions_count' => ':count upcoming public sessions',

    'label_verified_venues_count' => ':count verified venues',

    'section_upcoming_sessions' => 'Upcoming Sessions',

    'section_venues' => 'Verified Venues',

    // Full-list onward links at the bottom of each section (T03).
    'action_view_all_board_games' => 'Browse all board game sessions',
    'action_view_all_adventures' => 'Browse all adventures',
    'action_view_all_events' => 'Events in :city',
    'action_view_all_venues' => 'All venues in :city',

    // A qualifying cluster can still have an empty section (a
    // venue-qualified city has no sessions; a sessions-qualified city may
    // have no venues). Empty states always link onward — never a dead panel.
    'empty_upcoming_sessions' => 'No public sessions are scheduled in :city right now.',
    'empty_upcoming_sessions_cta' => 'Find or start a session',
    'empty_venues' => 'No verified venues are listed for :city yet.',
    'empty_venues_cta' => 'Browse the venue directory',

    // Per-locale SEO (T04). :city renders the canonical cluster display
    // name; the site-name suffix is appended by the SEO package config.
    'seo_title' => 'Tabletop gaming in :city',
    'seo_description' => 'Find upcoming public board game sessions, campaigns, and events in :city, plus verified venues to play at.',

    // Index page SEO (M066/S06/T04); the site-name suffix is appended by
    // the SEO package config.
    'seo_title_index' => 'Tabletop gaming city hubs',
    'seo_description_index' => 'Browse every live city hub: upcoming public board game sessions, campaigns, and events, plus verified venues to play at — city by city.',

];
