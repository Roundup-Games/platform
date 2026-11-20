<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Calendar Managed Sync
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the managed-sync surface (M083): the enable
    | gate, the OAuth verification posture, the exact scope set, the sync
    | cadence S03 consumes, and the test-user allowlist that bounds the
    | test_user rollout. S02's connect route and S03's sync entry read these
    | through App\Support\CalendarSyncGate — never bypass it.
    */

    // Master switch. When false NOBODY may connect a calendar — the
    // unverified-scope risk stays structurally locked out until an operator
    // deliberately enables it (D300: GO for build under test-user mode;
    // production enablement is owner-attested only after Google's
    // sensitive-scope review approves calendar.events).
    'enabled' => env('GOOGLE_CALENDAR_SYNC_ENABLED', false),

    // OAuth verification posture (D300):
    // - 'test_user': only allowlisted emails (test_user_allowlist below)
    //   may connect — the build/verify mode while Google's sensitive-scope
    //   review runs externally.
    // - 'verified': the scope passed review; every authenticated user may
    //   connect. Switching modes is an operator decision, never code.
    'verification_mode' => env('GOOGLE_CALENDAR_VERIFICATION_MODE', 'test_user'),

    // The exact OAuth scope set D287 named plus the identity pair the
    // connect flow structurally needs: calendar.events grants read/write
    // on events ONLY — NOT the broader calendar.readonly or calendar
    // scopes. openid + email exist because Google's userinfo endpoint
    // (Socialite's userFromToken) refuses an access token with no
    // identity scope — without them every real connect fails; email is
    // the narrowest grant that reads the account's address for the
    // Settings status row (non-sensitive, D300 posture unchanged).
    // Widening this set requires an explicit decision recorded
    // here, mirroring the services.google scope discipline from M056.
    'scopes' => [
        'https://www.googleapis.com/auth/calendar.events',
        'openid',
        'email',
    ],

    // ── Sync cadence (consumed by S03's reconcile loop) ─────────────────
    'sync' => [
        // Minutes between full reconcile passes over connected users'
        // calendars. 15 keeps confirmed games, campaign sessions, and event
        // registrations visible in Google within a quarter hour without
        // hammering the Calendar API quota.
        'reconcile_every_minutes' => env('GOOGLE_CALENDAR_RECONCILE_EVERY_MINUTES', 15),
    ],

    // Emails permitted to connect while verification_mode=test_user.
    // Comma-separated env (GOOGLE_CALENDAR_TEST_USER_ALLOWLIST=a@x.com,b@x.com),
    // empty by default — with an empty allowlist NOBODY connects, which is
    // the correct shipped-default posture for an unverified sensitive scope.
    'test_user_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_CALENDAR_TEST_USER_ALLOWLIST', ''))))),

];
