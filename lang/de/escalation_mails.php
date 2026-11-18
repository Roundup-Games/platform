<?php

/*
|--------------------------------------------------------------------------
| Eskalierte-Ticket-Mails an Anfragende — Roundup-Copy (M081-Follow-up)
|--------------------------------------------------------------------------
|
| Copy für die vier anfragenden-seitigen eskalierten Ticket-Mails. Die
| S03-View-Overrides ersetzten die übersetzbaren Vendor-Keys durch
| festverdrahtetes Englisch und haben damit die Lokalisierung für
| deutschsprachige Anfragende stillschweigend entfernt. Diese Keys
| stellen die Roundup-Stimme in beiden Sprachen wieder her; die
| Override-Views lesen die bevorzugte Sprache der Anfragenden aus und
| geben sie explizit an __() weiter. Agenten-Flächen (assigned) bleiben
| laut K002-Ausnahme EN-only. Du-Form mit Großschreibung (D175).
|
*/

return [
    // ── new-ticket ──────────────────────────────────────────────
    'heading_new_ticket' => 'Wir haben Dein Ticket erhalten',
    'body_new_ticket_intro' => 'Danke, dass Du Dich an den :brand Support wendest. Dein Ticket ist eingegangen und unser Team kümmert sich darum.',
    'body_new_ticket_follow' => 'Du kannst jede Aktualisierung auf der Ticket-Seite verfolgen.',

    // ── reply ───────────────────────────────────────────────────
    'heading_ticket_reply' => 'Neue Antwort zu :reference',
    'body_ticket_reply_intro' => 'Zu Deinem Support-Ticket wurde eine neue Antwort hinzugefügt.',

    // ── resolved ────────────────────────────────────────────────
    'heading_ticket_resolved' => 'Dein Ticket ist gelöst',
    'body_ticket_resolved_intro' => 'Wir haben Dein Support-Ticket als gelöst markiert.',
    'body_ticket_resolved_follow' => 'Wenn etwas noch nicht passt, antworte auf der Ticket-Seite — das öffnet das Ticket wieder und bringt es zurück zu unserem Team.',

    // ── status-changed ──────────────────────────────────────────
    'heading_ticket_status_changed' => 'Der Status Deines Tickets hat sich geändert',
    'body_ticket_status_changed_intro' => 'Hier ist der aktuelle Stand Deines Support-Tickets.',
    'body_ticket_status_changed_follow' => 'Wenn etwas nicht stimmt, antworte auf der Ticket-Seite — unser Team nimmt es sich wieder an.',

    // ── gemeinsame Feldbezeichnungen & Chrome ───────────────────
    'field_reference' => 'Referenz',
    'field_subject' => 'Betreff',
    'field_priority' => 'Priorität',
    'field_previous_status' => 'Bisheriger Status',
    'field_new_status' => 'Neuer Status',
    'action_view_ticket' => 'Ticket ansehen',
    'content_signoff' => 'Danke',
];
