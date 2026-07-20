<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Social Media Platforms for GM Profiles
    |--------------------------------------------------------------------------
    |
    | Config-driven registry of social media platforms that GMs can link to
    | from their public profile. Each entry defines URL generation, handle
    | validation, and display properties.
    |
    | url_template: Uses {handle} and optional {instance} placeholders.
    |   Resolved via str_replace() — no template engine needed.
    |
    | handle_pattern: Regex for server-side validation of user input.
    | at_prefixed: Whether the UI should display an @ prefix before the input.
    |
    | instance_required: True for platforms needing a second field (e.g. Mastodon).
    | instance_pattern: Regex for validating the instance field.
    |
    | sort_order: Controls display order in the profile form and public views.
    |
    */

    'discord' => [
        'name' => 'Discord',
        'url_template' => 'https://discord.com/users/{handle}',
        'handle_pattern' => '/^[0-9]{17,20}$/',
        'at_prefixed' => false,
        'icon' => 'groups',
        'sort_order' => 5,
    ],

    'twitter' => [
        'name' => 'X (Twitter)',
        'url_template' => 'https://x.com/{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9_]{1,15}$/',
        'at_prefixed' => true,
        'icon' => 'alternate_email',
        'sort_order' => 10,
    ],

    'instagram' => [
        'name' => 'Instagram',
        'url_template' => 'https://instagram.com/{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9._]{1,30}$/',
        'at_prefixed' => true,
        'icon' => 'photo_camera',
        'sort_order' => 20,
    ],

    'youtube' => [
        'name' => 'YouTube',
        'url_template' => 'https://youtube.com/@{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9_.\-]{1,50}$/',
        'at_prefixed' => true,
        'icon' => 'smart_display',
        'sort_order' => 30,
    ],

    'twitch' => [
        'name' => 'Twitch',
        'url_template' => 'https://twitch.tv/{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9_]{4,25}$/',
        'at_prefixed' => false,
        'icon' => 'sports_esports',
        'sort_order' => 40,
    ],

    'tiktok' => [
        'name' => 'TikTok',
        'url_template' => 'https://tiktok.com/@{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9_.]{1,24}$/',
        'at_prefixed' => true,
        'icon' => 'music_note',
        'sort_order' => 50,
    ],

    'threads' => [
        'name' => 'Threads',
        'url_template' => 'https://threads.net/@{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9._]{1,30}$/',
        'at_prefixed' => true,
        'icon' => 'alternate_email',
        'sort_order' => 60,
    ],

    'reddit' => [
        'name' => 'Reddit',
        'url_template' => 'https://reddit.com/user/{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9_\-]{3,21}$/',
        'at_prefixed' => false,
        'icon' => 'forum',
        'sort_order' => 70,
    ],

    'facebook' => [
        'name' => 'Facebook',
        'url_template' => 'https://facebook.com/{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9.]{1,50}$/',
        'at_prefixed' => false,
        'icon' => 'thumb_up',
        'sort_order' => 80,
    ],

    'mastodon' => [
        'name' => 'Mastodon',
        'url_template' => 'https://{instance}/@{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9_]{1,30}$/',
        'at_prefixed' => true,
        'instance_required' => true,
        'instance_pattern' => '/^[a-z0-9.\-]+\.[a-z]{2,}$/',
        'icon' => 'public',
        'sort_order' => 90,
    ],

    'bluesky' => [
        'name' => 'Bluesky',
        'url_template' => 'https://bsky.app/profile/{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9.\-]{1,100}$/',
        'at_prefixed' => false,
        'icon' => 'cloud',
        'sort_order' => 100,
    ],

    'patreon' => [
        'name' => 'Patreon',
        'url_template' => 'https://patreon.com/{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9_\-]{1,50}$/',
        'at_prefixed' => false,
        'icon' => 'card_membership',
        'sort_order' => 110,
    ],

    'ko-fi' => [
        'name' => 'Ko-fi',
        'url_template' => 'https://ko-fi.com/{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9_]{1,30}$/',
        'at_prefixed' => false,
        'icon' => 'local_cafe',
        'sort_order' => 120,
    ],

    'linktree' => [
        'name' => 'Linktree',
        'url_template' => 'https://linktr.ee/{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9_\-]{1,30}$/',
        'at_prefixed' => false,
        'icon' => 'link',
        'sort_order' => 130,
    ],

    'itch-io' => [
        'name' => 'itch.io',
        'url_template' => 'https://{handle}.itch.io',
        'handle_pattern' => '/^[a-zA-Z0-9_\-]{1,40}$/',
        'at_prefixed' => false,
        'icon' => 'games',
        'sort_order' => 140,
    ],

    'startplaying' => [
        'name' => 'StartPlaying',
        'url_template' => 'https://startplaying.games/gm/{handle}',
        'handle_pattern' => '/^[a-zA-Z0-9_\-]{1,50}$/',
        'at_prefixed' => false,
        'icon' => 'casino',
        'sort_order' => 150,
    ],

];
