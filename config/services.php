<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', config('app.url').'/auth/google/callback'),
        // Minimal no-approval scope set per M056/D-1. Widening requires an
        // explicit decision — document the change here so the scope set
        // stays the application's assertion, not a Socialite default.
        'scope' => ['openid', 'email', 'profile'],
    ],

    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect' => env('DISCORD_REDIRECT_URI', config('app.url').'/auth/discord/callback'),
        // Minimal no-approval scope set per M056/D-1: identify + email are
        // the documented scopes that require no Discord approval cycle and
        // give roundup everything it needs for login + attribution.
        // Approval-gated scopes (relationships.read, connections, guilds)
        // are deliberately NOT requested in Y1. Widening requires an
        // explicit decision and should be reflected here so the scope set
        // stays the application's assertion, not a Socialite default.
        'scope' => ['identify', 'email'],
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'bgg' => [
        'base_url' => env('BGG_API_BASE_URL', 'https://boardgamegeek.com/xmlapi2'),
        'token' => env('BGG_API_TOKEN'),
        'rate_limit_seconds' => env('BGG_RATE_LIMIT_SECONDS', 2),
    ],

    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('APP_URL'),
    ],

    'cloudflare' => [
        'zone_id' => env('CF_ZONE_ID'),
        'api_token' => env('CF_API_TOKEN'),
    ],

];
