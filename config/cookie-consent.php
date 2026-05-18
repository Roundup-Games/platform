<?php

return [

    /*
     * Use this setting to enable the cookie consent dialog.
     */
    'enabled' => env('COOKIE_CONSENT_ENABLED', true),

    /*
     * The name of the cookie in which we store the user's consent preferences.
     * Stores a JSON-encoded object: {"necessary":true,"analytics":true,"marketing":false}
     */
    'cookie_name' => 'cookie_consent',

    /*
     * Set the cookie duration in days.
     */
    'cookie_lifetime' => 365,

    /*
     * Consent categories for granular cookie management.
     * Each category has a translation key for label and description.
     * 'necessary' is always required and cannot be unchecked.
     */
    'categories' => [
        'necessary' => [
            'required' => true,
            'default' => true,
            'label_key' => 'cookie-consent.category_necessary_label',
            'description_key' => 'cookie-consent.category_necessary_description',
        ],
        'analytics' => [
            'required' => false,
            'default' => false,
            'label_key' => 'cookie-consent.category_analytics_label',
            'description_key' => 'cookie-consent.category_analytics_description',
        ],
        'marketing' => [
            'required' => false,
            'default' => false,
            'label_key' => 'cookie-consent.category_marketing_label',
            'description_key' => 'cookie-consent.category_marketing_description',
        ],
    ],
];
