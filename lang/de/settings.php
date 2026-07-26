<?php

// Per-user Settings-page copy (German). Mirrors lang/en/settings.php.
// Keys follow the domain.prefix_rest naming convention (i18n:check enforced).

return [
    // Calendar feed (iCal token) — D123
    'title_calendar_feed' => 'Kalender-Feed',
    'description_calendar_feed' => 'Abonniere deine bevorstehenden roundup-Spiele über Google Kalender, Apple Kalender oder jede Kalender-App, die iCal-Feeds (.ics) unterstützt.',
    'label_calendar_feed_url' => 'Deine Kalender-Feed-URL',
    'hint_calendar_feed_url' => 'Füge diese URL als neues Kalenderabonnement in deiner Kalender-App hinzu. Halte diese URL privat — jeder, der sie hat, kann deinen Zeitplan einsehen.',
    'action_calendar_feed_generate' => 'Kalender-Feed erstellen',
    'action_calendar_feed_regenerate' => 'URL erneuern',
    'action_calendar_feed_revoke' => 'Feed widerrufen',
    'action_calendar_feed_copy' => 'Kopieren',
    'status_calendar_feed_copied' => 'Kopiert!',
    'confirm_calendar_feed_regenerate' => 'Dadurch wird eine neue Feed-URL erstellt und die aktuelle ungültig. Jede Kalender-App, die die alte URL verwendet, muss aktualisiert werden. Fortfahren?',
    'confirm_calendar_feed_revoke' => 'Dadurch wird dein Kalender-Feed dauerhaft deaktiviert. Jede Kalender-App, die diese URL verwendet, wird nicht mehr aktualisiert. Du kannst jederzeit einen neuen Feed erstellen. Fortfahren?',
    'flash_calendar_feed_generated' => 'Deine Kalender-Feed-URL wurde erstellt. Füge sie deiner Kalender-App hinzu, um bevorstehende Spiele zu sehen.',
    'flash_calendar_feed_revoked' => 'Dein Kalender-Feed wurde widerrufen und ist nicht mehr zugänglich.',

    // Discord-Server (Vermieter-Oberfläche — Gilden, in die der Bot installiert wurde)
    'title_discord_servers' => 'Discord-Server',
    'description_discord_servers' => 'Server, auf denen du den roundup-Bot installiert hast. Verwalte Kanäle, Veröffentlichungen und den Moderationsmodus für jeden.',
    'status_discord_servers_active' => 'Aktiv',
    'status_discord_servers_paused' => 'Pausiert',
    'action_discord_servers_configure' => 'Konfigurieren',
];
