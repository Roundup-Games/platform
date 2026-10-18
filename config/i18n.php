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
        'badges' => [
            // True cognates — the metal names are identical in EN and DE
            // (unlike Silber/Jahr, which differ and stay checked).
            'content_badge_tier_bronze',
            'content_badge_tier_gold',
        ],
        'events' => [
            // Deliberate loanword — "Convention" is the canonical DE
            // event-type term (GLOSSARY D174: the German scene says
            // Convention, not Konvention); identical in EN and DE by design.
            'label_event_type_convention',
            // Deliberate loanword — "Event" is the canonical DE entity term
            // for the Event entity (GLOSSARY D174/D181 corpus sweep);
            // identical in EN and DE by design.
            'content_event',
            'content_events',
        ],
        'notifications' => [
            // Deliberate loanword — same canon as events.content_events.
            'group_events',
        ],
        'safety' => [
            // Deliberate loanwords — named safety techniques stay English
            // per GLOSSARY D174 ("X-Card kept", consistent with Session
            // Zero staying untranslated); identical in EN and DE by design.
            'tool_x_card',
            'tool_xno_card',
            // The rest of the canonical toolkit (GLOSSARY §5: Script Change /
            // Open Door / Breaks / Stars & Wishes / Lines & Veils stay
            // English; translating them is banned — D181 sweep enforced it).
            'tool_lines_and_veils',
            'tool_script_change',
            'tool_breaks',
            'tool_stars_and_wishes',
            'content_lines_veils_details',
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
