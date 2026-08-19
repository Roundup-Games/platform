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
        // Factual values inherently identical across en/de: units, URLs,
        // hex colors, proper nouns, and acronyms that have no German form.
        'common' => [
            'content_source_discord',
        ],
        'games' => [
            'label_host',
        ],
        'location' => [
            'placeholder_website_url',
        ],
        'teams' => [
            'placeholder_primary_color',
            'placeholder_secondary_color',
        ],
        'venue' => [
            'type_cafe',
        ],
        'discord' => [
            // True cognate — identical in EN and DE
            'heading_server',
        ],
    ],

];
