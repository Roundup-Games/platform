<?php

return [

    /*
    |--------------------------------------------------------------------------
    | i18n Check Exemptions
    |--------------------------------------------------------------------------
    |
    | Keys listed here are exempt from the "potentially untranslated" check.
    | These are factual values that are inherently identical across locales —
    | addresses, URLs, legal identifiers, organization names, etc.
    |
    | Supports glob-style wildcards: * matches any sequence of characters.
    |
    | Convention violations are NOT exempted — all keys must use valid prefixes.
    |
    */

    'untranslated_exemptions' => [
        'impressum' => [
            'content_company_name',
            'content_address_*',
            'content_vat_id',
            'content_dispute_url',
        ],
        'privacy' => [
            'content_contact_org',
        ],
        'terms' => [
            'content_contact_org',
        ],
    ],

];
