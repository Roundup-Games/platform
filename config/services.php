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
        // Bot application token shared with the M057 Interactions endpoint
        // (D118). Used by DiscordWebhookClient for all REST push (card posts,
        // edits, deletes) — NO gateway, NO webhook URL (D117 thin push+REST).
        'bot_token' => env('DISCORD_BOT_TOKEN'),
        // ── Interactions endpoint (S03/T01) ─────────────────────────────
        // Ed25519 public key (hex) from the Discord Developer Portal →
        // General Information → "Public Key". Used by
        // DiscordInteractionVerifier to verify the X-Signature-Ed25519 header
        // Discord signs every interaction with, over (timestamp + raw body).
        // Rotatable in the portal — read from env, never hardcode. If unset or
        // invalid the verifier fails CLOSED: every request 401s. There is no
        // bypass path — Discord revokes the interactions URL on repeated bad
        // probes, so verification must run on every request.
        'bot_public_key' => env('DISCORD_BOT_PUBLIC_KEY'),
        // The bot's application id (snowflake) from the Developer Portal →
        // General Information → "Application ID". Needed (S03/T03) to build
        // the @original / follow-up PATCH URLs for deferred responses. Stored
        // here alongside the public key so the Interactions config sits in
        // one block.
        'bot_application_id' => env('DISCORD_BOT_APPLICATION_ID'),
        // Discord REST base URL. Pinned to the stable v10 API.
        'api_base_url' => env('DISCORD_API_BASE_URL', 'https://discord.com/api/v10'),
        // ── Bot install credentials (T06) ──────────────────────────────
        // Distinct from the login OAuth client above: the bot is a separate
        // Discord application (D118 — the bot application shared with the
        // Interactions endpoint). The landlord authorizes the bot into their
        // guild via the OAuth2 add-app flow (bot + applications.commands
        // scopes), which round-trips to the bot callback below.
        // Defaulting the bot client id/secret to the login client lets a
        // single-application setup work out of the box; production sets
        // DISCORD_BOT_CLIENT_ID / DISCORD_BOT_CLIENT_SECRET when the bot is
        // a separate application.
        'bot_client_id' => env('DISCORD_BOT_CLIENT_ID', env('DISCORD_CLIENT_ID')),
        'bot_client_secret' => env('DISCORD_BOT_CLIENT_SECRET', env('DISCORD_CLIENT_SECRET')),
        'bot_redirect_uri' => env('DISCORD_BOT_REDIRECT_URI', config('app.url').'/discord/install/callback'),
        // Onboarding message posted to the games channel on a fresh install
        // (T06). Null disables the message.
        'install_onboarding_message' => env('DISCORD_INSTALL_ONBOARDING_MESSAGE', null),
        // Master switch for the DiscordPublisher chokepoint observer wiring
        // (T05). When false, GameObserver does NOT dispatch PublishGameToDiscord,
        // so the existing test suite (QUEUE_CONNECTION=sync) is unaffected by
        // Discord card-posting. The publisher itself is inert until a guild is
        // mapped + an organizer opts in (T06/T07), but this flag lets ops keep
        // the dispatch path fully off until M057 ships. Enable per-test via
        // config(['services.discord.publishing_enabled' => true]).
        'publishing_enabled' => env('DISCORD_PUBLISHING_ENABLED', false),
        // Discord OAuth scopes asserted by OAuthController::redirect().
        //   - identify + email: login + attribution (no Discord approval cycle).
        //   - guilds: added by M057/D119 to power organizer auto-discovery —
        //     lets roundup read the user's guild membership so roundup-enabled
        //     servers they are present in surface in the GM workspace with a
        //     per-guild opt-in prompt. This reverses the "no guilds in Y1"
        //     portion of M056/D-1.
        // The config block stays the application-owned assertion of the scope
        // set (not a Socialite default); widening must be a conscious decision
        // here so the requested surface is always auditable.
        'scope' => ['identify', 'email', 'guilds'],
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
