<?php

namespace App\Services;

use App\Models\User;

/**
 * Single source for device-timezone capture writes (R089).
 *
 * Three surfaces feed this — the login form, the OAuth callback, and the
 * authenticated beacon endpoint — and they all share the same contract:
 *
 *  - The candidate is canonicalized via canonical_timezone() (alias-
 *    tolerant, ctor-based; invalid values are silently dropped).
 *  - A user who set their timezone MANUALLY on the profile is never
 *    overwritten (users.timezone_manually_set wins over every capture).
 *  - No write when the value is unchanged or invalid — silent degradation
 *    everywhere, never a write per request.
 */
class TimezoneCaptureService
{
    /**
     * Persist $candidate as the user's device timezone when it is valid,
     * different from the stored value, and not manually pinned.
     *
     * @return bool True when a write happened.
     */
    public function capture(User $user, mixed $candidate): bool
    {
        $timezone = canonical_timezone($candidate);

        if ($timezone === null) {
            return false;
        }

        // Strict-mode guard: a partially hydrated user (e.g. an actingAs
        // fixture that never selected the flag column) throws on attribute
        // access. Fetch ONLY the flag column — refresh() would discard any
        // unsaved changes the caller holds on the model.
        if (! array_key_exists('timezone_manually_set', $user->getAttributes())) {
            $user->timezone_manually_set = (bool) User::query()
                ->whereKey($user->getKey())
                ->value('timezone_manually_set');
        }

        if ($user->timezone_manually_set || $user->timezone === $timezone) {
            return false;
        }

        $user->forceFill(['timezone' => $timezone])->saveQuietly();

        return true;
    }
}
