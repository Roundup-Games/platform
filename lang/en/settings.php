<?php

// Per-user Settings-page copy. Introduced by M057/S05/T04 (D123 iCal feed
// token generation + revocation UI). Keys follow the domain.prefix_rest
// naming convention (i18n:check enforced).

return [
    // Calendar feed (iCal token) — D123
    'title_calendar_feed' => 'Calendar Feed',
    'description_calendar_feed' => 'Subscribe to your upcoming roundup games from Google Calendar, Apple Calendar, or any calendar app that supports iCal (.ics) feeds.',
    'label_calendar_feed_url' => 'Your calendar feed URL',
    'hint_calendar_feed_url' => 'Add this URL to your calendar app as a new calendar subscription. Keep this URL private — anyone with it can see your schedule.',
    'action_calendar_feed_generate' => 'Generate Calendar Feed',
    'action_calendar_feed_regenerate' => 'Regenerate URL',
    'action_calendar_feed_revoke' => 'Revoke Feed',
    'action_calendar_feed_copy' => 'Copy',
    'confirm_calendar_feed_regenerate' => 'This will create a new feed URL and invalidate the current one. Any calendar app using the old URL will need to be updated. Continue?',
    'confirm_calendar_feed_revoke' => 'This will permanently disable your calendar feed. Any calendar app using this URL will stop updating. You can generate a new feed anytime. Continue?',
    'flash_calendar_feed_generated' => 'Your calendar feed URL has been generated. Add it to your calendar app to see upcoming games.',
    'flash_calendar_feed_revoked' => 'Your calendar feed has been revoked and is no longer accessible.',

    // Discord Servers (landlord surface — guilds the user installed the bot into)
    'title_discord_servers' => 'Discord Servers',
    'description_discord_servers' => 'Servers where you have installed the roundup bot. Manage channels, posting, and the moderation mode for each.',
    'status_discord_servers_paused' => 'Paused',
    'action_discord_servers_configure' => 'Configure',
];
