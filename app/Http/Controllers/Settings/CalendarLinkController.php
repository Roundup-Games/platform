<?php

namespace App\Http\Controllers\Settings;

use App\Jobs\ReconcileGoogleCalendarConnection;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\Calendar\Google\GoogleCalendarAuth;
use App\Support\CalendarSyncGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HTTP controller for the calendar-link OAuth round trip (M083/S02-T02).
 *
 * Two auth-gated routes — both top-level (outside the {locale} prefix,
 * like the login OAuth routes, because Google redirects back to a fixed
 * callback URL):
 *   - GET settings/calendar/google/connect  → Google's consent screen
 *   - GET settings/calendar/google/callback → exchange + upsert, back to Settings
 *
 * CalendarSyncGate::supportsConnect runs FIRST on both routes: while the
 * gate is closed (D300) nobody ever leaves for Google — the refusal
 * reason is flashed as the calendar_sync_refusal marker (Settings'
 * calendar section renders the honest copy from lang/calendar_sync.php,
 * error_connect_refused_disabled / error_connect_refused_not_allowlisted)
 * and the user is returned to Settings.
 *
 * Thin by design: all Google I/O lives in GoogleCalendarAuth. The
 * controller owns only the HTTP concerns (auth gate, refusal flash,
 * locale-aware redirect, failure surfacing).
 */
class CalendarLinkController
{
    /**
     * Redirect the user to Google's calendar-consent screen.
     */
    public function connect(Request $request, GoogleCalendarAuth $auth): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $reason = $this->refusalReason($user);

        if ($reason !== null) {
            session()->flash('calendar_sync_refusal', $reason);

            return redirect()->to($this->settingsUrl());
        }

        return $auth->connectRedirect($user);
    }

    /**
     * Handle the Google calendar-consent callback: exchange the
     * authorization code, upsert the connection row, return to Settings.
     */
    public function callback(Request $request, GoogleCalendarAuth $auth): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        // The gate FIRST here too: a callback completing while the
        // deployment locked the surface must not mint a connection.
        $reason = $this->refusalReason($user);

        if ($reason !== null) {
            session()->flash('calendar_sync_refusal', $reason);

            return redirect()->to($this->settingsUrl());
        }

        // Google redirects back with ?error=… when the user cancels the
        // consent screen — not a failure, just no consent granted: back
        // to Settings without a flash (the user chose to stop).
        if ($request->has('error')) {
            Log::info('calendar_sync.connect_cancelled', [
                'user_id' => $user->id,
                'error' => $request->string('error')->toString(),
            ]);

            return redirect()->to($this->settingsUrl());
        }

        $state = $request->input('state');

        try {
            $connection = $auth->handleCallback(
                $user,
                $request->string('code')->toString(),
                is_string($state) ? $state : null,
            );
        } catch (Throwable $e) {
            report($e);
            Log::warning('calendar_sync.connect_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            session()->flash('calendar_sync_link_failed', true);

            return redirect()->to($this->settingsUrl());
        }

        $this->queueInitialBackfill($user, $connection);

        return redirect()->to($this->settingsUrl());
    }

    /**
     * Initial backfill (M083/S03-T04): the connection row exists — queue
     * the SAME per-connection reconcile pass the periodic sweep runs.
     * No separate backfill path: an initial backfill is a reconcile pass
     * whose drift is "everything missing".
     *
     * Guarded like CalendarSyncDispatcher::guard absorbs dispatch-path
     * failures: the calendar mirror is best-effort and must never break
     * the connect flow. A lost dispatch self-heals on the next sweep —
     * worst case the user's calendar fills within one reconcile cadence.
     */
    private function queueInitialBackfill(User $user, GoogleCalendarConnection $connection): void
    {
        try {
            ReconcileGoogleCalendarConnection::dispatch($connection->id);
        } catch (Throwable $e) {
            Log::error('calendar_sync.backfill_dispatch_failed', [
                'user_id' => $user->id,
                'connection_id' => $connection->id,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
        }
    }

    /**
     * The honest-refusal reason for a blocked connect attempt, or null
     * when the gate is open. CalendarSyncGate owns the decision; this only
     * disambiguates the copy key (lang/calendar_sync.php
     * error_connect_refused_disabled / error_connect_refused_not_allowlisted)
     * from the deployment config.
     */
    private function refusalReason(User $user): ?string
    {
        if (CalendarSyncGate::supportsConnect($user)) {
            return null;
        }

        return (bool) config('calendar_sync.enabled') ? 'not_allowlisted' : 'disabled';
    }

    /**
     * Locale-prefixed Settings URL. These routes run outside the {locale}
     * group (fixed callback URL), so route('settings.show') cannot resolve
     * a locale — mirror OAuthController's session-locale fallback.
     */
    private function settingsUrl(): string
    {
        $locale = session('locale', config('app.fallback_locale'));

        return '/'.(is_string($locale) ? $locale : 'en').'/settings';
    }
}
