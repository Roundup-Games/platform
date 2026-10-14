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
            // Deliberate loanword — "Host" is the canonical DE actor term
            // (GLOSSARY D174); identical in EN and DE by design.
            'content_owner',
            // Deliberate loanword — "Vibes" is the canonical DE vibe-preference
            // term (GLOSSARY/audit 05 P10: Stimmung/Style banned for the
            // preference concept); identical in EN and DE by design.
            'content_vibes',
        ],
        'games' => [
            'label_host',
            // Deliberate loanword — same canon as label_host.
            'field_role_owner',
        ],
        'plan' => [
            // Deliberate loanword — "Event" is the canonical DE entity term
            // (GLOSSARY, ratified 2026-10-12); identical in EN and DE by design.
            'content_get_together',
        ],
        'location' => [
            'placeholder_website_url',
        ],
        'teams' => [
            'placeholder_primary_color',
            'placeholder_secondary_color',
        ],
        'venues' => [
            'type_cafe',
        ],
        'events' => [
            // Deliberate loanword — "Convention" is the canonical DE
            // event-type term (GLOSSARY D174: the German scene says
            // Convention, not Konvention); identical in EN and DE by design.
            'label_event_type_convention',
        ],
        'safety' => [
            // Deliberate loanwords — named safety techniques stay English
            // per GLOSSARY D174 ("X-Card kept", consistent with Session
            // Zero staying untranslated); identical in EN and DE by design.
            'tool_x_card',
            'tool_xno_card',
        ],
        'discord' => [
            // True cognate — identical in EN and DE
            'heading_server',
        ],
        'validation' => [
            // True cognate — the friendly attribute label for `name` is
            // "Name" in both locales
            'attributes.name',
        ],
    ],

];
