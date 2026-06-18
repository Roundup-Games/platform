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

    // Activity sections
    'heading_upcoming_sessions' => 'Anstehende Sitzungen',
    'heading_past_sessions' => 'Vergangene Sitzungen',
    'heading_active_campaigns' => 'Aktive Kampagnen',
    'heading_completed_campaigns' => 'Vergangene Kampagnen',

    // Leerzustand — einmalig angezeigt, wenn der Veranstaltungsort keine Aktivität hat.
    // Einzelne „Keine X“-Hinweise werden ausgeblendet; nur dieser Fallback wird angezeigt.
    'content_no_activity_yet' => 'Noch keine Sitzungen an diesem Ort eingetragen — schau bald wieder vorbei.',

    // Reviews (S03 hook)
    'reviews_heading' => 'Bewertungen',
    'content_reviews_soon' => 'Bewertungen für diesen Veranstaltungsort folgen in Kürze.',

    // Bewertungs-Oberfläche (M053/S03/T04 VenueReviews-Komponente)
    'content_no_reviews' => 'Noch keine Bewertungen für diesen Veranstaltungsort.',
    'reviews_count' => ':count Bewertung|:count Bewertungen',
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
];
