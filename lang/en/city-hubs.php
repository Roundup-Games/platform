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
    |
    */

    'chip_city_hub' => 'City hub',

    'heading' => 'Tabletop gaming in :city',

    'intro' => 'Upcoming public game sessions, campaigns, and events in and around :city — plus verified venues to play at.',

    'stats' => [
        'upcoming_sessions' => ':count upcoming public sessions',
        'verified_venues' => ':count verified venues',
    ],

    'sections' => [
        'upcoming_sessions' => 'Upcoming Sessions',
        'venues' => 'Verified Venues',
    ],

    // Full-list onward links at the bottom of each section (T03).
    'lists' => [
        'view_all_board_games' => 'Browse all board game sessions',
        'view_all_adventures' => 'Browse all adventures',
        'view_all_events' => 'Events in :city',
        'view_all_venues' => 'All venues in :city',
    ],

    // A qualifying cluster can still have an empty section (a
    // venue-qualified city has no sessions; a sessions-qualified city may
    // have no venues). Empty states always link onward — never a dead panel.
    'empty' => [
        'upcoming_sessions' => 'No public sessions are scheduled in :city right now.',
        'upcoming_sessions_cta' => 'Find or start a session',
        'venues' => 'No verified venues are listed for :city yet.',
        'venues_cta' => 'Browse the venue directory',
    ],

    // Per-locale SEO (T04). :city renders the canonical cluster display
    // name; the site-name suffix is appended by the SEO package config.
    'seo' => [
        'title' => 'Tabletop gaming in :city',
        'description' => 'Find upcoming public board game sessions, campaigns, and events in :city, plus verified venues to play at.',
    ],

];
