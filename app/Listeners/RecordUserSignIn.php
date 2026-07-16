<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\PostHogAnalytics;
use App\Services\PostHogClient;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

/**
 * Captures the login/return-visit signal — the missing half of retention.
 *
 * Fires on every authentication: credential login, OAuth, signup-context login,
 * and remember-me session restoration. This is the session-boundary marker that
 * makes retention cohorts measurable (signup → onboarding → engagement are
 * already captured; *return frequency* is the existential health metric for a
 * community platform).
 *
 * Two independent concerns, deliberately separated by consent:
 *
 *   1. FIRST-PARTY STATE (always): stamp `users.last_login_at` via saveQuietly.
 *      This is operational data (like updated_at), not analytics — it drives
 *      dormant-account detection, admin "last seen", and inactive cleanup. It
 *      is written regardless of consent and never leaves this database.
 *
 *   2. ANALYTICS (consent-gated): forward `user.signed_in` to PostHog via
 *      PostHogAnalytics, which checks the cookie or the persisted
 *      analytics_consent column (the column is authoritative at login time).
 *
 * Pseudonymization: only the opaque user ID reaches PostHog, consistent with
 * the rest of the analytics surface. Never throws — a listener failure must
 * never block authentication.
 *
 * `is_first_session` is derived authoritatively from whether `last_login_at`
 * was null before this stamp — no heuristics, no edge-case misclassification.
 */
class RecordUserSignIn
{
    public function __construct(
        private readonly PostHogAnalytics $analytics,
        private readonly PostHogClient $posthog,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;
        if (! $user instanceof User) {
            return;
        }

        // ── 1. First-party state: authoritative session boundary ─────────
        // Read the prior value BEFORE stamping — null means this is the first
        // authentication ever (true first session, not just signup).
        $isFirstSession = $user->last_login_at === null;

        try {
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('user.last_login_stamp_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            // Don't return — the analytics event is still useful even if the
            // stamp failed; is_first_session may be imperfect but the signal
            // (a sign-in happened) is correct.
        }

        // ── 2. Analytics: consent-gated forward to PostHog ──────────────
        $this->analytics->capture(
            $user,
            'user.signed_in',
            [
                'is_first_session' => $isFirstSession,
                'remember' => (bool) $event->remember,
                'guard' => $event->guard,
            ],
        );

        // Person properties for retention cohorts. $set_once first_login_at is
        // set only on the first session ever; last_login_at tracks recency.
        // login_count is NOT duplicated here — it's derivable from the count of
        // user.signed_in events, and duplicating it would risk drift.
        if ($this->posthog->isEnabled()) {
            try {
                $this->posthog->identify([
                    'distinctId' => (string) $user->id,
                    'properties' => [
                        '$set' => ['last_login_at' => now()->toIso8601String()],
                        '$set_once' => ['first_login_at' => now()->toIso8601String()],
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::channel('daily')->warning('posthog.signin_identify_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
