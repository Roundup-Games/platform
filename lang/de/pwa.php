<?php

return [
    // Manifest
    'manifest_name' => 'Roundup Games',
    'manifest_short_name' => 'Roundup',
    'manifest_description' => 'Der digitale Treffpunkt für Tabletop-Enthusiasten — entdecke Spiele, finde Spielleiter und nimm an Runden teil.',

    // Install prompt — Chrome / Android
    'install_title' => 'Roundup Games installieren',
    'install_description' => 'Hol dir das volle Erlebnis mit schnellem Zugriff vom Startbildschirm.',
    'install_button' => 'Installieren',
    'install_dismiss' => 'Nicht jetzt',

    // Install prompt — iOS Safari
    'ios_install_title' => 'Zum Startbildschirm hinzufügen',
    'ios_install_step_1' => 'Tippe auf die Teilen-Schaltfläche unten in Safari',
    'ios_install_step_2' => 'Scrolle nach unten und tippe auf „Zum Startbildschirm hinzufügen"',
    'ios_install_step_3' => 'Tippe auf „Hinzufügen" zum Bestätigen',
    'ios_install_dismiss' => 'Verstanden',

    // Offline fallback page (public/offline.html — static, keys for reference only)
    'offline_title' => 'Du bist offline',
    'offline_message' => 'Überprüfe deine Verbindung und versuche es erneut. Einige zuvor besuchte Seiten sind möglicherweise noch verfügbar.',
    'offline_try_again' => 'Erneut versuchen',

    // Offline indicator (lives in common.php as common.offline_*)
    // Keys listed here for cross-reference only:
    // 'offline_indicator' => 'Du bist offline — einige Funktionen sind möglicherweise nicht verfügbar',
    // 'back_online' => 'Wieder online',

    // Push notification UI (lives in notifications.php as notifications.push_*)
    // Keys listed here for cross-reference only:
    // 'push_subscribe' => 'Push-Benachrichtigungen aktivieren',
    // 'push_unsubscribe' => 'Push-Benachrichtigungen deaktivieren',
    // 'push_enabled' => 'Push-Benachrichtigungen auf :count Gerät(en) aktiviert',
    // 'push_unavailable' => 'Push-Benachrichtigungen werden in diesem Browser nicht unterstützt',
    // 'push_preferences_label' => 'Push',

    // Push notification payload (reminder)
    'reminder_title' => 'Spielerinnerung',
    'reminder_body' => ':game beginnt heute um :time',
];
