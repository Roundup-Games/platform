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

    // Daily calendar digest embed (rendered in the guild's locale — see DiscordDigestRenderer)
    'content_digest_footer' => 'roundup · cross-community tabletop',
    'content_digest_no_venue' => 'Online / no venue',
    'content_digest_undated' => 'Undated',
    'content_digest_continued' => ' (continued)',
    'content_digest_roster_full' => ' full',
    'content_digest_roster_open' => 'open',
    'content_digest_more_marker' => '… (+:count more)',
    'content_digest_more_ahead' => 'More events ahead',
    'content_digest_overflow_body' => '{1} Showing :count upcoming game — see roundup for the full two-week calendar.|[2,*] Showing :count upcoming games — see roundup for the full two-week calendar.',
    'content_digest_empty_title' => 'No public events scheduled',
    'content_digest_empty_body' => '📭 There are no public roundup games in the next two weeks — check back soon.',

    // Per-session thread starter (M059/S04)
    'content_thread_starter_prompt' => 'Use this thread to coordinate — ask the host a question, sort out scheduling, or say hi.',
    'content_thread_starter_this_session' => 'this session',
    'content_thread_starter_welcome' => 'Welcome to the session: :name',

    // Unlinked "My seat" on-ramp ephemeral body (M059/S02)
    'content_unlinked_onramp_body' => "You're one tap from your seat. Link your Discord account and we'll drop you straight onto the roster — you can finish in a few seconds. (Next time, this button RSVPs you straight from Discord.)",

    // ── Guild settings page (/discord/guilds/{id}) ──
    'content_guild_settings_subtitle' => 'Pick where roundup publishes event cards, and pause posting any time.',
    'flash_channels_saved' => 'Channels saved.',
    'flash_language_saved' => 'Language saved.',
    'content_posting_paused_banner' => 'Posting is paused for this server. Event cards will not be published until you resume.',
    'heading_channels' => 'Channels',
    'content_channels_help' => 'roundup publishes enriched event cards to the games channel. The calendar channel is reserved for the upcoming-events surface.',
    'error_channels_load_failed' => 'Couldn\'t load channels from Discord. Check the bot has "View Channels" permission, then refresh.',
    'label_games_channel' => 'Games channel',
    'label_games_channel_hint' => '(where event cards appear)',
    'label_calendar_channel' => 'Calendar channel',
    'label_calendar_channel_hint' => '(upcoming-events surface)',
    'content_not_picked_yet' => '— Not picked yet —',
    'content_no_channels_loaded' => 'No channels loaded.',
    'content_games_channel_help' => 'Posting stays off until a games channel is picked.',
    'action_save_channels' => 'Save channels',
    'action_refresh_list' => 'Refresh list',
    'content_language_help' => 'roundup posts event cards, the daily calendar digest, and session-thread starters to this server in this language. Dates and times follow it too.',
    'label_posting_language' => 'Posting language',
    'content_app_default' => '— App default (:locale) —',
    'content_locale_help' => 'Takes effect on the next publish — already-posted cards are not retro-translated.',
    'action_save_language' => 'Save language',
    'heading_posting' => 'Posting',
    'content_posting_help' => 'Pause stops all event-card publishing to this server without uninstalling the bot. Resume anytime.',
    'status_posting_paused' => 'Posting paused',
    'status_posting_active' => 'Posting active',
    'content_posting_paused_detail' => 'New and updated events will not reach this server.',
    'content_posting_active_detail' => 'Eligible public events publish automatically.',
    'action_resume_posting' => 'Resume posting',
    'action_pause_posting' => 'Pause posting',
    'flash_paused' => 'Paused.',
    'flash_resumed' => 'Resumed.',
    'heading_server' => 'Server',
    'label_discord_guild' => 'Discord guild',

    // ── Enriched game card (rendered in the guild's locale — see DiscordCardRenderer) ──
    'content_card_field_when' => 'When',
    'content_card_field_organizer' => 'Organizer',
    'content_card_joined_open_roster' => ':count joined · open roster',
    'content_card_min' => 'min :count',
    'content_card_count_waitlist' => ':count waitlist',
    'content_card_count_bench' => ':count bench',
    'content_card_roster_in' => 'In',
    'content_card_roster_waitlist' => 'Waitlist (:count)',
    'content_card_roster_bench' => 'Bench (:count)',
    'content_card_more' => '+:count more',
    'content_card_from_roundup' => '+:count from roundup',
    'content_card_tier_reliable' => '🟢 Reliable',
    'content_card_tier_active' => '🔵 Active',
    'content_card_tier_newcomer' => '🟡 Newcomer',
    'content_card_reliable_pct' => ':pct% reliable',
    'content_card_games_hosted' => '{1} :count game hosted|[2,*] :count games hosted',
    'content_card_open_in_maps' => 'Open in Maps',
    'content_card_field_cross_community' => '🌐 Cross-community',
    'content_card_cross_value_outside' => '**:count** attending from outside :guild — the roundup community reaches across servers',
    'content_card_cross_value_generic' => '**:count** attending from beyond this server — the roundup community reaches across servers',
    'content_card_button_my_seat' => '🎟️ My seat',
    'content_card_button_view' => 'View on roundup',

];
