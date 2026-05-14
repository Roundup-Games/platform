<?php

namespace App\Http\Middleware;

use App\Services\PostHogClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PostHogIdentifyUsers
{
    public function __construct(
        private readonly PostHogClient $posthog,
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
    private function shareClientIdentifyData($user): void
    {
        // Only share the user ID for client-side posthog.identify().
        // PII (name, email) is set exclusively via server-side identify()
        // to avoid exposing user data in the DOM where XSS could exfiltrate it.
        view()->share('posthogIdentifyData', [
            'id' => (string) $user->getAuthIdentifier(),
        ]);
    }

    /**
     * Server-side identify call for user property enrichment and
     * server-side event attribution.
     *
     * Error handling is centralized in PostHogClient::identify().
     * If the SDK throws, PostHogClient catches it and logs a warning.
     */
    private function identifyServerSide($user): void
    {
        if (! $this->posthog->isEnabled()) {
            return;
        }

        $distinctId = (string) $user->getAuthIdentifier();

        $this->posthog->identify([
            'distinctId' => $distinctId,
            'properties' => [
                '$set' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->preferred_language ?? app()->getLocale(),
                ],
                '$set_once' => [
                    'signup_date' => $user->created_at?->toDateString(),
                ],
            ],
        ]);

        Log::channel('daily')->debug('posthog.user.identified', [
            'user_id' => $distinctId,
        ]);
    }
}
