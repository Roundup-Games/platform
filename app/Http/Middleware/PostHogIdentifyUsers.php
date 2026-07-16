<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PostHogIdentifyUsers
{
    public function __construct(
        private readonly PostHogClient $posthog,
        private readonly PostHogConsentChecker $consentChecker,
    ) {}

    /**
     * Identify authenticated users in PostHog (both client-side and server-side).
     *
     * For authenticated users:
     * - Shares identify data with views for client-side posthog.identify() call (every request)
     * - Calls server-side Posthog::identify() with user properties ($set/$set_once) once per session
     *
     * Server-side identify is throttled via a session flag to avoid redundant SDK calls
     * on every GET request — user properties (name, email, locale) rarely change and are
     * kept fresh by the EnrichPostHogProfile job after meaningful events.
     *
     * Skips: API routes, Filament admin, Livewire update requests, and non-GET requests.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Respect Do Not Track — server-side identify sends PII (name, email).
        // The JS client already checks respect_dnt:true, but server-side
        // has no equivalent config. This ensures consistency.
        if ($request->headers->get('DNT') === '1') {
            return $next($request);
        }

        // Gate all server-side PostHog calls behind analytics consent.
        // If the user has not accepted analytics cookies, skip identify
        // and capture entirely — consistent with the JS-side gating.
        $hasAnalyticsConsent = $this->consentChecker->hasAnalyticsConsent($request);

        if (! $hasAnalyticsConsent) {
            return $next($request);
        }

        // Persist the consent decision on the user model so that
        // UserAnonymizationService can check it without request/cookie
        // context (e.g., from artisan or queued jobs).
        if ($user && ! $user->analytics_consent) {
            $user->forceFill(['analytics_consent' => true])->saveQuietly();
        }

        if ($user && $this->shouldIdentify($request)) {
            $this->shareClientIdentifyData($user);

            // Server-side identify: once per session to avoid redundant SDK overhead.
            // Client-side identify runs every request for correct session attribution.
            if (! session('posthog_server_identified')) {
                $this->identifyServerSide($user);
                session(['posthog_server_identified' => true]);
            }
        }

        return $next($request);
    }

    /**
     * Handle post-response: if consent is absent but the persisted column
     * is still true, correct the column. This keeps the DB in sync when
     * a user revokes consent via the cookie banner.
     */
    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if (! $user || ! $user->analytics_consent) {
            return;
        }

        // The cookie consent checker doesn't depend on response state,
        // so we can safely re-check here.
        if (! $this->consentChecker->hasAnalyticsConsent($request)) {
            $user->forceFill(['analytics_consent' => false])->saveQuietly();

            // Clear server-side identify flag so next consent-grant
            // re-identifies properly.
            session()->forget('posthog_server_identified');
        }
    }

    /**
     * Only identify on real page visits — skip API, admin, Livewire internals.
     */
    private function shouldIdentify(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $request->is('api/*')
            && ! $request->is('admin/*')
            && ! $request->is('livewire/*')
            && ! $request->header('X-Livewire');
    }

    /**
     * Share user data as a view variable so the layout can inject it
     * for client-side posthog.identify().
     */
    private function shareClientIdentifyData(User $user): void
    {
        // Only share the user ID for client-side posthog.identify().
        // PII (name, email) is set exclusively via server-side identify()
        // to avoid exposing user data in the DOM where XSS could exfiltrate it.
        view()->share('posthogIdentifyData', [
            'id' => ($id = $user->getAuthIdentifier()) instanceof \BackedEnum ? (string) $id->value : to_string_id($id),
        ]);
    }

    /**
     * Server-side identify call for user property enrichment and
     * server-side event attribution.
     *
     * PSEUDONYMIZATION: PostHog receives only the opaque user ID as the
     * distinctId. Name and email are deliberately NOT sent — the distinctId
     * already links the session, and PostHog cannot re-identify the person
     * without this application's database. This keeps analytics genuinely
     * pseudonymized and matches our public privacy posture.
     *
     * Only cheap, non-PII properties are set here (they run inline on the
     * first GET of a session). Computed/aggregated properties (game-system
     * cluster, modality tendency, lifetime counts) are set asynchronously by
     * EnrichPostHogProfile to avoid blocking the request.
     *
     * Error handling is centralized in PostHogClient::identify().
     * If the SDK throws, PostHogClient catches it and logs a warning.
     */
    private function identifyServerSide(User $user): void
    {
        if (! $this->posthog->isEnabled()) {
            return;
        }

        $authId = $user->getAuthIdentifier();
        $distinctId = to_string_id($authId);

        $this->posthog->identify([
            'distinctId' => $distinctId,
            'properties' => [
                '$set' => [
                    'locale' => $this->coarseLocale($user),
                    'account_age_days' => $user->created_at?->diffInDays(now()) ?? 0,
                    'has_completed_onboarding' => (bool) $user->profile_complete,
                    // Coarse country only (never raw geohash or coordinates).
                    'country' => $this->coarseCountry($user),
                ],
                '$set_once' => [
                    'signup_date' => $user->created_at?->toDateString(),
                    'signup_cohort_week' => $user->created_at?->format('Y-W'),
                ],
            ],
        ]);

        Log::channel('daily')->debug('posthog.user.identified', [
            'user_id' => $distinctId,
        ]);
    }

    /**
     * Resolve a coarse, non-PII locale string from the user's preference.
     *
     * preferred_language is cast to a ContentLanguage enum on the model, so we
     * send its scalar value (e.g. "de") rather than the enum instance.
     */
    private function coarseLocale(User $user): string
    {
        $preferred = $user->preferred_language;

        return $preferred instanceof \BackedEnum ? (string) $preferred->value : app()->getLocale();
    }

    /**
     * Derive a coarse, non-PII country code from the user's linked location.
     *
     * Returns null when no location or no country is set. Intentionally coarse —
     * country-level segmentation is useful; city/coordinates are not sent to
     * the analytics provider.
     */
    private function coarseCountry(User $user): ?string
    {
        $country = $user->linkedLocation?->country;

        return $country !== null && $country !== '' ? strtoupper($country) : null;
    }
}
