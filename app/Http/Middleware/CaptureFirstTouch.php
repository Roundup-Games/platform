<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capture acquisition first-touch for anonymous visitors.
 *
 * Records the first public content page a guest lands on and the external
 * referer that brought them there — once per session (first-write-wins). The
 * signup flows (email registration + OAuth callback) read these to attribute
 * the acquisition funnel to the real entry point, not the auth endpoint
 * (/register, /auth/{provider}/callback) where the signup actually POSTs.
 *
 * Only runs for guests (an authenticated user is already past acquisition), and
 * only for real page GETs — API calls, Livewire updates, asset/internal
 * requests, and the auth endpoints themselves are skipped so the signal stays a
 * content-page landing rather than a transient auth hop.
 *
 * The cost is a single conditional session read per guest request; the write
 * happens at most once per session.
 */
class CaptureFirstTouch
{
    /**
     * Session keys read by the signup flows.
     */
    public const REFERER_KEY = 'first_touch_referer';

    public const PATH_KEY = 'first_touch_path';

    public const CAPTURED_KEY = 'first_touch_captured';

    public function handle(Request $request, Closure $next): Response
    {
        // Only guests are acquiring; an authenticated user has already signed up.
        // Guard hasSession() so the middleware is a no-op when no session is
        // available (stateless requests, or if it runs before the session
        // middleware in the pipeline).
        if ($request->hasSession() && $request->user() === null && $this->shouldCapture($request)) {
            // First-write-wins: once captured, never overwrite — the first
            // landing is the authoritative acquisition source for this session.
            if (! $request->session()->has(self::CAPTURED_KEY)) {
                $request->session()->put(self::CAPTURED_KEY, true);
                $request->session()->put(self::PATH_KEY, '/'.$request->path());
                $request->session()->put(self::REFERER_KEY, $request->headers->get('referer'));
            }
        }

        return $next($request);
    }

    /**
     * Only capture real public page GETs, not auth endpoints or internal traffic.
     */
    private function shouldCapture(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        // Skip API, Livewire round-trips, and auth/login/oauth endpoints —
        // these are not content landings and would pollute the signal.
        if ($request->is('api/*')
            || $request->header('X-Livewire')
            || $request->is('login', 'logout', 'register', 'password/*', 'auth/*', 'verification/*')
            || $request->is('*/login', '*/logout', '*/register', '*/auth/*')) {
            return false;
        }

        return true;
    }
}
