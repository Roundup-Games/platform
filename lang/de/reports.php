<?php

return [
    // ── Actions ────────────────────────────────────────────────────
    'action_report' => 'Melden',
    'action_submit_report' => 'Meldung senden',
    // ── Titles ─────────────────────────────────────────────────────
    'title_report_content' => ':type melden',
    // ── Content ────────────────────────────────────────────────────
    'content_report_explanation' => 'Warum meldest du diesen Inhalt? Meldungen werden an unser Moderationsteam weitergeleitet.',
    'content_submitting' => 'Wird gesendet...',
    // ── Entity type labels ─────────────────────────────────────────
    'entity_user' => 'Benutzer',
    'entity_game' => 'Spiel',
    'entity_campaign' => 'Kampagne',
    // ── Report reasons ─────────────────────────────────────────────
    'field_reason_inappropriate_content' => 'Unangemessener Inhalt',
    'field_reason_harassment' => 'Belästigung oder Missbrauch',
    'field_reason_spam' => 'Spam',
    'field_reason_misleading' => 'Irreführende oder falsche Informationen',
    'field_reason_other' => 'Sonstiges',
    // ── Form labels ────────────────────────────────────────────────
    'label_description' => 'Zusätzliche Details',
    'label_optional' => 'optional',
    'placeholder_description' => 'Gib zusätzliche Kontextinformationen an (optional)',
    // ── Validation ─────────────────────────────────────────────────
    'validation_reason_required' => 'Bitte wähle einen Grund für die Meldung.',
    'validation_reason_invalid' => 'Bitte wähle einen gültigen Grund.',
    'validation_description_max' => 'Die Beschreibung darf maximal 1000 Zeichen lang sein.',
    // ── Errors ─────────────────────────────────────────────────────
    'error_rate_limit' => 'Du hast zu viele Meldungen gesendet. Bitte versuche es in :minutes Minuten erneut.',
    'error_entity_not_found' => 'Der gemeldete Inhalt konnte nicht gefunden werden.',
    'error_self_report' => 'Du kannst deinen eigenen Inhalt nicht melden.',
    'error_already_reported' => 'Du hast diesen Inhalt bereits gemeldet. Unser Team prüft ihn.',
    // ── Flash ──────────────────────────────────────────────────────
    'flash_report_submitted' => 'Vielen Dank. Deine Meldung wurde an unser Moderationsteam weitergeleitet.',
];
