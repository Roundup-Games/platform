<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Checks cookie consent status for analytics gating.
 *
 * Reads the `cookie_consent` cookie set by the front-end consent banner
 * (spatie/laravel-cookie-consent customized for category-based consent).
 *
 * Cookie format (JSON): {"necessary":true,"analytics":true,"marketing":false}
 *
 * This cookie is excluded from Laravel's encryption by the cookie-consent
 * service provider, so it can be read directly via Request::cookie().
 *
 * Used by:
 * - PostHogIdentifyUsers middleware (server-side identify/capture)
 * - PostHogEventBridge (server-side event forwarding)
 * - EnrichPostHogProfile job (async profile enrichment)
 */
class PostHogConsentChecker
{
    /**
     * Cookie name set by the cookie consent banner.
     */
    private const COOKIE_NAME = 'cookie_consent';

    /**
     * Check whether the user has granted analytics consent.
     *
     * Returns true only if the cookie exists and the analytics category
     * is explicitly set to true. Returns false for:
     * - Missing cookie (no consent decision yet)
     * - Malformed cookie value
     * - Analytics explicitly false
     * - CLI/queue contexts where no request is available
     */
    public function hasAnalyticsConsent(?Request $request = null): bool
    {
        // In CLI contexts (artisan, queue workers), there is no HTTP request
        // and therefore no cookie consent to check. Return false to prevent
        // accidental server-side tracking without user consent.
        if ($request === null && app()->runningInConsole()) {
            return false;
        }

        $request ??= request();

        $cookieValue = $request->cookie(self::COOKIE_NAME);

        if (! $cookieValue) {
            return false;
        }

        // Handle both raw JSON and already-decoded arrays
        if (is_string($cookieValue)) {
            $consent = json_decode($cookieValue, true);
        } else {
            $consent = (array) $cookieValue;
        }

        if (! is_array($consent)) {
            return false;
        }

        return (bool) ($consent['analytics'] ?? false);
    }

    /**
     * Get the full consent state from the cookie.
     *
     * Returns null if the cookie is missing or malformed.
     *
     * @return array<string, bool>|null
     */
    public function getConsentState(?Request $request = null): ?array
    {
        $request ??= request();

        $cookieValue = $request->cookie(self::COOKIE_NAME);

        if (! $cookieValue) {
            return null;
        }

        if (is_string($cookieValue)) {
            $consent = json_decode($cookieValue, true);
        } else {
            $consent = (array) $cookieValue;
        }

        if (! is_array($consent)) {
            return null;
        }

        return $consent;
    }
}
