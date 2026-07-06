<?php

return [

    /*
    |--------------------------------------------------------------------------
    | i18n Check Exemptions
    |--------------------------------------------------------------------------
    |
    | Keys listed here are exempt from the "potentially untranslated" check.
    | These are factual values that are inherently identical across locales.
    |
    | Supports glob-style wildcards: * matches any sequence of characters.
    |
    | Convention violations are NOT exempted — all keys must use valid prefixes.
    |
    | Company data (name, address, VAT ID, etc.) has been moved to
    | config/company.php and is no longer duplicated in lang files.
    |
    */

    'untranslated_exemptions' => [
        'privacy' => [
            'content_contact_org',
        ],
        'terms' => [
            'content_contact_org',
        ],

        // Factual values inherently identical across en/de: units, URLs,
        // hex colors, proper nouns, and acronyms that have no German form.
        'common' => [
            'label_unit_km',
        ],
        'games' => [
            'label_host',
            'label_capacity_exempt_owner',
        ],
        'location' => [
            'placeholder_website_url',
            'type_cafe',
            'type_flgs',
            'type_bar',
        ],
        'teams' => [
            'placeholder_primary_color',
            'placeholder_secondary_color',
        ],
        'venue' => [
            'placeholder_claim_website',
            'type_cafe',
        ],
    ],

];
