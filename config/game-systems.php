<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upcoming Tables Section Limit
    |--------------------------------------------------------------------------
    |
    | Max upcoming tables listed on a game-system landing page
    | (/game-systems/{slug}). Matches CityDirectoryService::SECTION_LIMIT
    | so hub and system sections cap at the same size; the SQL query is
    | limited to this size, so a landing render is one bounded query.
    |
    */

    'upcoming_tables_limit' => env('GAMESYSTEMS_UPCOMING_TABLES_LIMIT', 12),

    /*
    |--------------------------------------------------------------------------
    | Landing Data Cache TTL
    |--------------------------------------------------------------------------
    |
    | The upcoming-tables collection behind a game-system landing page is
    | cached for this many seconds, aligned with the discovery/cityhub
    | cache TTL default (900 = 15 min). Invalidation wiring lands with the
    | sitemap task (62-03); until then the TTL is the sole staleness bound.
    |
    */

    'cache_ttl' => env('GAMESYSTEMS_CACHE_TTL', 900),

];
