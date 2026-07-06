<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discovery Radius Options
    |--------------------------------------------------------------------------
    |
    | Available radius choices (in km) shown to users in discovery pages.
    | Also used as the fallback radius when the primary radius returns empty.
    |
    */
    'radius_options' => [10, 25, 50],
    'fallback_radius' => 100,

    /*
    |--------------------------------------------------------------------------
    | Gathering Cap Per Feed Page
    |--------------------------------------------------------------------------
    |
    | Maximum number of Gathering-type games allowed per 12-item feed page so
    | multi-system Gatherings cannot dominate the discovery feed (R048). The cap
    | scales with the rendered window size, so loading more (e.g. 24 items)
    | permits proportionally more Gatherings. Tunable from real feed-composition
    | data via the game.discovery.gathering_cap_applied debug log.
    |
    */
    'gathering_cap_per_page' => env('DISCOVERY_GATHERING_CAP_PER_PAGE', 1),

    /*
    |--------------------------------------------------------------------------
    | Gathering Relevance Penalty
    |--------------------------------------------------------------------------
    |
    | RESERVED (currently unused). The tiebreaker currently hardcodes a binary
    | 0/1 CASE WHEN game_type = 'gathering' tier in DiscoveryQueryService. This
    | config value is kept for forward-compatibility: once real feed-composition
    | data justifies multi-tier ranking, DiscoveryQueryService will read this
    | value instead of the hardcoded CASE. Tunable post-launch (R048).
    |
    */
    'gathering_relevance_penalty' => env('DISCOVERY_GATHERING_RELEVANCE_PENALTY', 1),

    /*
    |--------------------------------------------------------------------------
    | Discovery Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) the discovery cache is kept before requiring
    | a refresh. 15 minutes by default.
    |
    */
    'cache_ttl' => env('DISCOVERY_CACHE_TTL', 900),

];
