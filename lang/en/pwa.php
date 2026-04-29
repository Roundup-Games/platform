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

    // Install prompt — Firefox Android
    'heading_firefox_install_title' => 'Install Roundup Games',
    'content_firefox_install_step_1' => 'Tap the menu (⋮) in the address bar',
    'content_firefox_install_step_2' => 'Tap \'Install\' to add to your home screen',
    'action_firefox_install_dismiss' => 'Got it',

    // Offline fallback page (public/offline.html — static, keys for reference only)
    'offline_title' => 'You\'re offline',
    'offline_message' => 'Check your connection and try again. Some previously-visited pages may still be available.',
    'offline_try_again' => 'Try again',

    // Offline indicator
    'offline_indicator' => 'You\'re offline — some features may be unavailable',
    'back_online' => 'Back online',

    // SW update toast
    'content_update_available' => 'A new version is available',
    'action_update' => 'Update',

    // Push notification UI
    'push_enabled_on_devices' => 'Push notifications enabled on :count device(s).',
    'push_denied_hint' => 'Push notifications are blocked in your browser settings. To re-enable, update the notification permissions in your browser.',

    // Offline action toasts (Background Sync)
    'offline_action_queued' => 'Action queued — will complete when you reconnect',
    'offline_action_offline' => 'You\'re offline — connect to complete this action',
];
