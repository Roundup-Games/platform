<?php

// Venue *page* keys (M053/S02). Kept separate from venues.php (the picker)
// so the public detail page owns its own surface. T03 extends this file.
return [
    // Back / nav
    'action_back_to_discover' => 'Zurück zur Entdeckung',
    // Link-Affordanz (M053/S02/T03 <x-venue-link>). Der sichtbare Text ist der
    // Veranstaltername selbst; dies beschriftet die Verknüpfung für Hilfsmittel.
    'action_view_venue' => 'Veranstaltungsort ansehen: :name',
    // Header
    'label_venue_type' => 'Veranstaltungsort-Typ',
    'action_visit_website' => 'Website besuchen',
    'label_managed_by' => 'Verwaltet von',
    // Operative Parameter (M056/S05/T02) — vom Plattform-Admin im Namen des
    // Veranstalters kuratiert; auf der öffentlichen Veranstaltungsort-Seite
    // angezeigt, wenn ausgefüllt.
    'heading_operational_parameters' => 'Operative Parameter',
    'label_overlap_guidance' => 'Überschneidungs-Hinweis',
    'label_fee_display' => 'Gebührenhinweis',
    'label_house_rules' => 'Hausregeln',
    // Aktivitäts-Abschnitte
    'heading_upcoming_sessions' => 'Anstehende Sitzungen',
    'heading_past_sessions' => 'Vergangene Sitzungen',
    'heading_active_campaigns' => 'Aktive Kampagnen',
    'heading_completed_campaigns' => 'Vergangene Kampagnen',
    // Leerzustand — einmalig angezeigt, wenn der Veranstaltungsort keine Aktivität hat.
    // Einzelne „Keine X“-Hinweise werden ausgeblendet; nur dieser Fallback wird angezeigt.
    'content_no_activity_yet' => 'Noch keine Sitzungen an diesem Ort eingetragen — schau bald wieder vorbei.',
    // Reviews (S03 hook)
    'heading_reviews' => 'Bewertungen',
    'content_reviews_soon' => 'Bewertungen für diesen Veranstaltungsort folgen in Kürze.',
    // Bewertungs-Oberfläche (M053/S03/T04 VenueReviews-Komponente)
    'content_no_reviews' => 'Noch keine Bewertungen für diesen Veranstaltungsort.',
    'label_reviews_count' => ':count Bewertung|:count Bewertungen',
    'label_your_rating' => 'Deine Bewertung',
    'placeholder_venue_review' => 'Erzähl uns von deiner Erfahrung an diesem Ort (optional)',
    'action_submit_venue_review' => 'Bewertung abgeben',
    'content_not_eligible' => 'Du kannst diesen Veranstaltungsort bewerten, nachdem du an einer abgeschlossenen Sitzung hier teilgenommen hast.',
    'flash_venue_review_submitted' => 'Deine Veranstaltungsort-Bewertung wurde gesendet. Vielen Dank!',
    'validation_venue_rating_required' => 'Bitte wähle eine Bewertung aus.',
    'validation_venue_body_max' => 'Der Bewertungstext darf 2000 Zeichen nicht überschreiten.',
    // ── Veranstaltungsort beanspruchen (M053/S04/T04) ────────────────────────
    'heading_claim_venue' => 'Veranstaltungsort beanspruchen',
    'content_claim_subtitle' => 'Sag uns, warum du „:name“ verwalten solltest, und unser Team prüft deinen Antrag.',
    'field_justification' => 'Warum solltest du diesen Ort verwalten?',
    'placeholder_justification' => 'z. B. Ich bin Eigentümer / Betreiber dieses Orts und möchte die Seite aktuell halten.',
    'heading_optional_proof' => 'Optionaler Nachweis',
    'field_website' => 'Webseite',
    'placeholder_claim_website' => 'https://example.com',
    'field_contact_email' => 'Kontakt-E-Mail',
    'hint_contact_email' => 'Wird nur von unserem Team verwendet, um deine Zugehörigkeit zu diesem Ort zu prüfen.',
    'action_submit_claim' => 'Antrag einreichen',
    'action_claim_venue' => 'Diesen Ort beanspruchen',
    'action_back_to_venue' => 'Zurück zum Veranstaltungsort',
    'content_claim_success' => 'Dein Antrag wurde eingereicht! Unser Team wird ihn in Kürze prüfen.',
    'content_claim_reference' => 'Referenz: :reference',
    'error_claim_duplicate' => 'Du hast bereits einen ausstehenden Antrag für diesen Ort.',
    'error_claim_rate_limit' => 'Du hast zu viele Anträge eingereicht. Bitte versuche es in :hours Stunden erneut.',
    'error_claim_submission_failed' => 'Antrag konnte nicht gesendet werden. Bitte versuche es später erneut.',
    // ── Veranstaltungsort-Typen (über VenueType::label() internationalisiert) ─
    'type_cafe' => 'Café',
    'type_flgs' => 'Spieleladen (FLGS)',
    'type_library' => 'Bibliothek',
    'type_community_center' => 'Gemeindezentrum',
    'type_convention' => 'Convention / Messe',
    'type_bar' => 'Bar / Kneipe',
    'type_other' => 'Sonstiges',
    // ── Veranstaltungsort-Verzeichnis (/{locale}/venues) ─────────────────────
    'nav_venue_directory' => 'Veranstaltungsort-Verzeichnis',
    'heading_directory' => 'Finde einen Ort zum Spielen',
    'content_directory_subtitle' => 'Durchsuche Cafés, Spieleläden und Gemeindezentren in deiner Nähe, die öffentliche Sitzungen veranstalten.',
    'seo_directory_title' => 'Veranstaltungsort-Verzeichnis — Brettspiel- & TTRPG-Orte',
    'seo_directory_description' => 'Durchsuche verifizierte Cafés, Spieleläden und Gemeindezentren in deiner Nähe, die öffentliche Brettspiel- und Tischrollenspiel-Sitzungen veranstalten.',
    'placeholder_directory_search' => 'Suche nach Name, Stadt oder Adresse…',
    'field_directory_venue_type' => 'Veranstaltungsort-Typ',
    'field_directory_sort' => 'Sortieren nach',
    'field_directory_min_rating' => 'Mindestbewertung',
    'label_directory_any_rating' => 'Alle Bewertungen',
    'sort_directory_nearest' => 'Näheste zuerst',
    'sort_directory_active' => 'Aktivste',
    'sort_directory_rating' => 'Bestbewertet',
    'sort_directory_newest' => 'Neueste',
    'filter_directory_has_upcoming' => 'Mit anstehenden Sitzungen',
    'filter_directory_managed' => 'Betreiber-geführt',
    'action_directory_clear_filters' => 'Filter zurücksetzen',
    'hint_directory_nearest_needs_location' => 'Teile deinen Standort, um nach Entfernung zu sortieren.',
    'action_directory_load_more' => 'Mehr laden',
    'content_directory_showing_of' => ':shown von :total',
    'content_directory_upcoming_sessions' => ':count anstehende Sitzung|:count anstehende Sitzungen',
    'content_directory_no_upcoming' => 'Keine anstehenden Sitzungen',
    'label_directory_verified' => 'Verifizierter Veranstaltungsort',
    'label_directory_managed_badge' => 'Betreut',
    'action_directory_use_my_location' => 'Meinen Standort verwenden',
    'content_directory_showing_near_you' => 'Orte in deiner Nähe',
    'action_directory_clear_location' => 'Standort zurücksetzen',
    'empty_directory_title' => 'Keine Orte entsprechen deiner Suche',
    'empty_directory_body' => 'Passe deine Filter an oder, falls du einen tollen Spielort kennst, der noch fehlt, schlage ihn vor und wir nehmen ihn ins Verzeichnis auf.',
    'action_directory_cta_propose' => 'Ort vorschlagen',
    'action_directory_cta_sign_up_propose' => 'Registrieren, um einen Ort vorzuschlagen',
    'heading_directory_footer_cta' => 'Kennst du einen Ort, der hier sein sollte?',
    'content_directory_footer_cta' => 'Schlage einen Veranstaltungsort vor und hilf mehr Spielern, einen Platz am Tisch zu finden.',
    'heading_directory_portal_card' => 'Orte durchsuchen',
    'content_directory_portal_card' => 'Finde Cafés, Spieleläden und Gemeindezentren in deiner Nähe, die öffentliche Sitzungen veranstalten.',
];
