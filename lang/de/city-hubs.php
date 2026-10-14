<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stadt-Hub-Seiten (/cities/{slug})
    |--------------------------------------------------------------------------
    |
    | Texte für die öffentlichen Stadt-Hub-Landingpages (M062). :city wird
    | durch den kanonischen Anzeigenamen des Clusters ersetzt
    | (CitySummary::city). T03 ergänzt Listen-/Leerzustand-Texte, T04
    | SEO-Titel und -Beschreibungen. Keys sind flach mit erkannten
    | Präfixen gemäß der i18n:check-Konvention.
    |
    */

    'label_city_hub' => 'Stadt-Hub',

    // ── Indexseite (/cities, M066/S06/T04) ─────────────
    // Die crawlbare Einstiegsseite mit allen aktiven Hubs; Karten nutzen
    // die Zähler-Labels der Hub-Seiten unten mit.
    'label_city_hubs' => 'Stadt-Hubs',

    'heading_city_hubs_index' => 'Tabletop-Spiele nach Stadt',

    'content_index_intro' => 'Stadt-Hubs bündeln die öffentliche Aktivität einer lokalen Szene auf einer Seite: anstehende Spielrunden, Kampagnen und Events plus verifizierte Veranstaltungsorte zum Spielen. Hubs gehen automatisch live, sobald eine Stadt genug anstehende öffentliche Aktivität oder verifizierte Veranstaltungsorte hat — das Verzeichnis wächst mit den lokalen Szenen.',

    'label_featured' => 'Empfohlen',

    'empty_city_index' => 'Noch sind keine Stadt-Hubs aktiv — sie erscheinen automatisch, sobald lokale Szenen öffentliche Aktivität und verifizierte Veranstaltungsorte aufbauen.',

    'empty_city_index_cta' => 'Spiele in deiner Nähe entdecken',

    'nav_cities' => 'Städte',

    'heading_city_hub' => 'Tabletop-Spiele in :city',

    'content_intro' => 'Anstehende öffentliche Spielrunden, Kampagnen und Events in und um :city — plus verifizierte Veranstaltungsorte zum Spielen.',

    'label_upcoming_sessions_count' => ':count anstehende öffentliche Runden',

    'label_verified_venues_count' => ':count verifizierte Veranstaltungsorte',

    'section_upcoming_sessions' => 'Anstehende Runden',

    'section_venues' => 'Verifizierte Veranstaltungsorte',

    // Weiterleitungs-Links am Ende jedes Abschnitts (T03).
    'action_view_all_board_games' => 'Alle Brettspiel-Runden ansehen',
    'action_view_all_adventures' => 'Alle Abenteuer ansehen',
    'action_view_all_events' => 'Events in :city',
    'action_view_all_venues' => 'Alle Veranstaltungsorte in :city',

    // Ein qualifizierter Cluster kann trotzdem einen leeren Abschnitt
    // haben (über Veranstaltungsorte qualifizierte Städte ohne Runden; über
    // Runden qualifizierte Städte ohne Veranstaltungsorte). Leerzustände verlinken
    // immer weiter — nie ein totes Panel.
    'empty_upcoming_sessions' => 'In :city sind derzeit keine öffentlichen Runden geplant.',
    'empty_upcoming_sessions_cta' => 'Runde finden oder starten',
    'empty_venues' => 'Für :city sind noch keine verifizierten Veranstaltungsorte eingetragen.',
    'empty_venues_cta' => 'Veranstaltungsort-Verzeichnis ansehen',

    // Locale-spezifisches SEO (T04). :city rendert den kanonischen
    // Anzeigenamen des Clusters; der Seiten-Suffix kommt aus der SEO-Paket-Konfiguration.
    'seo_title' => 'Tabletop-Spiele in :city',
    'seo_description' => 'Anstehende öffentliche Brettspiel-Runden, Kampagnen und Events in :city finden, plus verifizierte Veranstaltungsorte zum Spielen.',

    // SEO der Indexseite (M066/S06/T04); der Seiten-Suffix kommt aus der
    // SEO-Paket-Konfiguration.
    'seo_title_index' => 'Stadt-Hubs für Tabletop-Spiele',
    'seo_description_index' => 'Alle aktiven Stadt-Hubs entdecken: anstehende öffentliche Brettspiel-Runden, Kampagnen und Events plus verifizierte Veranstaltungsorte — Stadt für Stadt.',

];
