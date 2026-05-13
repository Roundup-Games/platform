<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PostHog API Key
    |--------------------------------------------------------------------------
    |
    | Your PostHog project API key. Find this in your PostHog project settings.
    | When empty/null, all PostHog calls are silently skipped.
    |
    */
    'api_key' => env('POSTHOG_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | PostHog Host
    |--------------------------------------------------------------------------
    |
    | The PostHog instance URL. Default is the EU cloud instance.
    | Change to 'https://us.i.posthog.com' for US cloud, or your self-hosted URL.
    |
    */
    'host' => env('POSTHOG_HOST', 'https://eu.i.posthog.com'),

    /*
    |--------------------------------------------------------------------------
    | PostHog Enabled
    |--------------------------------------------------------------------------
    |
    | Global kill switch for all PostHog tracking. When false, no events
    | are captured server-side and the JS snippet is not rendered.
    |
    */
    'enabled' => env('POSTHOG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Session Replay
    |--------------------------------------------------------------------------
    |
    | Session replay with GDPR privacy masking. Enabled by default with
    | 50% sampling rate. Set POSTHOG_SESSION_REPLAY_ENABLED=false to disable.
    | Adjust POSTHOG_REPLAY_SAMPLE_RATE (0.0–1.0) to control recording frequency.
    | Text inputs, images, and sensitive fields (password, email, etc.) are masked.
    |
    */
    'session_replay' => [
        'enabled' => env('POSTHOG_SESSION_REPLAY_ENABLED', true),
        'sample_rate' => env('POSTHOG_REPLAY_SAMPLE_RATE', 0.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Surveys
    |--------------------------------------------------------------------------
    |
    | In-app surveys rendered by the PostHog JS SDK. Surveys are created in the
    | PostHog dashboard (Surveys → New Survey) and automatically rendered as
    | popovers by posthog-js. Set POSTHOG_SURVEYS_ENABLED=false to suppress
    | all survey rendering.
    |
    | Survey targeting (user conditions, display frequency) is configured
    | entirely in the PostHog UI. The JS SDK respects those conditions
    | automatically — no code changes needed to add new surveys.
    |
    */
    'surveys' => [
        'enabled' => env('POSTHOG_SURVEYS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Feature flags evaluated server-side via PostHogFeatureFlag service.
    | Set 'enabled' to true once the first flag has been created in PostHog.
    |
    */
    'feature_flags' => [
        'enabled' => env('POSTHOG_FEATURE_FLAGS_ENABLED', true),
    ],

];
