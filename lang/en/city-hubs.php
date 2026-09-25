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

];
