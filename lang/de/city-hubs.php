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
    | SEO-Titel und -Beschreibungen.
    |
    */

    'chip_city_hub' => 'Städte-Hub',

    'heading' => 'Tabletop-Spiele in :city',

    'intro' => 'Anstehende öffentliche Spielsitzungen, Kampagnen und Events in und um :city — plus verifizierte Veranstaltungsorte zum Spielen.',

    'stats' => [
        'upcoming_sessions' => ':count anstehende öffentliche Sitzungen',
        'verified_venues' => ':count verifizierte Veranstaltungsorte',
    ],

    'sections' => [
        'upcoming_sessions' => 'Anstehende Sitzungen',
        'venues' => 'Verifizierte Veranstaltungsorte',
    ],

    // Weiterleitungs-Links am Ende jedes Abschnitts (T03).
    'lists' => [
        'view_all_board_games' => 'Alle Brettspiel-Sitzungen ansehen',
        'view_all_adventures' => 'Alle Abenteuer ansehen',
        'view_all_events' => 'Events in :city',
        'view_all_venues' => 'Alle Veranstaltungsorte in :city',
    ],

    // Ein qualifizierter Cluster kann trotzdem einen leeren Abschnitt
    // haben (über Venues qualifizierte Städte ohne Sitzungen; über
    // Sitzungen qualifizierte Städte ohne Venues). Leerzustände verlinken
    // immer weiter — nie ein totes Panel.
    'empty' => [
        'upcoming_sessions' => 'In :city sind derzeit keine öffentlichen Sitzungen geplant.',
        'upcoming_sessions_cta' => 'Sitzung finden oder starten',
        'venues' => 'Für :city sind noch keine verifizierten Veranstaltungsorte eingetragen.',
        'venues_cta' => 'Veranstaltungsort-Verzeichnis ansehen',
    ],

    // Locale-spezifisches SEO (T04). :city rendert den kanonischen
    // Anzeigenamen des Clusters; der Seiten-Suffix kommt aus der SEO-Paket-Konfiguration.
    'seo' => [
        'title' => 'Tabletop-Spiele in :city',
        'description' => 'Anstehende öffentliche Brettspiel-Runden, Kampagnen und Events in :city finden, plus verifizierte Orte zum Spielen.',
    ],

];
