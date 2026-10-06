<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Städte-Hub-Seiten (/cities/{slug})
    |--------------------------------------------------------------------------
    |
    | Texte für die öffentlichen Städte-Hub-Landingpages (M062). :city wird
    | durch den kanonischen Anzeigenamen des Clusters ersetzt
    | (CitySummary::city). T03 ergänzt Listen-/Leerzustand-Texte, T04
    | SEO-Titel und -Beschreibungen. Keys sind flach mit erkannten
    | Präfixen gemäß der i18n:check-Konvention.
    |
    */

    'label_city_hub' => 'Städte-Hub',

    'heading_city_hub' => 'Tabletop-Spiele in :city',

    'content_intro' => 'Anstehende öffentliche Spielsitzungen, Kampagnen und Events in und um :city — plus verifizierte Veranstaltungsorte zum Spielen.',

    'label_upcoming_sessions_count' => ':count anstehende öffentliche Sitzungen',

    'label_verified_venues_count' => ':count verifizierte Veranstaltungsorte',

    'section_upcoming_sessions' => 'Anstehende Sitzungen',

    'section_venues' => 'Verifizierte Veranstaltungsorte',

    // Weiterleitungs-Links am Ende jedes Abschnitts (T03).
    'action_view_all_board_games' => 'Alle Brettspiel-Sitzungen ansehen',
    'action_view_all_adventures' => 'Alle Abenteuer ansehen',
    'action_view_all_events' => 'Events in :city',
    'action_view_all_venues' => 'Alle Veranstaltungsorte in :city',

    // Ein qualifizierter Cluster kann trotzdem einen leeren Abschnitt
    // haben (über Venues qualifizierte Städte ohne Sitzungen; über
    // Sitzungen qualifizierte Städte ohne Venues). Leerzustände verlinken
    // immer weiter — nie ein totes Panel.
    'empty_upcoming_sessions' => 'In :city sind derzeit keine öffentlichen Sitzungen geplant.',
    'empty_upcoming_sessions_cta' => 'Sitzung finden oder starten',
    'empty_venues' => 'Für :city sind noch keine verifizierten Veranstaltungsorte eingetragen.',
    'empty_venues_cta' => 'Veranstaltungsort-Verzeichnis ansehen',

    // Locale-spezifisches SEO (T04). :city rendert den kanonischen
    // Anzeigenamen des Clusters; der Seiten-Suffix kommt aus der SEO-Paket-Konfiguration.
    'seo_title' => 'Tabletop-Spiele in :city',
    'seo_description' => 'Anstehende öffentliche Brettspiel-Runden, Kampagnen und Events in :city finden, plus verifizierte Orte zum Spielen.',

];
