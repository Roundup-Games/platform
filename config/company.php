<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Company / Legal Entity
    |--------------------------------------------------------------------------
    |
    | Single source of truth for all company data referenced across the
    | platform: Impressum, privacy policy, terms, email footers, and
    | billing pages. Values here are factual (not locale-dependent).
    |
    | Locale-dependent labels and prose remain in lang/{en,de}/ files.
    | Templates use config() for data and __() for labels.
    |
    */

    'legal_name' => 'Roundup Games',
    'display_name' => 'Roundup Games',

    /*
    | Entity status. Currently in pre-incorporation — the platform is
    | operated by the founder as a sole proprietor until gGmbH registration
    | is complete. Templates use entity_type to conditionally render
    | the appropriate legal notice.
    |
    | Values: pre-incorporation | gGmbH | e.V. | GmbH | UG
    */
    'entity_type' => 'pre-incorporation',

    'responsible_person' => [
        'name' => 'Vasile Burghelea',
        'location' => 'Berlin, Germany',
    ],

    'address' => [
        'line_1' => '',
        'line_2' => '',
        'city' => 'Berlin',
        'postal_code' => '',
        'country' => 'Germany',
    ],

    'contact' => [
        'general' => 'hello@roundup.games',
        'legal' => 'legal@roundup.games',
        'privacy' => 'privacy@roundup.games',
        'support' => 'support@roundup.games',
    ],

    /*
    | Registration details. Empty until incorporation is complete.
    | Templates conditionally hide registration fields when these are blank.
    */
    'registration' => [
        'court' => '',
        'number' => '',
    ],

    'tax' => [
        'vat_id' => '',
    ],

    'governing_law' => [
        'jurisdiction' => 'the Federal Republic of Germany',
        'courts_city' => 'Berlin',
    ],

    'dispute_resolution' => [
        'url' => 'https://ec.europa.eu/consumers/odr/',
        'participates' => false,
    ],
];
