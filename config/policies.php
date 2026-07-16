<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Policy Versioning
    |--------------------------------------------------------------------------
    |
    | This config tracks the last-updated date for each legal policy. When a
    | policy is updated, change the date here and the platform will prompt
    | authenticated users to re-accept the updated policy.
    |
    | The version key is the ISO date string. Compare against the
    | `policies_accepted_at` timestamp on the user record.
    |
    */

    'privacy' => [
        'last_updated' => '2026-07-15',
    ],

    'terms' => [
        'last_updated' => '2025-06-01',
    ],

];
