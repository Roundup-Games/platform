<?php

namespace App\Support;

use App\Models\User;

/**
 * Machine-enforced go/no-go for the Google Calendar managed sync (M083).
 *
 * S02's connect route and S03's sync entry call this gate before any OAuth
 * or Calendar-API work happens — the unverified-scope risk (calendar.events
 * is a sensitive scope until Google approves it) stays structurally locked
 * out until an operator deliberately widens config/calendar_sync.php (D300:
 * GO for build under test-user mode; production enablement owner-attested
 * after approval).
 *
 * Pure/static by design: no DB, no events, no writes. Two layers:
 * allowsConnect() takes every input as an explicit parameter so the whole
 * decision matrix is unit-pinnable (CalendarSyncConfigTest);
 * supportsConnect() is the thin config-reading wrapper S02's connect
 * route and S03's sync entry call with one argument
 * (CalendarSyncGateWiringTest pins the config translation).
 */
final class CalendarSyncGate
{
    /**
     * May a user with the given email connect their calendar?
     *
     * Decision matrix:
     * - enabled=false refuses everyone, regardless of mode or allowlist.
     * - mode 'verified' allows everyone (the scope passed review).
     * - mode 'test_user' allows only allowlisted emails (case-insensitive —
     *   config allowlists are hand-typed, user emails may be mixed-case).
     * - Any unknown mode fails CLOSED (refuses) — a typo in the env var
     *   must never widen the gate.
     * - A null email is refused in every mode: "allows everyone" means
     *   every identified user, not anonymous callers.
     *
     * @param  array<int, string>  $allowlist
     */
    public static function allowsConnect(bool $enabled, string $mode, array $allowlist, ?string $userEmail): bool
    {
        if (! $enabled) {
            return false;
        }

        if ($userEmail === null) {
            return false;
        }

        if ($mode === 'verified') {
            return true;
        }

        if ($mode === 'test_user') {
            return in_array(mb_strtolower($userEmail), array_map(
                fn (string $email) => mb_strtolower($email),
                $allowlist
            ), true);
        }

        return false;
    }

    /**
     * May this user connect their calendar under the deployment's current
     * config/calendar_sync.php posture?
     *
     * The one-argument surface S02's connect route and S03's sync entry
     * call: reads the enabled gate, verification mode, and test-user
     * allowlist from config and delegates to allowsConnect(). Static and
     * side-effect-free — config reads only, no DB, no events.
     *
     * Fails CLOSED on malformed config: a non-array allowlist is treated
     * as empty (test_user mode then refuses everyone), and a mode that is
     * not exactly 'verified' or 'test_user' refuses everyone — a broken
     * override can never widen the gate (D300).
     */
    public static function supportsConnect(?User $user): bool
    {
        $rawMode = config('calendar_sync.verification_mode');
        $rawAllowlist = config('calendar_sync.test_user_allowlist');

        $allowlist = [];

        if (is_array($rawAllowlist)) {
            foreach ($rawAllowlist as $allowlistedEmail) {
                if (is_string($allowlistedEmail)) {
                    $allowlist[] = $allowlistedEmail;
                }
            }
        }

        return self::allowsConnect(
            (bool) config('calendar_sync.enabled'),
            is_string($rawMode) ? $rawMode : '',
            $allowlist,
            $user?->email,
        );
    }
}
