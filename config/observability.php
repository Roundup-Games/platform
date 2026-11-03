<?php

/**
 * Log-derived alerting configuration (observability 2026-11-03).
 *
 * The backbone: AlertableErrorTap records every stack-channel log record at
 * WARNING or above into alertable_errors; observability:scan (every 5 min)
 * pages the operator about critical-pattern matches; observability:digest
 * (daily 06:30) sends the consolidated unresolved summary. These are
 * internal operator surfaces — no locale handling applies.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Alertable Error Tap
    |--------------------------------------------------------------------------
    |
    | Master switch for App\Logging\AlertableErrorTap (wired into the default
    | stack channel). The test suite disables it via phpunit.xml so unrelated
    | feature tests do not write rows for every warning they emit; tap tests
    | opt back in per test. Everything is fail-safe: with the tap off, logging
    | behaves exactly as before.
    |
    */

    'tap_enabled' => env('OBSERVABILITY_TAP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Critical Patterns (pages, not just digests)
    |--------------------------------------------------------------------------
    |
    | observability:scan pages the operator (email + optional Discord) about
    | unnotified alertable_errors rows whose message matches any of these
    | patterns — case-insensitive regex (see CriticalPatternMatcher; an entry
    | that is not valid regex degrades to a literal substring match).
    |
    | Curated against the platform's known canary messages: sweep failures
    | (badges/discovery/waitlist), the schedule health gate's degradation
    | signal, and Paddle's manual-reconciliation warnings. Add a pattern when
    | a new error log means "an operator must look at this".
    |
    */

    'critical_patterns' => [
        'badges.sweep.',
        'discovery.sweep.',
        'waitlist.sweep.',
        'schedule.health.',
        'paddle webhook.*manual reconciliation',
        'needs manual reconciliation',
        // Deliberately absent: 'js.runtime_error' (client-side noise, added
        // in parallel work) — it lands in alertable_errors like every other
        // error-level log and surfaces in the daily digest only; it must
        // never page.
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator Recipients
    |--------------------------------------------------------------------------
    |
    | Recipients for both observability mails (the scan's page and the daily
    | digest) — the same ops inbox the DataAudit/LocationsDriftSweep
    | emailOutputOnFailure mails go to. Internal ops surface: plain EN.
    |
    */

    'digest_recipients' => [(string) config('mail.from.address')],

    /*
    |--------------------------------------------------------------------------
    | Discord Page (optional second channel)
    |--------------------------------------------------------------------------
    |
    | An incoming Discord webhook URL (the URL itself carries its auth —
    | DiscordWebhookClient's bot-token REST API is a different surface). When
    | set AND page_discord is true, observability:scan also posts a compact
    | embed there. Email pages regardless; Discord is an opt-in extra pipe
    | (posted via DiscordAlertWebhook: one attempt, 3s timeout, catch-all).
    |
    */

    'discord_webhook_url' => env('OBSERVABILITY_DISCORD_WEBHOOK_URL'),

    'page_discord' => (bool) env('OBSERVABILITY_PAGE_DISCORD', false),

];
