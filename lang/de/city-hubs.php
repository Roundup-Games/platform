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

];
