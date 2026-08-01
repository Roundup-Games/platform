<?php

/*
|--------------------------------------------------------------------------
| Discord bot language lines
|--------------------------------------------------------------------------
|
| Strings surfaced inside Discord by the roundup bot — per-session thread
| starter messages, calendar thread copy, the unlinked on-ramp ephemeral,
| etc. Resolved with the guild's locale (the audience is the guild), not the
| app/queue locale.
|
*/

return [

    // Unlinked "My seat" on-ramp ephemeral buttons (M059/S02)
    'action_link_discord_to_grab_your_seat' => 'Link Discord to grab your seat',
    'action_rsvp_on_roundup' => 'RSVP on roundup',

    // Daily calendar thread title (M059/S03)
    'content_digest_thread_title' => 'Upcoming — :date',

    // Per-session thread starter (M059/S04)
    'content_thread_starter_prompt' => 'Use this thread to coordinate — ask the host a question, sort out scheduling, or say hi.',
    'content_thread_starter_this_session' => 'this session',
    'content_thread_starter_welcome' => 'Welcome to the session: :name',

    // Unlinked "My seat" on-ramp ephemeral body (M059/S02)
    'content_unlinked_onramp_body' => "You're one tap from your seat. Link your Discord account and we'll drop you straight onto the roster — you can finish in a few seconds. (Next time, this button RSVPs you straight from Discord.)",

];
