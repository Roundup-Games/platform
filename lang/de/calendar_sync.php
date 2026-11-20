<?php

return [
    // ── Überschrift & Einleitung ──────────────────────
    'heading_connect' => 'Google-Kalender-Sync',
    'content_intro' => 'Roundup schreibt Deine bestätigten Runden, Kampagnen-Runden und Event-Anmeldungen in Deinen eigenen Google-Kalender — damit Verpflichtungen, die Du hier eingehst, dort auftauchen, wo Du Deine Zeit planst.',
    // ── Quellen-Auswahl ───────────────────────────────
    'label_source_games' => 'Deine Runden',
    'description_source_games' => 'Bestätigte Runden, an denen Du teilnimmst, erscheinen als Kalender-Termine.',
    'label_source_campaign_sessions' => 'Kampagnen-Runden',
    'description_source_campaign_sessions' => 'Runden aus Kampagnen, in denen Du mitspielst, erscheinen als Kalender-Termine.',
    'label_source_events' => 'Events',
    'description_source_events' => 'Deine Event-Anmeldungen erscheinen als Kalender-Termine.',
    // ── Ehrlicher Scope-Block ─────────────────────────
    // Aussagen müssen mit der Google-Nutzerdaten-Datenschutzerklärung in
    // lang/de/privacy.php (be4d7c9a) übereinstimmen — nie weniger und nie
    // mehr versprechen.
    'heading_scope' => 'Was der Sync tut — und was nicht',
    'content_scope_does' => 'Was Roundup tut: Es erstellt, aktualisiert und storniert Kalender-Termine, die Deine Roundup-Verpflichtungen spiegeln — und ausschließlich Termine, die Roundup selbst erstellt hat.',
    'content_scope_never_reads' => 'Was Roundup niemals tut: Termine lesen, speichern oder teilen, die Du woanders erstellt hast — und Deinen Kalender analysiert es ebenfalls nie.',
    'content_scope_never_notifies' => 'Deine Gäste werden niemals benachrichtigt: Änderungen durch den Sync lösen keine Google-Kalender-Benachrichtigungs-E-Mails aus.',
    'content_scope_storage' => 'Roundup speichert nur die Verbindung selbst — ein OAuth-Refresh-Token. Deine Termine liegen in Deinem Google-Kalender, nicht in unserer Datenbank.',
    // ── Folgen eines Widerrufs ────────────────────────
    'heading_disconnect' => 'Wenn Du die Verbindung trennst',
    'content_disconnect_consequences' => 'Wenn Du die Verbindung trennst, entscheidest Du, was mit den von Roundup verwalteten Terminen in Deinem Google-Kalender passiert: behalten (sie bleiben erhalten, Roundup aktualisiert oder entfernt sie aber nicht mehr) oder alle löschen lassen. Deine Runden, Kampagnen und Event-Anmeldungen auf Roundup bleiben unberührt — nur der Kalender-Spiegel verschwindet.',
    // ── Verbinden, Trennen-Auswahl, Widerrufs-Banner ──
    'action_connect' => 'Google-Kalender verbinden',
    'action_disconnect' => 'Trennen',
    'confirm_disconnect' => 'Google-Kalender trennen?',
    'label_disconnect_keep' => 'Termine behalten',
    'description_disconnect_keep' => 'Die von Roundup verwalteten Termine bleiben in Deinem Google-Kalender, Roundup aktualisiert oder entfernt sie aber nicht mehr.',
    'label_disconnect_remove' => 'Roundup-Termine entfernen',
    'description_disconnect_remove' => 'Alle von Roundup verwalteten Termine werden aus Deinem Google-Kalender gelöscht — vergangene und kommende. Das kann einen Moment dauern.',
    'action_disconnect_confirm' => 'Trennen',
    'action_disconnect_cancel' => 'Abbrechen',
    'content_disconnecting' => 'Wir entfernen Deine Roundup-Termine aus dem Google-Kalender und trennen die Verbindung — das kann einen Moment dauern.',
    'content_banner_revoked' => 'Der Kalender-Sync wurde getrennt oder der Zugriff bei Google widerrufen. Verbinde den Kalender erneut, um weiter zu synchronisieren.',
    // ── Status-Labels ─────────────────────────────────
    'status_connected' => 'Verbunden',
    'status_disconnecting' => 'Trennen…',
    'status_revoked' => 'Zugriff widerrufen',
    'status_last_sync' => 'Letzte Synchronisierung: :time',
    // ── Gate-Oberflächen (von S02 über CalendarSyncGate konsumiert) ──
    'error_connect_refused_disabled' => 'Der Kalender-Sync ist für diese Bereitstellung nicht aktiviert.',
    'error_connect_refused_not_allowlisted' => 'Der Kalender-Sync ist für Dein Konto noch nicht verfügbar — er wird schrittweise ausgerollt.',
    'label_mode_test_user_note' => 'Begrenzter Rollout (Testbetrieb)',
];
