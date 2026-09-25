<?php

return [

    /*
    |--------------------------------------------------------------------------
    | City Hub Qualification Thresholds
    |--------------------------------------------------------------------------
    |
    | A city hub (/cities/{slug}) only renders when the city cluster meets
    | EITHER threshold — upcoming public activity OR verified venues.
    | Non-qualifying cities return a real 404 (never a soft empty page).
    |
    | Both values are interim config knobs; 62-04 moves them into Filament
    | curation. Until then they are env-tunable without a deploy.
    |
    */

    'min_upcoming_sessions' => env('CITYHUBS_MIN_UPCOMING_SESSIONS', 3),

    'min_verified_venues' => env('CITYHUBS_MIN_VERIFIED_VENUES', 2),

    /*
    |--------------------------------------------------------------------------
    | Upcoming Activity Window
    |--------------------------------------------------------------------------
    |
    | Games, campaign sessions, and events count toward the activity
    | threshold only when they start within this many days (M062 decision:
    | forward-looking activity is what a search visitor lands on).
    |
    */

    'upcoming_window_days' => env('CITYHUBS_UPCOMING_WINDOW_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | City Summary Cache TTL
    |--------------------------------------------------------------------------
    |
    | Per-city summaries (cluster + activity counts) are cached for this many
    | seconds, aligned with the discovery cache TTL default (900 = 15 min).
    | Game/Event/Location saves flush the affected summary eagerly via
    | CityHubCacheObserver (62-03-T04); the TTL is now the staleness bound
    | only for campaign-side drift, which deliberately rides it.
    |
    */

    'cache_ttl' => env('CITYHUBS_CACHE_TTL', 900),

];
