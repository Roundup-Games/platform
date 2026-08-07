<?php

/*
|--------------------------------------------------------------------------
| Discord bot language lines (Deutsch)
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
    'action_link_discord_to_grab_your_seat' => 'Discord verbinden und Platz sichern',
    'action_rsvp_on_roundup' => 'Auf Roundup zusagen',

    // Daily calendar thread title (M059/S03)
    'content_digest_thread_title' => 'Anstehend — :date',

    // Daily calendar digest embed (rendered in the guild's locale — see DiscordDigestRenderer)
    'content_digest_footer' => 'roundup · tabletop über Community-Grenzen hinweg',
    'content_digest_no_venue' => 'Online / kein Ort',
    'content_digest_undated' => 'Ohne Datum',
    'content_digest_continued' => ' (Fortsetzung)',
    'content_digest_roster_full' => ' voll',
    'content_digest_roster_open' => 'offen',
    'content_digest_more_marker' => '… (+:count weitere)',
    'content_digest_more_ahead' => 'Weitere Events folgen',
    'content_digest_overflow_body' => '{1} :count anstehendes Spiel angezeigt — das vollständige Zwei-Wochen-Kalender findest du auf roundup.|[2,*] :count anstehende Spiele angezeigt — das vollständige Zwei-Wochen-Kalender findest du auf roundup.',
    'content_digest_empty_title' => 'Keine öffentlichen Events geplant',
    'content_digest_empty_body' => '📭 In den nächsten zwei Wochen sind keine öffentlichen roundup-Spiele geplant — schau bald wieder vorbei.',

    // Per-session thread starter (M059/S04)
    'content_thread_starter_prompt' => 'Nutzt diesen Thread für die Absprache — stellt der Spielleitung Fragen, klärt Termine oder sagt kurz Hallo.',
    'content_thread_starter_this_session' => 'diese Sitzung',
    'content_thread_starter_welcome' => 'Willkommen zur Sitzung: :name',

    // Unlinked "My seat" on-ramp ephemeral body (M059/S02)
    'content_unlinked_onramp_body' => 'Du bist nur einen Klick von deinem Platz entfernt. Verbinde dein Discord-Konto und wir setzen dich direkt auf die Teilnehmerliste – es dauert nur wenige Sekunden. (Beim nächsten Mal zusagt dieser Button dich direkt aus Discord heraus.)',

    // ── Guild settings page (/discord/guilds/{id}) ──
    'label_discord' => 'Discord',
    'content_guild_settings_subtitle' => 'Lege fest, wo roundup Event-Karten veröffentlicht, und pausiere die Veröffentlichung jederzeit.',
    'flash_channels_saved' => 'Kanäle gespeichert.',
    'flash_language_saved' => 'Sprache gespeichert.',
    'content_posting_paused_banner' => 'Die Veröffentlichung ist für diesen Server pausiert. Event-Karten werden erst wieder veröffentlicht, wenn du sie fortsetzt.',
    'heading_channels' => 'Kanäle',
    'content_channels_help' => 'roundup veröffentlicht erweiterte Event-Karten im Spiele-Kanal. Der Kalender-Kanal ist der Übersicht der anstehenden Events vorbehalten.',
    'error_channels_load_failed' => 'Kanäle konnten nicht von Discord geladen werden. Prüfe, ob der Bot die Berechtigung „Kanäle ansehen“ hat, und lade erneut.',
    'label_games_channel' => 'Spiele-Kanal',
    'label_games_channel_hint' => '(wo Event-Karten erscheinen)',
    'label_calendar_channel' => 'Kalender-Kanal',
    'label_calendar_channel_hint' => '(Übersicht anstehender Events)',
    'content_not_picked_yet' => '— Noch nicht gewählt —',
    'content_no_channels_loaded' => 'Keine Kanäle geladen.',
    'content_games_channel_help' => 'Die Veröffentlichung bleibt aus, bis ein Spiele-Kanal gewählt ist.',
    'action_save_channels' => 'Kanäle speichern',
    'action_refresh_list' => 'Liste aktualisieren',
    'heading_language' => 'Sprache',
    'content_language_help' => 'roundup veröffentlicht Event-Karten, den täglichen Kalender-Digest und Session-Thread-Starter auf diesem Server in dieser Sprache. Auch Datum und Uhrzeit richten sich danach.',
    'label_posting_language' => 'Veröffentlichungssprache',
    'content_app_default' => '— App-Standard (:locale) —',
    'content_locale_help' => 'Greift ab der nächsten Veröffentlichung — bereits veröffentlichte Karten werden nicht nachträglich übersetzt.',
    'action_save_language' => 'Sprache speichern',
    'heading_posting' => 'Veröffentlichung',
    'content_posting_help' => 'Pausieren stoppt die gesamte Veröffentlichung von Event-Karten auf diesem Server, ohne den Bot zu deinstallieren. Jederzeit fortsetzbar.',
    'status_posting_paused' => 'Veröffentlichung pausiert',
    'status_posting_active' => 'Veröffentlichung aktiv',
    'content_posting_paused_detail' => 'Neue und aktualisierte Events erreichen diesen Server nicht.',
    'content_posting_active_detail' => 'Öffentliche Events werden automatisch veröffentlicht.',
    'action_resume_posting' => 'Veröffentlichung fortsetzen',
    'action_pause_posting' => 'Veröffentlichung pausieren',
    'flash_paused' => 'Pausiert.',
    'flash_resumed' => 'Fortgesetzt.',
    'heading_server' => 'Server',
    'label_discord_guild' => 'Discord-Gilde',
    'label_moderation' => 'Moderation',

    // ── Enriched game card (rendered in the guild's locale — see DiscordCardRenderer) ──
    'content_card_field_when' => 'Wann',
    'content_card_field_players' => 'Mitspieler',
    'content_card_field_system' => 'System',
    'content_card_field_organizer' => 'Spielleitung',
    'content_card_field_venue' => 'Ort',
    'content_card_full' => 'Voll',
    'content_card_joined_open_roster' => ':count dabei · offene Liste',
    'content_card_min' => 'min :count',
    'content_card_count_waitlist' => ':count Warteliste',
    'content_card_count_bench' => ':count Bank',
    'content_card_roster_in' => 'Dabei',
    'content_card_roster_waitlist' => 'Warteliste (:count)',
    'content_card_roster_bench' => 'Bank (:count)',
    'content_card_more' => '+:count weitere',
    'content_card_from_roundup' => '+:count über roundup',
    'content_card_tier_reliable' => '🟢 Zuverlässig',
    'content_card_tier_active' => '🔵 Aktiv',
    'content_card_tier_newcomer' => '🟡 Neu',
    'content_card_reliable_pct' => ':pct% zuverlässig',
    'content_card_games_hosted' => '{1} :count Spiel geleitet|[2,*] :count Spiele geleitet',
    'content_card_open_in_maps' => 'In Maps öffnen',
    'content_card_field_cross_community' => '🌐 Community-übergreifend',
    'content_card_cross_value_outside' => '**:count** nehmen von außerhalb :guild teil — die roundup-Community reicht über Server hinweg',
    'content_card_cross_value_generic' => '**:count** nehmen von außerhalb dieses Servers teil — die roundup-Community reicht über Server hinweg',
    'content_card_button_my_seat' => '🎟️ Mein Platz',
    'content_card_button_view' => 'Auf roundup ansehen',
    'content_card_footer' => 'roundup · tabletop über Community-Grenzen hinweg',

];
