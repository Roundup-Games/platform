<?php

use App\Models\User;
use App\Support\CalendarSyncGate;

/**
 * Pins the D300 posture at the wiring layer (M083/S01/T03):
 * CalendarSyncGate::supportsConnect(?User) is the one-argument gate S02's
 * connect route and S03's sync entry call — it reads the deployment's
 * config/calendar_sync.php and delegates to allowsConnect(). With the
 * shipped defaults (enabled=false, mode=test_user, empty allowlist)
 * NOBODY may connect: the unverified-scope risk stays structurally locked
 * out until an operator deliberately widens the gate.
 *
 * The allowsConnect decision matrix itself is owned by T01's
 * CalendarSyncConfigTest; this file pins only the config→gate translation
 * and runs alongside it as an independent regression line. The test env
 * sets no GOOGLE_CALENDAR_* vars, so an unoverridden container serves the
 * shipped defaults. No DB: users are in-memory attribute bags.
 */

/**
 * Arrange a full, well-formed calendar-sync config posture for one scenario.
 *
 * @param  array<int, string>  $allowlist
 */
function wireCalendarSyncConfig(bool $enabled, string $mode, array $allowlist): void
{
    config([
        'calendar_sync.enabled' => $enabled,
        'calendar_sync.verification_mode' => $mode,
        'calendar_sync.test_user_allowlist' => $allowlist,
    ]);
}

describe('shipped-default posture (D300)', function () {
    test('refuses everyone while the deployment config is untouched', function () {
        // No config overrides: the container serves the shipped defaults —
        // enabled=false, mode=test_user, empty allowlist — so neither an
        // allowlist-style email, nor any other user, nor a null caller may
        // connect until an operator deliberately widens the gate.
        expect(CalendarSyncGate::supportsConnect(new User(['email' => 'dev@roundup.games'])))->toBeFalse()
            ->and(CalendarSyncGate::supportsConnect(new User(['email' => 'someone@else.com'])))->toBeFalse()
            ->and(CalendarSyncGate::supportsConnect(null))->toBeFalse();
    });
});

describe('supportsConnect config wiring', function () {
    test('refuses everyone while disabled, even in verified mode with an allowlisted email', function () {
        wireCalendarSyncConfig(false, 'verified', ['dev@roundup.games']);

        expect(CalendarSyncGate::supportsConnect(new User(['email' => 'dev@roundup.games'])))->toBeFalse();
    });

    test('allows an allowlisted email in test_user mode', function () {
        wireCalendarSyncConfig(true, 'test_user', ['dev@roundup.games', 'qa@roundup.games']);

        expect(CalendarSyncGate::supportsConnect(new User(['email' => 'qa@roundup.games'])))->toBeTrue();
    });

    test('refuses a non-allowlisted email in test_user mode', function () {
        wireCalendarSyncConfig(true, 'test_user', ['dev@roundup.games']);

        expect(CalendarSyncGate::supportsConnect(new User(['email' => 'someone@else.com'])))->toBeFalse();
    });

    test('refuses everyone in test_user mode with an empty allowlist', function () {
        wireCalendarSyncConfig(true, 'test_user', []);

        expect(CalendarSyncGate::supportsConnect(new User(['email' => 'dev@roundup.games'])))->toBeFalse();
    });

    test('allows every identified user in verified mode regardless of allowlist', function () {
        wireCalendarSyncConfig(true, 'verified', []);

        expect(CalendarSyncGate::supportsConnect(new User(['email' => 'never-seen@anywhere.com'])))->toBeTrue();
    });

    test('refuses a null user even in the most permissive mode', function () {
        wireCalendarSyncConfig(true, 'verified', []);

        expect(CalendarSyncGate::supportsConnect(null))->toBeFalse();
    });

    test('fails closed when the verification mode config is missing', function () {
        config([
            'calendar_sync.enabled' => true,
            'calendar_sync.verification_mode' => null,
        ]);

        expect(CalendarSyncGate::supportsConnect(new User(['email' => 'dev@roundup.games'])))->toBeFalse();
    });

    test('treats a malformed allowlist as empty and refuses in test_user mode', function () {
        config([
            'calendar_sync.enabled' => true,
            'calendar_sync.verification_mode' => 'test_user',
            'calendar_sync.test_user_allowlist' => 'dev@roundup.games',
        ]);

        expect(CalendarSyncGate::supportsConnect(new User(['email' => 'dev@roundup.games'])))->toBeFalse();
    });
});
