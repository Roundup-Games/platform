<?php

return [
    // Manifest
    'manifest_name' => 'Roundup Games',
    'manifest_short_name' => 'Roundup',
    'manifest_description' => 'The digital parlor for tabletop enthusiasts — discover games, find GMs, and join sessions.',

    // Install prompt — Chrome / Android
    'install_title' => 'Install Roundup Games',
    'install_description' => 'Get the full experience with quick access from your home screen.',
    'install_button' => 'Install',
    'install_dismiss' => 'Not now',

    // Install prompt — iOS Safari
    'ios_install_title' => 'Add to Home Screen',
    'ios_install_step_1' => 'Tap the Share button at the bottom of Safari',
    'ios_install_step_2' => 'Scroll down and tap \'Add to Home Screen\'',
    'ios_install_step_3' => 'Tap \'Add\' to confirm',
    'ios_install_dismiss' => 'Got it',

    // Offline fallback page (public/offline.html — static, keys for reference only)
    'offline_title' => 'You\'re offline',
    'offline_message' => 'Check your connection and try again. Some previously-visited pages may still be available.',
    'offline_try_again' => 'Try again',

    // Offline indicator (lives in common.php as common.offline_*)
    // Keys listed here for cross-reference only:
    // 'offline_indicator' => 'You\'re offline — some features may be unavailable',
    // 'back_online' => 'Back online',

    // Push notification UI (lives in notifications.php as notifications.push_*)
    // Keys listed here for cross-reference only:
    // 'push_subscribe' => 'Enable push notifications',
    // 'push_unsubscribe' => 'Disable push notifications',
    // 'push_enabled' => 'Push notifications enabled on :count device(s)',
    // 'push_unavailable' => 'Push notifications are not supported in this browser',
    // 'push_preferences_label' => 'Push',

    // Push notification payload (reminder)
    'reminder_title' => 'Game Reminder',
    'reminder_body' => ':game starts at :time today',
];
