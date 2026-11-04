<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allow CDN edge caching for public pages served to anonymous visitors.
 *
 * Works in tandem with the Cloudflare cache rules managed by
 * `php artisan cloudflare:cache-rules`.
 *
 * Strategy:
 * - For anonymous GET requests to public routes, emit cacheable headers
 *   that Cloudflare can honor (s-maxage=300 = 5 min edge TTL).
 * - For authenticated users, Laravel's session middleware already sets
 *   Cache-Control: no-cache, private — we don't override those.
 * - Cloudflare rules double-check via cookie presence (belt & suspenders).
 *
 * This middleware must run AFTER StartSession so auth()->check() works,
 * but it should be in the "web" group, not global.
 */
class CachePublicPages
{
    /** @var string[] Routes that should NEVER be edge-cached (auth-dependent content) */
    protected const ALWAYS_PRIVATE_ROUTES = [
        'dashboard',
        'profile.show',
        'profile.edit',
        'people',
        'notifications.*',
        'billing.*',
        'membership',
        'onboarding.*',
        'gm.workspace',
        // Any page that embeds a per-session CSRF token or renders an
        // auth form. Origin-level guarantee: even if an edge rule goes
        // rogue, these pages must never carry public cache headers — a
        // cached login page poisons every visitor with one dead CSRF
        // token and no Set-Cookie (2026-11-04 incident).
        'login',
        'register',
        'logout',
        'password.*',
        'verification.*',
        // Public event-registration form — POSTs a CSRF-guarded action.
        'events.register',
        // Operator surfaces (separate guard; their session cookie is not
        // the main app's, so edge cookie-bypass rules cannot cover them).
        'escalated.*',
        'filament.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only relax cache headers for anonymous GET requests on successful responses
        // Skip API routes (they manage their own cache headers) and non-GET methods
        if (
            $request->isMethod('GET')
            && ! $request->routeIs('api.*')
            && ! $request->is('api/*')
            && ! auth()->check()
            && $response->getStatusCode() >= 200
            && $response->getStatusCode() < 300
            && ! $this->isAlwaysPrivateRoute($request)
        ) {
            $response->headers->set(
                'Cache-Control',
                'public, max-age=60, s-maxage=300, stale-while-revalidate=60'
            );
        }

        return $response;
    }

    /**
     * Check if the current route should never be cached, even for anonymous users.
     * These routes may contain session-dependent content or CSRF tokens.
     */
    protected function isAlwaysPrivateRoute(Request $request): bool
    {
        $routeName = $request->route()?->getName() ?? '';

        foreach (static::ALWAYS_PRIVATE_ROUTES as $pattern) {
            if (str_ends_with($pattern, '.*')) {
                // Wildcard match: "notifications.*" matches "notifications.index"
                $prefix = rtrim($pattern, '.*');
                if (str_starts_with($routeName, $prefix.'.') || $routeName === $prefix) {
                    return true;
                }
            } elseif ($routeName === $pattern) {
                return true;
            }
        }

        return false;
    }
}
