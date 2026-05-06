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
    | Discovery Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) the discovery cache is kept before requiring
    | a refresh. 15 minutes by default.
    |
    */
    'cache_ttl' => env('DISCOVERY_CACHE_TTL', 900),

];
