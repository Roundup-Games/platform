<?php

// Per-user Settings-page copy. Introduced by M057/S05/T04 (D123 iCal feed
// token generation + revocation UI). Add calendar-feed-related keys here.

return [
    // Calendar feed (iCal token) — D123
    'calendar_feed_title' => 'Calendar Feed',
    'calendar_feed_description' => 'Subscribe to your upcoming roundup games from Google Calendar, Apple Calendar, or any calendar app that supports iCal (.ics) feeds.',
    'calendar_feed_url_label' => 'Your calendar feed URL',
    'calendar_feed_url_help' => 'Add this URL to your calendar app as a new calendar subscription. Keep this URL private — anyone with it can see your schedule.',
    'calendar_feed_generate' => 'Generate Calendar Feed',
    'calendar_feed_regenerate' => 'Regenerate URL',
    'calendar_feed_revoke' => 'Revoke Feed',
    'calendar_feed_copy' => 'Copy',
    'calendar_feed_copied' => 'Copied!',
    'calendar_feed_regenerate_confirm' => 'This will create a new feed URL and invalidate the current one. Any calendar app using the old URL will need to be updated. Continue?',
    'calendar_feed_revoke_confirm' => 'This will permanently disable your calendar feed. Any calendar app using this URL will stop updating. You can generate a new feed anytime. Continue?',
    'calendar_feed_generated_flash' => 'Your calendar feed URL has been generated. Add it to your calendar app to see upcoming games.',
    'calendar_feed_revoked_flash' => 'Your calendar feed has been revoked and is no longer accessible.',
];
