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

    // Empty states
    'content_no_upcoming_sessions' => 'Noch keine anstehenden Sitzungen an diesem Ort.',
    'content_no_past_sessions' => 'Noch keine vergangenen Sitzungen.',
    'content_no_active_campaigns' => 'Noch keine aktiven Kampagnen an diesem Ort.',
    'content_no_completed_campaigns' => 'Noch keine vergangenen Kampagnen.',

    // Reviews (S03 hook)
    'reviews_heading' => 'Bewertungen',
    'content_reviews_soon' => 'Bewertungen für diesen Veranstaltungsort folgen in Kürze.',
];
