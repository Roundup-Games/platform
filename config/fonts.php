<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Material Symbols Icon Subset
    |--------------------------------------------------------------------------
    |
    | The complete list of Material Symbols Outlined icon names used across the
    | application. This drives the font subsetting in build-tools/subset-icons.sh,
    | which reduces the font payload from ~1.1 MB (full set) to ~50 KB.
    |
    | When adding new icons, append them here and re-run:
    |   bash build-tools/subset-icons.sh
    |
    | The build script also auto-discovers icons from templates/enums/JS, so icons
    | not yet registered here will still be included (with a warning).
    |
    | Icon names use snake_case as defined by Google:
    | https://fonts.google.com/icons?icon.style=Outlined
    |
    | Run `php artisan fonts:audit` to check for gaps or dead entries.
    |
    */

    'material_symbols' => [
        // Navigation & UI
        'menu', 'close', 'arrow_back', 'arrow_forward', 'arrow_upward',
        'chevron_right', 'expand_more', 'more_vert', 'open_in_new',
        'cancel', 'search', 'filter_list', 'tune', 'refresh',

        // Auth & user
        'person', 'person_add', 'person_edit', 'person_play', 'person_remove',
        'person_search', 'people', 'group', 'group_add', 'groups',
        'account_circle', 'account_balance_wallet', 'badge', 'manage_accounts',
        'login', 'logout', 'shield', 'shield_person', 'verified', 'verified_user',

        // Games & discovery
        'casino', 'auto_stories', 'explore', 'explore_off', 'stadium',
        'map', 'location_on', 'location_off', 'my_location', 'edit_location',
        'add_location', 'near_me', 'sports_esports', 'swords',
        'play_circle', 'stop_circle', 'start', 'event', 'event_available',
        'event_busy', 'event_note', 'event_seat', 'event_upcoming',
        'calendar_today', 'calendar_month', 'schedule',
        'campaign', 'playlist_add', 'repeat', 'local_fire_department',

        // Game systems & attributes
        'extension', 'category', 'fitness_center', 'straighten',
        'star', 'star_rate', 'emoji_events', 'bolt', 'trending_up',
        'sell', 'signal_cellular_alt', 'target',

        // Social & communication
        'forum', 'chat', 'mail', 'send', 'share', 'content_copy',
        'link', 'link_off', 'add_link',
        'notifications', 'notifications_active', 'notifications_off', 'notifications_paused',
        'rate_review', 'thumb_up', 'thumb_down', 'favorite', 'favorite_border',
        'public', 'contact_support', 'flag',

        // Content & media
        'edit', 'edit_note', 'draw', 'delete', 'delete_forever',
        'download', 'upload', 'cloud_upload', 'cloud_done', 'cloud_off',
        'save', 'publish', 'visibility', 'visibility_off',
        'assignment', 'notes', 'menu_book', 'school', 'psychology',
        'theater_comedy', 'mood', 'diversity_3', 'auto_awesome',
        'check', 'check_circle', 'checklist', 'done_all',
        'add', 'add_circle', 'add_circle_outline', 'add_business',
        'remove', 'push_pin', 'progress_activity',

        // Safety & moderation
        'block', 'pan_tool', 'gavel', 'warning', 'error', 'lock', 'lock_open',
        'shield', 'task_alt',

        // Settings & prefs
        'settings', 'cookie', 'language', 'translate', 'dark_mode', 'light_mode',
        'routine', 'palette', 'contract', 'contract_edit', 'title',
        'domain', 'business', 'computer', 'shopping_cart', 'payments',
        'install_mobile', 'ios_share', 'home', 'info', 'help',
        'monitoring', 'recommend', 'date_range', 'autorenew',
        'volunteer_activism', 'how_to_reg',

        // Icons only in dynamic PHP code
        'pause_circle', 'search_off', 'inbox', 'code', 'dashboard',

        // Pledge & algorithms pages
        'account_balance', 'distance', 'insights', 'function',
        'lightbulb', 'account_tree', 'grid_on',
        'functions',

        // Social platform icons (from config/platforms.php)
        'alternate_email', 'photo_camera', 'smart_display', 'music_note',
        'cloud', 'card_membership', 'local_cafe', 'games',

        // Tag
        'tag',
    ],

    /*
    |--------------------------------------------------------------------------
    | Noto Serif Weight Variants
    |--------------------------------------------------------------------------
    |
    | Only load the weight variants actually used in templates.
    | Audit: font-heading font-bold (700) = 158 instances,
    |        font-heading font-semibold (600) = 131 instances,
    |        font-heading default (400) = ~59 instances,
    |        italic 400 used in some heading contexts.
    |        Weight 800 and italic 700 are NOT used.
    |
    */

    'noto_serif_weights' => '0,400;0,600;0,700;1,400',

    /*
    |--------------------------------------------------------------------------
    | Inter Weight Variants
    |--------------------------------------------------------------------------
    |
    | Inter is the body font. Weights: 400 (regular), 500 (medium), 600 (semibold).
    |
    */

    'inter_weights' => '400;500;600',
];
