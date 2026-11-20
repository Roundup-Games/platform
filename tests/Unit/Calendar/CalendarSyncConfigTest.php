<?php

use App\Support\CalendarSyncGate;

/**
 * Pins the managed-sync config surface (M083/S01/T01): the shipped default
 * posture (enabled=false, mode=test_user, empty allowlist — NOBODY may
 * connect until an operator deliberately widens the gate, D300) and the
 * CalendarSyncGate decision matrix S02's connect route and S03's sync entry
 * will enforce.
 *
 * Pure unit: no container, no DB, no factories. Config defaults are pinned
 * by requiring the shipped file directly — the test env sets no
 * GOOGLE_CALENDAR_* vars, so every env() call falls through to its default,
 * which is exactly the shipped-default contract under test.
 */

/**
 * @return array<string, mixed>
 */
function calendarSyncConfig(): array
{
    /** @var array<string, mixed> $config */
    $config = require __DIR__.'/../../../config/calendar_sync.php';

    return $config;
}

describe('config/calendar_sync.php shipped defaults', function () {
    test('is disabled by default', function () {
        expect(calendarSyncConfig()['enabled'])->toBeFalse();
    });

    test('defaults to test_user verification mode', function () {
        expect(calendarSyncConfig()['verification_mode'])->toBe('test_user');
    });

    test('requests the calendar.events scope D287 named plus the identity pair the connect flow needs', function () {
        $config = calendarSyncConfig();

        expect($config['scopes'])->toBe([
            'https://www.googleapis.com/auth/calendar.events',
            'openid',
            'email',
        ]);
    });

    test('carries an identity scope for the userinfo call — a calendar-only token cannot resolve the account email', function () {
        // Google's userinfo endpoint (Socialite's userFromToken) refuses an
        // access token with no identity scope: without openid/email riding
        // the grant, EVERY real connect fails at the userinfo step with an
        // insufficient-scopes error (401/403). This pin keeps that lesson.
        $scopes = calendarSyncConfig()['scopes'];

        expect($scopes)->toContain('openid')
            ->and($scopes)->toContain('email');
    });

    test('does not request the broader calendar or calendar.readonly scopes', function () {
        $scopes = calendarSyncConfig()['scopes'];

        expect($scopes)->not->toContain('https://www.googleapis.com/auth/calendar')
            ->and($scopes)->not->toContain('https://www.googleapis.com/auth/calendar.readonly');
    });

    test('ships an empty test-user allowlist', function () {
        $config = calendarSyncConfig();

        expect($config['test_user_allowlist'])->toBe([]);
    });

    test('defaults the S03 reconcile cadence to 15 minutes', function () {
        $config = calendarSyncConfig();

        expect($config['sync'])->toBe(['reconcile_every_minutes' => 15]);
    });
});

describe('CalendarSyncGate::allowsConnect matrix', function () {
    test('refuses everyone while disabled, even in verified mode with an allowlist', function () {
        expect(CalendarSyncGate::allowsConnect(false, 'verified', ['dev@roundup.games'], 'dev@roundup.games'))->toBeFalse()
            ->and(CalendarSyncGate::allowsConnect(false, 'test_user', ['dev@roundup.games'], 'dev@roundup.games'))->toBeFalse();
    });

    test('refuses non-allowlisted emails in test_user mode', function () {
        expect(CalendarSyncGate::allowsConnect(true, 'test_user', ['dev@roundup.games'], 'someone@else.com'))->toBeFalse();
    });

    test('refuses everyone in test_user mode with an empty allowlist', function () {
        // The shipped default posture: enabled=false also refuses, but even
        // if someone flips only the master switch, an empty allowlist keeps
        // the gate shut — both pins matter independently.
        expect(CalendarSyncGate::allowsConnect(true, 'test_user', [], 'anyone@roundup.games'))->toBeFalse();
    });

    test('refuses a null email in test_user mode', function () {
        expect(CalendarSyncGate::allowsConnect(true, 'test_user', ['dev@roundup.games'], null))->toBeFalse();
    });

    test('allows allowlisted emails in test_user mode', function () {
        expect(CalendarSyncGate::allowsConnect(true, 'test_user', ['dev@roundup.games', 'qa@roundup.games'], 'dev@roundup.games'))->toBeTrue();
    });

    test('matches allowlist entries case-insensitively', function () {
        expect(CalendarSyncGate::allowsConnect(true, 'test_user', ['Dev@Roundup.Games'], 'dev@roundup.games'))->toBeTrue();
    });

    test('allows everyone in verified mode regardless of allowlist', function () {
        expect(CalendarSyncGate::allowsConnect(true, 'verified', [], 'never-seen@anywhere.com'))->toBeTrue();
    });

    test('refuses a null email even in verified mode', function () {
        // "Allows everyone" means every identified user — anonymous callers
        // never pass. supportsConnect(?User) in T03 relies on this.
        expect(CalendarSyncGate::allowsConnect(true, 'verified', [], null))->toBeFalse();
    });

    test('fails closed on an unknown verification mode', function () {
        // A typo in GOOGLE_CALENDAR_VERIFICATION_MODE must never widen the gate.
        expect(CalendarSyncGate::allowsConnect(true, 'verifyed', ['dev@roundup.games'], 'dev@roundup.games'))->toBeFalse()
            ->and(CalendarSyncGate::allowsConnect(true, '', [], 'anyone@example.com'))->toBeFalse();
    });
});
